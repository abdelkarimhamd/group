<?php

namespace App\Modules\Payments\Services;

use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\Closing;
use App\Modules\Orders\Services\Orders;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RejectionReason;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Enums\TransactionType;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\ProviderPayment;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Capturing a hold at the final price (spec, Payment holds: "Capture").
 * Run from CapturePayment, outside any transaction:
 *
 *  1. Lock the rows (campaign, participation, payment). A join that no
 *     longer counts is skipped; a hold past its capture cutoff becomes
 *     capture_failed without a call.
 *  2. Write the attempt keyed capture:{payment uuid} and commit, then ask
 *     Moyasar for a partial capture of the participation's capture amount.
 *  3. Record the answer under the same locks: captured (with the customer
 *     order and its tax invoice), or capture_failed (then voided). A result
 *     already recorded is left alone.
 *
 * An unknown outcome (timeout, Moyasar down) leaves the attempt pending and
 * the job retries; each later try fetches the payment first and sends the
 * capture again only if Moyasar shows it was not made. Past the cutoff,
 * a capture Moyasar cannot confirm stays authorized for finance to review:
 * a hold is never voided blind.
 */
class Captures
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly StatusHistory $history,
        private readonly Holds $holds,
        private readonly Closing $closing,
        private readonly Orders $orders,
    ) {}

    /**
     * @throws GatewayException when the outcome is unknown; the job retries
     */
    public function capture(PaymentAuthorization $payment, Actor $actor): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Never call the payment provider inside a database transaction.');
        }

        $payment->refresh();
        $attempt = PaymentTransaction::query()->where('idempotency_key', self::key($payment))->first();

        if ($attempt !== null && $attempt->status !== TransactionStatus::Pending) {
            return;
        }

        // An earlier try may have reached Moyasar: ask before sending again.
        if ($attempt !== null && $attempt->attempts > 0 && $payment->provider_payment_id !== null) {
            $remote = $this->fetch($payment, $attempt);

            if (self::wasCaptured($remote, $attempt)) {
                $this->record($payment, $attempt, true, null, null, ['resolved_by' => 'fetch'] + $remote->raw, $actor, $remote->id);

                return;
            }

            if ($remote->status === 'captured') {
                // Captured for another amount: never guess, finance decides.
                Log::critical('payments.capture.amount_mismatch', ['payment' => $payment->uuid, 'captured_halalas' => $remote->capturedHalalas, 'expected_halalas' => $attempt->amount_halalas]);

                return;
            }

            if ($remote->status !== 'authorized') {
                $this->record($payment, $attempt, false, 'not_capturable', "Moyasar shows the payment {$remote->status}.", ['resolved_by' => 'fetch'] + $remote->raw, $actor);

                return;
            }
        }

        $attempt = $this->prepare($payment, $actor);

        if ($attempt === null) {
            return;
        }

        $attempt->increment('attempts');
        Log::info('payments.capture.sending', ['payment' => $payment->uuid, 'idempotency_key' => $attempt->idempotency_key, 'amount_halalas' => $attempt->amount_halalas]);

        try {
            $result = $this->gateway->capture((string) $payment->provider_payment_id, $attempt->amount_halalas, $attempt->idempotency_key);
        } catch (GatewayException $e) {
            // On any error, fetch first: the capture may have gone through.
            $remote = $this->fetch($payment, $attempt);

            if (self::wasCaptured($remote, $attempt)) {
                $this->record($payment, $attempt, true, null, null, ['resolved_by' => 'fetch'] + $remote->raw, $actor, $remote->id);

                return;
            }

            $this->alertIfLate($payment, $attempt, $e);

            throw $e;
        }

        if ($result->succeeded) {
            $this->record($payment, $attempt, true, null, null, $result->payload, $actor, $result->providerReference);

            return;
        }

        // A refusal: Moyasar may still show it captured (an earlier call landed).
        try {
            $remote = $this->gateway->fetch((string) $payment->provider_payment_id);

            if (self::wasCaptured($remote, $attempt)) {
                $this->record($payment, $attempt, true, null, null, ['resolved_by' => 'fetch'] + $remote->raw, $actor, $remote->id);

                return;
            }
        } catch (GatewayException) {
            // The refusal itself is a definite answer.
        }

        $this->record($payment, $attempt, false, $result->errorCode, $result->errorMessage, $result->payload, $actor);
    }

    /**
     * The job's retries ran out with the outcome still unknown: the hold
     * stays authorized and goes to finance review (daily reconciliation
     * lists it); the closing sweep keeps asking Moyasar.
     */
    public function unresolved(PaymentAuthorization $payment, \Throwable $e): void
    {
        Log::critical('payments.capture.unconfirmed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
    }

    /**
     * Step 1: lock, check, and write (or find) the attempt row. Returns null
     * when there is nothing to send.
     */
    private function prepare(PaymentAuthorization $payment, Actor $actor): ?PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $actor): ?PaymentTransaction {
            [$campaign, $participation, $payment] = $this->lock($payment);

            if ($payment->status !== PaymentStatus::Authorized || $payment->rejection_reason !== null || $participation->status !== ParticipationStatus::Authorized) {
                return null;
            }

            if ($campaign->status !== CampaignStatus::Closing || $campaign->closing_step !== ClosingStep::Capturing) {
                return null;
            }

            $amount = $participation->capture_amount_halalas
                ?? throw new LogicException("Participation {$participation->id} has no capture amount.");

            if ($amount > $payment->authorized_amount_halalas) {
                throw new LogicException("Capture of {$amount} is above the hold on payment {$payment->id}.");
            }

            $attempt = PaymentTransaction::query()->where('idempotency_key', self::key($payment))->lockForUpdate()->first();

            if ($attempt !== null && $attempt->status !== TransactionStatus::Pending) {
                return null;
            }

            // The cutoff is checked before the first call and every retry.
            if (! CarbonImmutable::now()->lessThan($payment->captureCutoffAt())) {
                if ($attempt !== null) {
                    $this->complete($attempt, false, 'capture_cutoff_passed', 'The hold reached its capture cutoff.', [], null);
                }

                $this->closing->captureFailed($participation, $payment, RejectionReason::CaptureCutoffPassed, $actor);

                return null;
            }

            return $attempt ?? PaymentTransaction::create([
                'payment_authorization_id' => $payment->id,
                'type' => TransactionType::Capture,
                'amount_halalas' => $amount,
                'idempotency_key' => self::key($payment),
                'requested_by_type' => $actor->type->value,
                'requested_by_id' => $actor->id,
            ]);
        });
    }

    /**
     * Step 3: record Moyasar's answer once, with the rows locked.
     *
     * @param  array<string, mixed>  $payload
     */
    private function record(
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
            [, $participation, $payment] = $this->lock($payment);
            $row = PaymentTransaction::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== TransactionStatus::Pending) {
                return;
            }

            $this->complete($row, $succeeded, $errorCode, $errorMessage, $payload, $reference);

            if (! $succeeded) {
                Log::warning('payments.capture.declined', ['payment' => $payment->uuid, 'error' => $errorCode]);
                $this->closing->captureFailed($participation, $payment, RejectionReason::CaptureDeclined, $actor);

                return;
            }

            if ($payment->status !== PaymentStatus::Authorized) {
                // Charged although the hold was settled here meanwhile: finance must look.
                Log::critical('payments.capture.after_settled', ['payment' => $payment->uuid, 'status' => $payment->status->value]);

                return;
            }

            $payment->status = PaymentStatus::Captured;
            $payment->captured_amount_halalas = $row->amount_halalas;
            $payment->captured_at = CarbonImmutable::now();
            $payment->provider_status = 'captured';
            $payment->save();
            $this->history->record($payment, PaymentStatus::Authorized, PaymentStatus::Captured, $actor);

            $this->closing->captured($participation, $actor);

            // The order, and its tax invoice, exist from the moment the money is taken.
            if ($participation->status === ParticipationStatus::Captured) {
                $this->orders->createForCapture($participation, $payment, $actor);
            }

            PaymentCaptured::dispatch($payment->id);
        });
    }

    /**
     * Locks campaign, participation and payment, in that order.
     *
     * @return array{Campaign, CampaignParticipation, PaymentAuthorization}
     */
    private function lock(PaymentAuthorization $payment): array
    {
        $campaignId = CampaignParticipation::query()->whereKey($payment->participation_id)->value('campaign_id');
        $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
        $participation = CampaignParticipation::query()->whereKey($payment->participation_id)->lockForUpdate()->firstOrFail();
        $payment = PaymentAuthorization::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        return [$campaign, $participation, $payment];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function complete(PaymentTransaction $row, bool $succeeded, ?string $errorCode, ?string $errorMessage, array $payload, ?string $reference): void
    {
        $row->forceFill([
            'status' => $succeeded ? TransactionStatus::Succeeded : TransactionStatus::Failed,
            'provider_reference' => $reference,
            'error_code' => $errorCode,
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 500),
            'response_payload' => $payload,
            'completed_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * @throws GatewayException when Moyasar cannot be reached
     */
    private function fetch(PaymentAuthorization $payment, PaymentTransaction $attempt): ProviderPayment
    {
        Log::info('payments.capture.checking', ['payment' => $payment->uuid, 'idempotency_key' => $attempt->idempotency_key]);

        try {
            return $this->gateway->fetch((string) $payment->provider_payment_id);
        } catch (GatewayException $e) {
            $this->alertIfLate($payment, $attempt, $e);

            throw $e;
        }
    }

    /**
     * Ops are told once a capture has been failing for an hour.
     */
    private function alertIfLate(PaymentAuthorization $payment, PaymentTransaction $attempt, \Throwable $e): void
    {
        $since = CarbonImmutable::now()->subMinutes((int) config('groupbuy.payments.capture_alert_after_minutes'));

        if ($attempt->created_at->lessThanOrEqualTo($since)) {
            Log::critical('payments.capture.delayed', ['payment' => $payment->uuid, 'since' => $attempt->created_at->toIso8601ZuluString(), 'error' => $e->getMessage()]);
        }
    }

    private static function wasCaptured(ProviderPayment $remote, PaymentTransaction $attempt): bool
    {
        return $remote->status === 'captured' && $remote->capturedHalalas === $attempt->amount_halalas;
    }

    private static function key(PaymentAuthorization $payment): string
    {
        return 'capture:'.$payment->uuid;
    }
}
