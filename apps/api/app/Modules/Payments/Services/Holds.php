<?php

namespace App\Modules\Payments\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RejectionReason;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Enums\TransactionType;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Jobs\VoidPayment;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Releasing card holds. The public entry point for other modules: they call
 * release() inside their own transaction, and the void itself runs after
 * commit in a queued job, because the provider is never called inside a
 * database transaction.
 *
 * A void follows the spec's Payment holds steps: write the attempt row with
 * key void:{payment uuid}, commit, call the provider, record the result
 * under the payment row lock. An unknown outcome leaves the row pending;
 * the retry asks the provider what happened before calling again.
 */
class Holds
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly StatusHistory $history,
    ) {}

    /**
     * Stop a payment from becoming or staying a live hold. Call inside the
     * transaction that ends the participation. A hold that is already
     * authorized is voided after commit; one still waiting for the customer
     * is marked failed, and is voided on receipt if it authorizes later.
     */
    public function release(PaymentAuthorization $payment, RejectionReason $reason, Actor $actor): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isEmpty()) {
            throw new LogicException('Release a hold inside the transaction that ends its participation.');
        }

        $payment = PaymentAuthorization::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        if ($payment->status === PaymentStatus::PendingAuthorization) {
            $payment->status = PaymentStatus::Failed;
            $payment->rejection_reason = $reason;
            $payment->save();
            $this->history->record($payment, PaymentStatus::PendingAuthorization, PaymentStatus::Failed, $actor, $reason->value);

            return;
        }

        if ($payment->status === PaymentStatus::Authorized) {
            if ($payment->rejection_reason === null) {
                $payment->rejection_reason = $reason;
                $payment->save();
            }

            VoidPayment::dispatch($payment->id, $actor->type->value, $actor->id)->afterCommit();
        }
    }

    /**
     * A hold that could not be captured at close (declined, or past its
     * capture cutoff): authorized -> capture_failed, voided after commit.
     * Call inside the transaction that records it, with the campaign and
     * participation locked first (lock order).
     */
    public function captureFailed(PaymentAuthorization $payment, RejectionReason $reason, Actor $actor): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isEmpty()) {
            throw new LogicException('Record a failed capture inside a transaction.');
        }

        $payment = PaymentAuthorization::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        if ($payment->status !== PaymentStatus::Authorized) {
            return;
        }

        $payment->status = PaymentStatus::CaptureFailed;
        $payment->rejection_reason = $reason;
        $payment->save();
        $this->history->record($payment, PaymentStatus::Authorized, PaymentStatus::CaptureFailed, $actor, $reason->value);

        VoidPayment::dispatch($payment->id, $actor->type->value, $actor->id)->afterCommit();
    }

    /**
     * Holds past their expiry: authorized and capture_failed payments become
     * `expired` (spec, Expiry clock). One being voided gets one last void
     * attempt first. Returns how many expired.
     */
    public function expireDue(?CarbonImmutable $now = null, int $limit = 500): int
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Never call the payment provider inside a database transaction.');
        }

        $now ??= CarbonImmutable::now();
        $due = PaymentAuthorization::query()
            ->whereIn('status', [PaymentStatus::Authorized, PaymentStatus::CaptureFailed])
            ->where('expires_at', '<=', $now)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $expired = 0;

        foreach ($due as $payment) {
            if ($payment->status === PaymentStatus::CaptureFailed || $payment->rejection_reason !== null) {
                try {
                    $this->void($payment, Actor::system());
                } catch (\Throwable $e) {
                    Log::warning('payments.void.last_attempt_failed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
                }
            }

            $expired += DB::transaction(function () use ($payment, $now): int {
                // Lock order: campaign, participation, payment.
                $campaignId = CampaignParticipation::query()->whereKey($payment->participation_id)->value('campaign_id');
                Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
                $participation = CampaignParticipation::query()->whereKey($payment->participation_id)->lockForUpdate()->firstOrFail();
                $locked = PaymentAuthorization::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if (! in_array($locked->status, [PaymentStatus::Authorized, PaymentStatus::CaptureFailed], true) || $locked->expires_at->greaterThan($now)) {
                    return 0;
                }

                $from = $locked->status;
                $locked->status = PaymentStatus::Expired;
                $locked->save();
                $this->history->record($locked, $from, PaymentStatus::Expired, Actor::system(), 'hold_expired');

                // A live hold should never run out: the extension rule and the
                // capture cutoff keep it capturable through close.
                if ($from === PaymentStatus::Authorized && $locked->rejection_reason === null) {
                    Log::critical('payments.hold.expired_uncaptured', ['payment' => $locked->uuid, 'participation' => $participation->uuid, 'participation_status' => $participation->status->value]);
                }

                return 1;
            });
        }

        return $expired;
    }

    /**
     * Void a hold now: an authorized one, or one whose capture failed. Run
     * from VoidPayment, outside any transaction.
     *
     * @throws GatewayException when the outcome is unknown; the job retries
     */
    public function void(PaymentAuthorization $payment, Actor $actor): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Never call the payment provider inside a database transaction.');
        }

        $payment->refresh();

        if (! in_array($payment->status, [PaymentStatus::Authorized, PaymentStatus::CaptureFailed], true) || $payment->provider_payment_id === null) {
            return;
        }

        $attempt = $this->attempt($payment, TransactionType::Void, 0, 'void:'.$payment->uuid, $actor);

        if ($attempt->status !== TransactionStatus::Pending) {
            return;
        }

        // A pending row from an earlier try means its outcome is unknown: ask first.
        if ($attempt->attempts > 0 && $this->gateway->fetch($payment->provider_payment_id)->status === 'voided') {
            $this->recordVoid($payment, $attempt, true, null, null, ['status' => 'voided', 'resolved_by' => 'fetch'], $actor);

            return;
        }

        $attempt->increment('attempts');
        Log::info('payments.void.sending', ['payment' => $payment->uuid, 'idempotency_key' => $attempt->idempotency_key]);

        $result = $this->gateway->void($payment->provider_payment_id, $attempt->idempotency_key);

        $this->recordVoid($payment, $attempt, $result->succeeded, $result->errorCode, $result->errorMessage, $result->payload, $actor, $result->providerReference);

        if (! $result->succeeded) {
            // A refused void is left for daily reconciliation; retrying will not change the answer.
            Log::warning('payments.void.refused', ['payment' => $payment->uuid, 'error' => $result->errorCode]);
        }
    }

    /**
     * The attempt row for this call, created (and committed) before the call
     * is sent. A retry finds the same row through its idempotency key.
     */
    private function attempt(PaymentAuthorization $payment, TransactionType $type, int $amount, string $key, Actor $actor): PaymentTransaction
    {
        return PaymentTransaction::query()->firstOrCreate(['idempotency_key' => $key], [
            'payment_authorization_id' => $payment->id,
            'type' => $type,
            'amount_halalas' => $amount,
            'requested_by_type' => $actor->type->value,
            'requested_by_id' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordVoid(
        PaymentAuthorization $payment,
        PaymentTransaction $attempt,
        bool $succeeded,
        ?string $errorCode,
        ?string $errorMessage,
        array $payload,
        Actor $actor,
        ?string $reference = null,
    ): void {
        DB::transaction(function () use ($payment, $attempt, $succeeded, $errorCode, $errorMessage, $payload, $actor, $reference): void {
            $locked = PaymentAuthorization::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $row = PaymentTransaction::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== TransactionStatus::Pending) {
                return;
            }

            $row->forceFill([
                'status' => $succeeded ? TransactionStatus::Succeeded : TransactionStatus::Failed,
                'provider_reference' => $reference,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'response_payload' => $payload,
                'completed_at' => now(),
            ])->save();

            if ($succeeded && in_array($locked->status, [PaymentStatus::Authorized, PaymentStatus::CaptureFailed], true)) {
                $from = $locked->status;
                $locked->status = PaymentStatus::Voided;
                $locked->voided_at = now();
                $locked->provider_status = 'voided';
                $locked->save();
                $this->history->record($locked, $from, PaymentStatus::Voided, $actor, $locked->rejection_reason?->value);
            }
        });
    }
}
