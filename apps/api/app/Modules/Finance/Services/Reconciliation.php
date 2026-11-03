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
use Throwable;

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
        // A declined payment, or an orphan hold we voided.
        'failed' => ['failed', 'voided'],
        // Being voided after a capture failed.
        'capture_failed' => ['authorized', 'voided'],
    ];

    /** Ours, where Moyasar still holding the money means our release did not land (also an authorized hold with a rejection reason). */
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
        $this->failAbandoned();
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

                // Progress, and a sign of life: only a run that stopped
                // moving counts as abandoned.
                $this->close($run, ['payments_checked' => $checked, 'mismatch_count' => $mismatches, 'updated_at' => CarbonImmutable::now()], final: false);
            });
        } catch (Throwable $e) {
            // Moyasar being unreachable is expected now and then; anything else is a bug.
            $expected = $e instanceof GatewayException;
            Log::critical('payments.reconciliation.failed', ['run' => $run->uuid, 'error' => $e->getMessage(), 'exception' => $e::class]);
            $this->close($run, [
                'status' => ReconciliationStatus::Failed,
                'payments_checked' => $checked,
                'mismatch_count' => $mismatches,
                'error' => $expected ? mb_substr($e->getMessage(), 0, 500) : 'The run stopped on an unexpected error; see the logs.',
                'finished_at' => CarbonImmutable::now(),
            ]);

            if (! $expected) {
                throw $e;
            }

            return $run;
        }

        $this->close($run, [
            'status' => $mismatches === 0 ? ReconciliationStatus::Matched : ReconciliationStatus::Mismatches,
            'payments_checked' => $checked,
            'mismatch_count' => $mismatches,
            'finished_at' => CarbonImmutable::now(),
        ]);

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
     * Write the run's progress or outcome, only while it is still running:
     * a run the next one already marked abandoned keeps that verdict.
     *
     * @param  array<string, mixed>  $values
     */
    private function close(ReconciliationRun $run, array $values, bool $final = true): void
    {
        $values['updated_at'] ??= CarbonImmutable::now();
        $written = ReconciliationRun::query()
            ->whereKey($run->id)
            ->where('status', ReconciliationStatus::Running)
            ->update(array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, $values));

        if ($written === 0 && $final) {
            Log::warning('payments.reconciliation.closed_meanwhile', ['run' => $run->uuid]);
        }

        $run->refresh();
    }

    /**
     * Runs still marked running that have not moved for a long time were cut
     * off (a deploy, a crash): they are marked failed so they do not show as
     * in progress for ever. A run updates itself after every batch.
     */
    private function failAbandoned(): void
    {
        $cutoff = CarbonImmutable::now()->subMinutes((int) config('groupbuy.finance.reconciliation_timeout_minutes'));

        ReconciliationRun::query()
            ->where('status', ReconciliationStatus::Running)
            ->where('updated_at', '<', $cutoff)
            ->get()
            ->each(function (ReconciliationRun $run): void {
                $failed = ReconciliationRun::query()
                    ->whereKey($run->id)
                    ->where('status', ReconciliationStatus::Running)
                    ->update([
                        'status' => ReconciliationStatus::Failed->value,
                        'error' => 'The run stopped before it finished.',
                        'finished_at' => CarbonImmutable::now(),
                        'updated_at' => CarbonImmutable::now(),
                    ]);

                if ($failed > 0) {
                    Log::critical('payments.reconciliation.abandoned', ['run' => $run->uuid]);
                }
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

        // A hold released here (a cancelled join, a duplicate) stays authorized
        // with a rejection reason until Moyasar confirms the void. Like a
        // failed capture, its void is sent right away: give it time.
        $releasing = $payment->status === PaymentStatus::Authorized && $payment->rejection_reason !== null;
        $voidSettling = ($releasing || $payment->status === PaymentStatus::CaptureFailed)
            && ! $payment->updated_at->lessThan(CarbonImmutable::now()->subMinutes((int) config('groupbuy.finance.void_grace_minutes')));

        if ($remote->status === 'authorized' && ($releasing || in_array($payment->status, self::RELEASED, true))) {
            return $voidSettling ? null : MismatchType::StuckVoid;
        }

        if ($releasing && $remote->status === 'voided' && $voidSettling) {
            // The void landed; our row follows when its job hears back.
            return null;
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
