<?php

namespace App\Modules\Finance\Services;

use App\Exceptions\ApiException;
use App\Modules\Finance\Enums\MismatchType;
use App\Modules\Finance\Enums\ReconciliationStatus;
use App\Modules\Finance\Models\ReconciliationItem;
use App\Modules\Finance\Models\ReconciliationRun;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\ProviderPayment;
use App\Modules\Payments\Gateway\ProviderPaymentNotFound;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily reconciliation against Moyasar (spec, Payments: reconciliation).
 * Every payment that changed on the day, and every one still open (a hold
 * not yet captured or voided, a call whose outcome is unknown), is fetched
 * from Moyasar and compared with our row. Mismatches are listed for
 * finance; resolving one records a note, and any fix that moves money goes
 * through an approval request.
 *
 * Finding payments Moyasar has and we do not (missing_locally) needs
 * Moyasar's payment list, which comes with the real integration.
 */
final class Reconciliation
{
    /** Ours, and the Moyasar status that matches it. */
    private const EXPECTED = [
        'authorized' => ['authorized'],
        'captured' => ['captured'],
        'partially_refunded' => ['refunded'],
        'refunded' => ['refunded', 'voided'],
        'voided' => ['voided'],
        'expired' => ['voided'],
        'failed' => ['failed'],
        // Being voided after a capture failed.
        'capture_failed' => ['authorized', 'voided'],
    ];

    /** Ours, where Moyasar still holding the money means our release did not land. */
    private const RELEASED = [PaymentStatus::Voided, PaymentStatus::Expired, PaymentStatus::CaptureFailed];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Check the payments of one day (Riyadh time); yesterday by default.
     */
    public function run(?CarbonImmutable $day = null): ReconciliationRun
    {
        $zone = (string) config('groupbuy.display_timezone');
        $day = ($day ?? CarbonImmutable::now($zone)->subDay())->setTimezone($zone)->startOfDay();
        $run = ReconciliationRun::create([
            'run_date' => $day->toDateString(),
            'started_at' => CarbonImmutable::now(),
        ]);

        $from = $day->utc();
        $to = $day->addDay()->utc();
        $checked = 0;
        $mismatches = 0;

        try {
            $this->candidates($from, $to)->chunkById(200, function ($payments) use ($run, &$checked, &$mismatches): void {
                foreach ($payments as $payment) {
                    $checked++;
                    $mismatches += $this->check($run, $payment) ? 1 : 0;
                }
            });
        } catch (GatewayException $e) {
            Log::critical('payments.reconciliation.failed', ['run' => $run->uuid, 'error' => $e->getMessage()]);
            $run->forceFill([
                'status' => ReconciliationStatus::Failed,
                'payments_checked' => $checked,
                'mismatch_count' => $mismatches,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => CarbonImmutable::now(),
            ])->save();

            return $run;
        }

        $run->forceFill([
            'status' => $mismatches === 0 ? ReconciliationStatus::Matched : ReconciliationStatus::Mismatches,
            'payments_checked' => $checked,
            'mismatch_count' => $mismatches,
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        if ($mismatches > 0) {
            Log::critical('payments.reconciliation.mismatches', ['run' => $run->uuid, 'run_date' => $run->run_date->toDateString(), 'count' => $mismatches]);
        }

        return $run;
    }

    /**
     * Close a mismatch with a note.
     *
     * @throws ApiException 409 already_resolved
     */
    public function resolve(string $uuid, string $note, AdminUser $by): ReconciliationItem
    {
        return DB::transaction(function () use ($uuid, $note, $by): ReconciliationItem {
            $item = ReconciliationItem::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ($item->resolved_at !== null) {
                throw new ApiException(409, 'already_resolved');
            }

            $item->forceFill([
                'resolved_by_admin_id' => $by->id,
                'resolved_at' => CarbonImmutable::now(),
                'resolution_note' => $note,
            ])->save();

            $this->audit->record(Actor::admin($by->id), 'reconciliation.resolve', $item, null, [
                'mismatch_type' => $item->mismatch_type->value,
                'note' => $note,
            ]);

            return $item;
        });
    }

    /**
     * @return Builder<PaymentAuthorization>
     */
    private function candidates(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return PaymentAuthorization::query()
            ->whereNotNull('provider_payment_id')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('updated_at', '>=', $from)->where('updated_at', '<', $to))
                ->orWhereIn('status', [PaymentStatus::Authorized, PaymentStatus::CaptureFailed])
                ->orWhereIn('id', PaymentTransaction::query()->select('payment_authorization_id')->where('status', TransactionStatus::Pending)));
    }

    /**
     * Returns whether a mismatch was recorded.
     *
     * @throws GatewayException when Moyasar cannot be reached
     */
    private function check(ReconciliationRun $run, PaymentAuthorization $payment): bool
    {
        $ours = [
            'status' => $payment->status->value,
            'authorized_halalas' => $payment->authorized_amount_halalas,
            'captured_halalas' => $payment->captured_amount_halalas,
            'refunded_halalas' => $payment->refunded_amount_halalas,
        ];

        try {
            $remote = $this->gateway->fetch((string) $payment->provider_payment_id);
        } catch (ProviderPaymentNotFound) {
            $this->item($run, $payment, MismatchType::MissingAtProvider, $ours, null);

            return true;
        }

        $theirs = [
            'status' => $remote->status,
            'amount_halalas' => $remote->amountHalalas,
            'captured_halalas' => $remote->capturedHalalas,
            'refunded_halalas' => $remote->refundedHalalas,
        ];
        $type = $this->mismatch($payment, $remote);

        if ($type === null) {
            return false;
        }

        $this->item($run, $payment, $type, $ours, $theirs);

        return true;
    }

    private function mismatch(PaymentAuthorization $payment, ProviderPayment $remote): ?MismatchType
    {
        if ($remote->status === 'authorized' && $payment->status === PaymentStatus::Failed) {
            return MismatchType::OrphanHold;
        }

        if ($remote->status === 'authorized' && in_array($payment->status, self::RELEASED, true)) {
            // A failed capture is voided right away; give the void an hour.
            $since = CarbonImmutable::now()->subHour();

            return $payment->status !== PaymentStatus::CaptureFailed || $payment->updated_at->lessThan($since) ? MismatchType::StuckVoid : null;
        }

        $expected = self::EXPECTED[$payment->status->value] ?? null;

        if ($expected === null) {
            // Not yet authorized here: the webhook or the customer's sync decides.
            return null;
        }

        if (! in_array($remote->status, $expected, true)) {
            return MismatchType::Status;
        }

        $capturedMatters = in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true);

        if (($capturedMatters && $remote->capturedHalalas !== $payment->captured_amount_halalas)
            || ($remote->status === 'refunded' && $remote->refundedHalalas !== $payment->refunded_amount_halalas)) {
            return MismatchType::Amount;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>|null  $theirs
     */
    private function item(ReconciliationRun $run, PaymentAuthorization $payment, MismatchType $type, array $ours, ?array $theirs): void
    {
        ReconciliationItem::create([
            'reconciliation_run_id' => $run->id,
            'payment_authorization_id' => $payment->id,
            'provider_payment_id' => $payment->provider_payment_id,
            'mismatch_type' => $type,
            'our_value' => $ours,
            'provider_value' => $theirs,
        ]);
    }
}
