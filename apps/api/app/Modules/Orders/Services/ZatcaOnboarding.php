<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\ZatcaDevice;
use App\Modules\Orders\Zatca\Certificate;
use App\Modules\Orders\Zatca\FatooraClient;
use App\Modules\Orders\Zatca\ZatcaCsr;
use App\Modules\Orders\Zatca\ZatcaDocument;
use App\Modules\Orders\Zatca\ZatcaSigner;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Registering an EGS unit with ZATCA (onboarding), run by zatca:onboard:
 *
 * 1. a new unit with its own key and CSR, saved before anything is sent;
 * 2. a compliance CSID for the CSR, with the OTP from the Fatoora portal;
 * 3. ZATCA's compliance checks: a sample simplified invoice, credit note
 *    and debit note, signed with the compliance CSID, must pass;
 * 4. the production CSID; the unit becomes the active one for its
 *    environment, and the one before is retired.
 *
 * Each step is saved as it completes, so an interrupted run resumes from
 * the step it stopped at (an OTP is single use and short lived).
 */
final class ZatcaOnboarding
{
    public function __construct(
        private readonly ZatcaSigner $signer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The unit still being onboarded in this environment, or a new one.
     */
    public function device(string $environment): ZatcaDevice
    {
        $open = ZatcaDevice::query()->where('environment', $environment)->where('status', ZatcaDevice::ONBOARDING)->latest('id')->first();

        if ($open !== null) {
            return $open;
        }

        $seller = Invoices::seller();
        $csr = ZatcaCsr::make($environment, (array) config('groupbuy.zatca.egs'), $seller);

        return ZatcaDevice::create([
            'environment' => $environment,
            'status' => ZatcaDevice::ONBOARDING,
            'egs_serial' => $csr['serial'],
            'common_name' => $csr['common_name'],
            'private_key' => $csr['private_key'],
            'csr' => $csr['csr'],
        ]);
    }

    /**
     * Drop the units still being onboarded in this environment, so the next
     * device() starts over with a new CSR. Their keys are never used again.
     *
     * @return int how many were dropped
     */
    public function abandon(string $environment): int
    {
        return DB::transaction(function () use ($environment): int {
            $open = ZatcaDevice::query()->where('environment', $environment)->where('status', ZatcaDevice::ONBOARDING)->lockForUpdate()->get();

            foreach ($open as $device) {
                $device->forceFill(['status' => ZatcaDevice::RETIRED, 'retired_at' => CarbonImmutable::now()])->save();
                $this->audit->record(Actor::system(), 'zatca.device.abandon', $device, ['status' => ZatcaDevice::ONBOARDING], ['status' => ZatcaDevice::RETIRED]);
            }

            return $open->count();
        });
    }

    public function needsOtp(ZatcaDevice $device): bool
    {
        return $device->compliance_certificate === null;
    }

    /**
     * Step 2.
     */
    public function requestComplianceCsid(ZatcaDevice $device, string $otp): void
    {
        $csid = FatooraClient::forEnvironment($device->environment)->complianceCsid($device->csr, $otp);
        $certificate = Certificate::fromToken($csid['token']);

        $device->forceFill([
            'compliance_request_id' => $csid['request_id'],
            'compliance_certificate' => $certificate->base64,
            'compliance_secret' => $csid['secret'],
        ])->save();
    }

    /**
     * Step 3: each sample is sent once; all must be reported.
     *
     * @return list<array{type: string, status: int, warnings: list<string>, errors: list<string>}>
     */
    public function runComplianceChecks(ZatcaDevice $device): array
    {
        $client = FatooraClient::forEnvironment($device->environment);
        $certificate = $device->complianceCertificate();
        $previous = ZatcaSigner::FIRST_PREVIOUS_HASH;
        $results = [];

        foreach ([ZatcaDocument::INVOICE, ZatcaDocument::CREDIT_NOTE, ZatcaDocument::DEBIT_NOTE] as $i => $type) {
            $document = self::sample($type, $i + 1, $previous);
            $signed = $this->signer->sign($document, $device->private_key, $certificate);
            $answer = $client->complianceCheck($certificate, (string) $device->compliance_secret, $document->uuid, $signed);
            $previous = $signed->hash;
            $results[] = ['type' => $type, 'status' => $answer->status, 'warnings' => $answer->warnings(), 'errors' => $answer->refused() ? ($answer->errors() ?: ['Refused without a reason.']) : []];
        }

        return $results;
    }

    /**
     * @param  list<array{type: string, status: int, warnings: list<string>, errors: list<string>}>  $results
     */
    public static function passed(array $results): bool
    {
        return $results !== [] && array_filter($results, fn (array $r) => $r['errors'] !== [] || ! in_array($r['status'], [200, 202], true)) === [];
    }

    /**
     * Step 4.
     */
    public function activate(ZatcaDevice $device): void
    {
        if ($device->compliance_request_id === null || $device->compliance_request_id === '') {
            throw new LogicException('The compliance CSID came without a request ID; onboard again.');
        }

        $csid = FatooraClient::forEnvironment($device->environment)->productionCsid($device->complianceCertificate(), (string) $device->compliance_secret, $device->compliance_request_id);
        $certificate = Certificate::fromToken($csid['token']);

        DB::transaction(function () use ($device, $certificate, $csid): void {
            $now = CarbonImmutable::now();
            $retired = ZatcaDevice::query()->where('environment', $device->environment)->where('status', ZatcaDevice::ACTIVE)->lockForUpdate()->get();

            foreach ($retired as $old) {
                $old->forceFill(['status' => ZatcaDevice::RETIRED, 'retired_at' => $now])->save();
                $this->audit->record(Actor::system(), 'zatca.device.retire', $old, ['status' => ZatcaDevice::ACTIVE], ['status' => ZatcaDevice::RETIRED]);
            }

            $device->forceFill([
                'status' => ZatcaDevice::ACTIVE,
                'certificate' => $certificate->base64,
                'secret' => $csid['secret'],
                'certificate_expires_at' => $certificate->validTo,
                'activated_at' => $now,
            ])->save();
            $this->audit->record(Actor::system(), 'zatca.device.activate', $device, ['status' => ZatcaDevice::ONBOARDING], [
                'status' => ZatcaDevice::ACTIVE,
                'environment' => $device->environment,
                'egs_serial' => $device->egs_serial,
                'certificate_expires_at' => $certificate->validTo->toIso8601String(),
            ]);
        });
    }

    /**
     * A sample document for the compliance checks: one item of 115.00
     * with VAT, from today's seller settings.
     */
    private static function sample(string $type, int $icv, string $previousHash): ZatcaDocument
    {
        $note = $type !== ZatcaDocument::INVOICE;
        // Prices include VAT and are split as on real invoices. 115.04 is a
        // total whose VAT (15.01) is a halala off its net x 15% (15.00), so
        // ZATCA's checks show here, not on a customer's invoice, whether it
        // accepts that split (BR-KSA-50).
        $lines = array_map(function (int $total): array {
            $split = Money::sar($total)->splitVatInclusive((int) config('groupbuy.vat_rate_bp'));

            return ['description_ar' => 'منتج تجريبي', 'description_en' => 'Sample item', 'qty' => 1, 'net_halalas' => $split['net']->minor, 'vat_halalas' => $split['vat']->minor, 'total_halalas' => $total];
        }, [11500, 11504]);

        return new ZatcaDocument(
            number: sprintf('CMPL-%s-%d', CarbonImmutable::now()->format('Ymd'), $icv),
            uuid: (string) Str::uuid(),
            typeCode: $type,
            issuedAt: CarbonImmutable::now(),
            seller: Invoices::seller(),
            lines: $lines,
            netHalalas: array_sum(array_column($lines, 'net_halalas')),
            vatHalalas: array_sum(array_column($lines, 'vat_halalas')),
            totalHalalas: array_sum(array_column($lines, 'total_halalas')),
            vatRateBp: (int) config('groupbuy.vat_rate_bp'),
            icv: $icv,
            previousHash: $previousHash,
            billingReference: $note ? sprintf('CMPL-%s-1', CarbonImmutable::now()->format('Ymd')) : null,
            reason: $note ? ($type === ZatcaDocument::CREDIT_NOTE ? 'Refund of a returned item' : 'Price correction') : null,
        );
    }
}
