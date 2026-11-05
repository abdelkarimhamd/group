<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\ZatcaStatus;
use App\Modules\Orders\Jobs\RenderInvoicePdf;
use App\Modules\Orders\Jobs\SubmitInvoiceToZatca;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Models\ZatcaDevice;
use App\Modules\Orders\Models\ZatcaInvoiceStamp;
use App\Modules\Orders\Zatca\FatooraAnswer;
use App\Modules\Orders\Zatca\FatooraClient;
use App\Modules\Orders\Zatca\FatooraUnavailable;
use App\Modules\Orders\Zatca\SignedDocument;
use App\Modules\Orders\Zatca\ZatcaDocument;
use App\Modules\Orders\Zatca\ZatcaSigner;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * ZATCA e-invoicing phase 2 (integration) for our simplified invoices and
 * credit notes. While `groupbuy.zatca.enabled` is on:
 *
 * 1. issuing an invoice opens its stamp in the same transaction, and its
 *    PDF waits for the signature (the QR code needs it);
 * 2. after the commit, SubmitInvoiceToZatca signs it with the active EGS
 *    unit, under that unit's row lock: the next counter (ICV) and the
 *    previous document's hash (PIH) chain it to the one before. The
 *    invoice becomes `submitted`; its XML is stored and its PDF rendered;
 * 3. it is then reported to Fatoora outside any transaction, logged
 *    before it is sent: `accepted` (with or without warnings, or already
 *    reported), `rejected` when ZATCA finds errors (alerted, not sent
 *    again on its own), or retried while Fatoora cannot be reached.
 *
 * `zatca:report` (every ten minutes) queues again what a lost or
 * exhausted job left, and alerts on anything still unreported close to
 * ZATCA's 24-hour limit.
 */
final class ZatcaReporting
{
    public function __construct(
        private readonly ZatcaSigner $signer,
        private readonly StatusHistory $history,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('groupbuy.zatca.enabled');
    }

    /**
     * Why invoices cannot be signed and reported, or null when they can
     * (or phase 2 is off). In production nothing is captured meanwhile,
     * as for the seller of record (Captures).
     */
    public static function problem(): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        if (($address = self::sellerProblem()) !== null) {
            return $address;
        }

        if (! ZatcaDevice::query()->active()->exists()) {
            return 'ZATCA phase 2 is on but no EGS unit is onboarded for '.config('groupbuy.zatca.environment').': run php artisan zatca:onboard --otp=<OTP>.';
        }

