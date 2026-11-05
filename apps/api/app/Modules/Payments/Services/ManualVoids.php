<?php

namespace App\Modules\Payments\Services;

use App\Exceptions\ApiException;
use App\Modules\Finance\Enums\ApprovalStatus;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Enums\MismatchType;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Models\ReconciliationItem;
use App\Modules\Finance\Services\Approvals;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Enums\TransactionType;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\ProviderPaymentNotFound;
use App\Modules\Payments\Jobs\ExecuteManualVoid;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Releasing a hold Moyasar still keeps although we no longer count it
 * (reconciliation's orphan_hold and stuck_void): the automatic void was
 * refused, or Moyasar authorized a payment after we failed it. An admin
 * asks, a second admin with the finance role approves (Decisions), and the
 * void is sent after commit with its own key,
 * void:{payment uuid}:{approval uuid}.
 *
 * Every try asks Moyasar first and calls void only while Moyasar shows the
 * payment authorized, so a retry after an unknown outcome never sends it
 * blind. A hold that still counts for a campaign, or one whose capture is
 * in flight, cannot be released this way; nor can one with an approved
 * release still to run, or one we voided unless reconciliation found
 * Moyasar still holding it (an open stuck_void).
 */
final class ManualVoids
{
    /** Ours, where the hold no longer counts. An authorized hold counts unless it carries a rejection reason. */
    private const RELEASED = [PaymentStatus::Failed, PaymentStatus::CaptureFailed, PaymentStatus::Expired, PaymentStatus::Voided];

    public function __construct(
        private readonly Approvals $approvals,
        private readonly PaymentGateway $gateway,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  ApprovalRequest|null  $deciding  the request being approved, which is not "another" release
     */
    public static function requestable(PaymentAuthorization $payment, ?ApprovalRequest $deciding = null): bool
    {
        if ($payment->provider_payment_id === null) {
            return false;
        }

        $released = in_array($payment->status, self::RELEASED, true)
            || ($payment->status === PaymentStatus::Authorized && $payment->rejection_reason !== null);

        // Voided here means voided at Moyasar too, unless reconciliation saw otherwise.
        if ($payment->status === PaymentStatus::Voided && ! ReconciliationItem::query()
            ->where('payment_authorization_id', $payment->id)
            ->where('mismatch_type', MismatchType::StuckVoid)
            ->whereNull('resolved_at')
            ->exists()) {
            return false;
        }

        return $released
            && ! PaymentTransaction::query()
                ->where('idempotency_key', Captures::attemptKey($payment))
                ->where('status', TransactionStatus::Pending)
                ->exists()
            && ! self::releaseInProgress($payment, $deciding);
    }

    /**
     * An approved release of this hold that has not run to the end yet.
     */
    private static function releaseInProgress(PaymentAuthorization $payment, ?ApprovalRequest $except): bool
    {
        return ApprovalRequest::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->where('type', ApprovalType::ManualVoid)
            ->where('subject_type', $payment->getMorphClass())
            ->where('subject_id', $payment->id)
            ->where('status', ApprovalStatus::Approved)
            ->whereNull('executed_at')
            ->exists();
    }

    /**
     * @throws ApiException 409 not_voidable
     */
    public function request(string $paymentUuid, string $reason, AdminUser $by): ApprovalRequest
    {
        return DB::transaction(function () use ($paymentUuid, $reason, $by): ApprovalRequest {
            $payment = PaymentAuthorization::query()->where('uuid', $paymentUuid)->lockForUpdate()->firstOrFail();

            if (! self::requestable($payment)) {
                throw new ApiException(409, 'not_voidable');
            }

            return $this->approvals->request(ApprovalType::ManualVoid, $payment, [
                'reason' => $reason,
                'status' => $payment->status->value,
                'provider_payment_id' => $payment->provider_payment_id,
                'authorized_halalas' => $payment->authorized_amount_halalas,
            ], $by);
        });
    }

    public function lockForDecision(int $paymentId): PaymentAuthorization
    {
        return PaymentAuthorization::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
    }

    /**
     * The second admin approved: the void is sent after commit. Call with
     * the payment locked (lockForDecision).
     *
     * @throws ApiException 409 not_voidable when the hold counts again (a capture started, say) or another release was approved first
     */
    public function approved(PaymentAuthorization $payment, ApprovalRequest $request): void
    {
        if (! self::requestable($payment, $request)) {
            throw new ApiException(409, 'not_voidable');
        }

        ExecuteManualVoid::dispatch($request->id)->afterCommit();
    }

    /**
     * Run from ExecuteManualVoid, outside any transaction.
     *
     * @throws GatewayException when Moyasar cannot be reached or the outcome is unknown; the job retries
     */
    public function execute(ApprovalRequest $request): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Never call the payment provider inside a database transaction.');
        }

        $payment = PaymentAuthorization::query()->whereKey($request->subject_id)->firstOrFail();
        $actor = Actor::admin((int) $request->decided_by_admin_id);
        $attempt = PaymentTransaction::query()->firstOrCreate(['idempotency_key' => self::attemptKey($payment, $request)], [
            'payment_authorization_id' => $payment->id,
            'type' => TransactionType::Void,
            'amount_halalas' => 0,
            'requested_by_type' => $actor->type->value,
            'requested_by_id' => $actor->id,
        ]);

        if ($attempt->status !== TransactionStatus::Pending) {
            return;
        }

        // Ask first, every time: Moyasar documents no idempotency for void.
        try {
            $remote = $this->gateway->fetch((string) $payment->provider_payment_id);
        } catch (ProviderPaymentNotFound $e) {
            // Retrying never changes this answer.
            $this->record($payment, $attempt, $request, false, 'not_found', $e->getMessage(), ['resolved_by' => 'fetch'], $actor);

            return;
        }

        if ($remote->status === 'voided') {
            $this->record($payment, $attempt, $request, true, null, null, ['resolved_by' => 'fetch'] + $remote->raw, $actor);

            return;
        }

        if ($remote->status !== 'authorized') {
            $this->record($payment, $attempt, $request, false, 'not_voidable', "Moyasar shows the payment {$remote->status}.", ['resolved_by' => 'fetch'] + $remote->raw, $actor);

            return;
        }

        $attempt->increment('attempts');
        Log::info('payments.manual_void.sending', ['payment' => $payment->uuid, 'idempotency_key' => $attempt->idempotency_key, 'approval' => $request->uuid]);

        $result = $this->gateway->void((string) $payment->provider_payment_id, $attempt->idempotency_key);

        $this->record($payment, $attempt, $request, $result->succeeded, $result->errorCode, $result->errorMessage, $result->payload, $actor, $result->providerReference);
    }

    public static function attemptKey(PaymentAuthorization $payment, ApprovalRequest $request): string
    {
        return 'void:'.$payment->uuid.':'.$request->uuid;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        PaymentAuthorization $payment,
        PaymentTransaction $attempt,
        ApprovalRequest $request,
        bool $succeeded,
        ?string $errorCode,
        ?string $errorMessage,
        array $payload,
        Actor $actor,
        ?string $reference = null,
    ): void {
        DB::transaction(function () use ($payment, $attempt, $request, $succeeded, $errorCode, $errorMessage, $payload, $actor, $reference): void {
            $locked = PaymentAuthorization::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $row = PaymentTransaction::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== TransactionStatus::Pending) {
                return;
            }

            $now = CarbonImmutable::now();
            $row->forceFill([
                'status' => $succeeded ? TransactionStatus::Succeeded : TransactionStatus::Failed,
                'provider_reference' => $reference,
                'error_code' => $errorCode,
                'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 500),
                'response_payload' => $payload,
                'completed_at' => $now,
            ])->save();

            ApprovalRequest::query()->whereKey($request->id)->update(['executed_at' => $now, 'updated_at' => $now]);

            if ($succeeded) {
                $from = $locked->status;

                if ($from !== PaymentStatus::Voided) {
                    $locked->status = PaymentStatus::Voided;
                    $locked->voided_at = $now;
                    $this->history->record($locked, $from, PaymentStatus::Voided, $actor, 'manual_void');
                }

                $locked->provider_status = 'voided';
                $locked->save();

                // The automatic void, if one is still open, has nothing left to do.
                PaymentTransaction::query()
                    ->where('payment_authorization_id', $locked->id)
                    ->where('type', TransactionType::Void)
                    ->where('status', TransactionStatus::Pending)
                    ->whereKeyNot($row->id)
                    ->update([
                        'status' => TransactionStatus::Failed->value,
                        'error_code' => 'superseded',
                        'error_message' => 'Released by the approved manual void '.$request->uuid.'.',
                        'completed_at' => $now,
                        'updated_at' => $now,
                    ]);
            } else {
                Log::warning('payments.manual_void.refused', ['payment' => $payment->uuid, 'error' => $errorCode, 'approval' => $request->uuid]);
            }

            $this->audit->record($actor, 'payment.manual_void', $locked, null, [
                'approval' => $request->uuid,
                'succeeded' => $succeeded,
                'error_code' => $errorCode,
            ]);
        });
    }
}