        return null;
    }

    /**
     * Why the seller cannot go on an e-invoice: phase 2 needs the CR
     * number and the national address in parts.
     */
    public static function sellerProblem(): ?string
    {
        $seller = (array) config('groupbuy.seller');
        $ok = preg_match('/^\d{10}$/', (string) ($seller['cr_number'] ?? '')) === 1
            && preg_match('/^\d{4}$/', (string) ($seller['building_number'] ?? '')) === 1
            && preg_match('/^\d{5}$/', (string) ($seller['postal_code'] ?? '')) === 1
            && trim((string) ($seller['street'] ?? '')) !== ''
            && trim((string) ($seller['district'] ?? '')) !== ''
            && trim((string) ($seller['city'] ?? '')) !== '';

        return $ok ? null : 'Set the seller\'s CR number and national address for e-invoices: GROUPBUY_SELLER_CR_NUMBER (10 digits), GROUPBUY_SELLER_STREET, GROUPBUY_SELLER_BUILDING_NUMBER (4 digits), GROUPBUY_SELLER_DISTRICT, GROUPBUY_SELLER_CITY and GROUPBUY_SELLER_POSTAL_CODE (5 digits).';
    }

    /**
     * Call in the transaction that issues the invoice. Returns whether it
     * waits for its e-invoice (then its PDF is rendered once signed).
     */
    public function open(Invoice $invoice): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $stamp = ZatcaInvoiceStamp::create(['invoice_id' => $invoice->id]);
        SubmitInvoiceToZatca::dispatch($stamp->id)->afterCommit();

        return true;
    }

    /**
     * Sign the invoice with the active EGS unit unless it is signed
     * already. Its own transaction, holding the unit's row lock, so
     * documents are chained one at a time.
     *
     * @throws RuntimeException when no EGS unit is active
     */
    public function sign(int $stampId): ZatcaInvoiceStamp
    {
        return DB::transaction(function () use ($stampId): ZatcaInvoiceStamp {
            $stamp = ZatcaInvoiceStamp::query()->whereKey($stampId)->lockForUpdate()->firstOrFail();

            if ($stamp->signed_at !== null) {
                return $stamp;
            }

            $device = ZatcaDevice::query()->active()->lockForUpdate()->first();

            if ($device === null) {
                throw new RuntimeException('No active ZATCA EGS unit for '.config('groupbuy.zatca.environment').'; run zatca:onboard.');
            }

            $invoice = Invoice::query()->whereKey($stamp->invoice_id)->lockForUpdate()->firstOrFail();
            $icv = $device->icv + 1;
            $signed = $this->signer->sign(ZatcaDocument::fromInvoice($invoice, $icv, $device->last_hash), $device->private_key, $device->productionCertificate());

            $stamp->forceFill([
                'zatca_device_id' => $device->id,
                'icv' => $icv,
                'previous_hash' => $device->last_hash,
                'invoice_hash' => $signed->hash,
                'signed_xml' => $signed->xml,
                'qr' => $signed->qr,
                'signed_at' => CarbonImmutable::now(),
            ])->save();
            $device->forceFill(['icv' => $icv, 'last_hash' => $signed->hash])->save();
            $this->move($invoice, ZatcaStatus::Submitted, 'zatca_signed', ['icv' => $icv, 'device' => $device->uuid]);
            Log::info('zatca.signed', ['invoice' => $invoice->invoice_number, 'icv' => $icv, 'device' => $device->uuid]);

            return $stamp;
        });
    }

    /**
     * After signing: store the XML beside the PDF and render the PDF with
     * the phase 2 QR code. Both are safe to repeat.
     */
    public function afterSigning(ZatcaInvoiceStamp $stamp): void
    {
        $invoice = Invoice::query()->findOrFail($stamp->invoice_id);

        if ($invoice->xml_s3_key === null) {
            $path = (string) preg_replace('/\.pdf$/', '.xml', InvoiceDocuments::pdfPath($invoice));

            if (! Storage::disk((string) config('groupbuy.media.documents_disk'))->put($path, (string) $stamp->signed_xml)) {
                throw new RuntimeException("Could not store the XML of {$invoice->invoice_number}.");
            }

            Invoice::query()->whereKey($invoice->id)->whereNull('xml_s3_key')->update(['xml_s3_key' => $path]);
        }

        if ($invoice->pdf_s3_key === null) {
            RenderInvoicePdf::dispatch($invoice->id);
        }
    }

    /**
     * Report a signed invoice to Fatoora, unless that is settled already.
     * Outside any transaction.
     *
     * @throws FatooraUnavailable while Fatoora cannot be reached; the job retries
     */
    public function report(int $stampId, bool $resend = false): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Never call ZATCA inside a database transaction.');
        }

        $stamp = ZatcaInvoiceStamp::query()->with(['invoice', 'device'])->findOrFail($stampId);
        $invoice = $stamp->invoice;

        if ($stamp->signed_at === null || $stamp->device === null) {
            throw new LogicException("Invoice {$invoice->invoice_number} is not signed yet.");
        }

        if ($stamp->reported_at !== null || ($invoice->zatca_status === ZatcaStatus::Rejected && ! $resend)) {
            return;
        }

        ZatcaInvoiceStamp::query()->whereKey($stamp->id)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_attempt_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
        Log::info('zatca.report.sending', ['invoice' => $invoice->invoice_number, 'uuid' => $invoice->zatca_uuid, 'icv' => $stamp->icv, 'attempt' => $stamp->attempts + 1]);

        try {
            $answer = FatooraClient::forEnvironment($stamp->device->environment)->report(
                $stamp->device->productionCertificate(),
                (string) $stamp->device->secret,
                $invoice->zatca_uuid,
                new SignedDocument((string) $stamp->signed_xml, (string) $stamp->invoice_hash, '', (string) $stamp->qr),
            );
        } catch (FatooraUnavailable $e) {
            ZatcaInvoiceStamp::query()->whereKey($stamp->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => CarbonImmutable::now()]);
            Log::warning('zatca.report.unavailable', ['invoice' => $invoice->invoice_number, 'error' => $e->getMessage()]);

            throw $e;
        }

        $this->record($stamp->id, $answer);
    }

    /**
     * Queue again what is still to sign or report, and say what is late.
     *
     * @return array{queued: int, overdue: int, rejected: int}
     */
    public function sweep(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;
        $open = fn () => ZatcaInvoiceStamp::query()
            ->whereNull('reported_at')
            ->whereHas('invoice', fn ($query) => $query->whereIn('zatca_status', [ZatcaStatus::Pending, ZatcaStatus::Submitted]));

        $open()
            ->where('created_at', '<=', $now->subMinutes((int) config('groupbuy.zatca.requeue_after_minutes')))
            ->select('id')
            ->chunkById(500, function ($stamps) use (&$queued): void {
                foreach ($stamps as $stamp) {
                    SubmitInvoiceToZatca::dispatch($stamp->id);
                    $queued++;
                }
            });

        $late = fn () => $open()->whereHas('invoice', fn ($query) => $query->where('issued_at', '<=', $now->subHours((int) config('groupbuy.zatca.report_alert_after_hours'))));
        $overdue = $late()->count();

        if ($overdue > 0) {
            Log::critical('zatca.report.overdue', ['count' => $overdue, 'invoices' => $late()->with('invoice:id,invoice_number')->limit(20)->get()->pluck('invoice.invoice_number')->all()]);
        }

        return [
            'queued' => $queued,
            'overdue' => $overdue,
            'rejected' => Invoice::query()->where('zatca_status', ZatcaStatus::Rejected)->count(),
        ];
    }

    private function record(int $stampId, FatooraAnswer $answer): void
    {
        DB::transaction(function () use ($stampId, $answer): void {
            $stamp = ZatcaInvoiceStamp::query()->whereKey($stampId)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->whereKey($stamp->invoice_id)->lockForUpdate()->firstOrFail();

            if ($stamp->reported_at !== null) {
                return;
            }

            $now = CarbonImmutable::now();
            $stamp->forceFill([
                'response_status' => $answer->status,
                'response' => $answer->body,
                'last_error' => $answer->refused() ? mb_substr(implode('; ', $answer->errors()) ?: 'Refused without a reason.', 0, 1000) : null,
                'reported_at' => $answer->accepted() ? $now : null,
            ])->save();

            if ($answer->accepted()) {
                $this->move($invoice, ZatcaStatus::Accepted, $answer->status === 202 ? 'zatca_reported_with_warnings' : 'zatca_reported', ['status' => $answer->status]);

                if ($answer->warnings() !== []) {
                    Log::warning('zatca.report.warnings', ['invoice' => $invoice->invoice_number, 'warnings' => $answer->warnings()]);
                }

                return;
            }

            $this->move($invoice, ZatcaStatus::Rejected, 'zatca_rejected', ['errors' => array_slice($answer->errors(), 0, 10)]);
            Log::critical('zatca.report.rejected', ['invoice' => $invoice->invoice_number, 'errors' => $answer->errors()]);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function move(Invoice $invoice, ZatcaStatus $to, string $reason, array $metadata = []): void
    {
        $from = $invoice->zatca_status;

        if ($from === $to) {
            return;
        }

        $invoice->zatca_status = $to;
        $invoice->save();
        $this->history->record($invoice, $from, $to, Actor::system(), $reason, ['field' => 'zatca_status'] + $metadata);
    }
}
