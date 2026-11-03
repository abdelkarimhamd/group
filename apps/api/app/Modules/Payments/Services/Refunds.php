<?php

namespace App\Modules\Payments\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\CampaignNotices;
use App\Modules\Finance\Enums\ApprovalStatus;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Services\Approvals;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Fulfillment\Services\Shipments;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Services\Invoices;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Enums\TransactionType;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\GatewayResult;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\ProviderPayment;
use App\Modules\Payments\Jobs\ExecuteRefund;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Refund;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Refunds (spec, Payments: refunds). An admin asks; a second admin with the
 * finance role approves; only then is Moyasar called, from ExecuteRefund,
 * outside any transaction:
 *
 *  1. Lock the payment and the refund, move it to processing, and write
 *     (or find) the attempt keyed by the refund's idempotency key; commit.
 *  2. Ask Moyasar: a void for a full refund soon after capture (no fee),
 *     otherwise a refund. A refused void falls back to a refund under its
 *     own key ({key}:refund).
 *  3. Record the answer under the campaign, participation, payment and
 *     order locks: the payment's refunded amount, a credit note, the ledger
 *     posting, and the order closed when everything went back.
 *
 * An unknown outcome leaves the attempt pending and the job retries; each
 * retry fetches the payment first and sends again only if Moyasar shows
 * nothing was returned. A payment's refunds are sent one at a time: one
 * that finds another in flight stays approved and is sent when that one
 * settles. finance:requeue-approved queues again any refund left approved
 * or processing (its job ran out of tries or was lost), so PostgreSQL, not
 * the queue, remembers what still has to go back.
 */
final class Refunds
{
    public function __construct(
        private readonly Approvals $approvals,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
        private readonly PaymentGateway $gateway,
        private readonly Invoices $invoices,
        private readonly Ledger $ledger,
        private readonly Shipments $shipments,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array{payment_id: string, customer_order_id?: string|null, amount_halalas: int, reason: string, note?: string|null}  $input
     *
     * @throws ApiException 422 payment_not_captured, order_not_for_payment or refund_exceeds_captured
     */
    public function request(array $input, AdminUser $by): Refund
    {
        return DB::transaction(function () use ($input, $by): Refund {
            // The payment row lock serializes requests against the same capture.
            $payment = PaymentAuthorization::query()->where('uuid', $input['payment_id'])->lockForUpdate()->first()
                ?? throw ApiException::unprocessable('payment_not_found', 'payment_id');

            if (! in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded], true)) {
                throw ApiException::unprocessable('payment_not_captured', 'payment_id');
            }

            $order = CustomerOrder::query()->where('payment_authorization_id', $payment->id)->first();

            if (($input['customer_order_id'] ?? null) !== null && $order?->uuid !== strtolower($input['customer_order_id'])) {
                throw ApiException::unprocessable('order_not_for_payment', 'customer_order_id');
            }

            $amount = Money::sar((int) $input['amount_halalas']);

            if ($amount->greaterThan(self::refundable($payment))) {
                throw ApiException::unprocessable('refund_exceeds_captured', 'amount_halalas');
            }

            $actor = Actor::admin($by->id);
            $refund = $this->newRefund($payment, $order, $amount, RefundReason::from($input['reason']), $input['note'] ?? null, RefundStatus::PendingApproval, $by->id, $actor);

            $approval = $this->approvals->request(ApprovalType::ManualRefund, $refund, [
                'payment_id' => $payment->uuid,
                'customer_order_id' => $order?->uuid,
                'amount_halalas' => $amount->minor,
                'method' => $refund->method->value,
                'reason' => $refund->reason->value,
                'note' => $refund->note,
            ], $by);
            $refund->approval_request_id = $approval->id;
            $refund->save();

            $this->audit->record($actor, 'refund.request', $refund, after: [
                'payment_id' => $payment->uuid,
                'amount_halalas' => $amount->minor,
                'method' => $refund->method->value,
                'reason' => $refund->reason->value,
            ]);

            return $refund;
        });
    }

    /**
     * Everything still refundable on a payment, as an approved refund sent
     * after commit: an approved campaign cancellation returns the money of
     * captured joins this way. Call with the payment locked. Returns null
     * when nothing is left to refund.
     */
    public function createApproved(PaymentAuthorization $payment, ?CustomerOrder $order, RefundReason $reason, ApprovalRequest $approval, Actor $actor): ?Refund
    {
        $amount = self::refundable($payment);

        if (! $amount->greaterThan(Money::zero())) {
            return null;
        }

        $refund = $this->newRefund($payment, $order, $amount, $reason, null, RefundStatus::Approved, $approval->requested_by_admin_id, $actor);
        $refund->approval_request_id = $approval->id;
        $refund->save();

        $this->audit->record($actor, 'refund.approve', $refund, after: [
            'payment_id' => $payment->uuid,
            'amount_halalas' => $amount->minor,
            'method' => $refund->method->value,
            'reason' => $reason->value,
            'approval_request_id' => $approval->uuid,
        ]);
        ExecuteRefund::dispatch($refund->id)->afterCommit();

        return $refund;
    }

    /**
     * Refunds of a payment still waiting for approval are rejected, and
     * their approval requests withdrawn, when an approved campaign
     * cancellation returns everything left on the payment instead. Call
     * with the payment locked, before createApproved. Returns how many.
     */
    public function withdrawPending(PaymentAuthorization $payment, string $reason, Actor $actor): int
    {
        $pending = Refund::query()
            ->where('payment_authorization_id', $payment->id)
            ->where('status', RefundStatus::PendingApproval)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($pending as $refund) {
            $refund->status = RefundStatus::Rejected;
            $refund->save();
            $this->history->record($refund, RefundStatus::PendingApproval, RefundStatus::Rejected, $actor, $reason);
            $this->audit->record($actor, 'refund.reject', $refund, ['status' => RefundStatus::PendingApproval->value], ['status' => RefundStatus::Rejected->value, 'reason' => $reason]);

            $request = $refund->approval_request_id === null ? null
                : ApprovalRequest::query()->whereKey($refund->approval_request_id)->lockForUpdate()->first();

            if ($request?->status === ApprovalStatus::Pending) {
                $this->approvals->withdraw($request, $reason, $actor);
            }
        }

        return $pending->count();
    }

    /**
     * Locks a refund for a decision on its approval request: its payment
     * first, as everywhere else.
     */
    public function lockForDecision(int $refundId): Refund
    {
        $paymentId = Refund::query()->whereKey($refundId)->value('payment_authorization_id')
            ?? throw new LogicException("Refund {$refundId} is missing.");
        PaymentAuthorization::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

        return Refund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();
    }

    /**
     * The second admin approved: the refund is sent after commit. Call
     * with the refund locked (lockForDecision).
     */
    public function approve(Refund $refund, Actor $actor): void
    {
        if ($refund->status !== RefundStatus::PendingApproval) {
            throw new ApiException(409, 'invalid_transition');
        }

        $refund->status = RefundStatus::Approved;
        $refund->save();
        $this->history->record($refund, RefundStatus::PendingApproval, RefundStatus::Approved, $actor);
        $this->audit->record($actor, 'refund.approve', $refund, ['status' => RefundStatus::PendingApproval->value], ['status' => RefundStatus::Approved->value]);

        ExecuteRefund::dispatch($refund->id)->afterCommit();
    }

    /**
     * The second admin said no; nothing is sent. Call with the refund locked.
     */
    public function reject(Refund $refund, string $reason, Actor $actor): void
    {
        if ($refund->status !== RefundStatus::PendingApproval) {
            throw new ApiException(409, 'invalid_transition');
        }

        $refund->status = RefundStatus::Rejected;
        $refund->save();
        $this->history->record($refund, RefundStatus::PendingApproval, RefundStatus::Rejected, $actor, mb_substr($reason, 0, 255));
        $this->audit->record($actor, 'refund.reject', $refund, ['status' => RefundStatus::PendingApproval->value], ['status' => RefundStatus::Rejected->value, 'reason' => $reason]);
    }

    /**
     * Send an approved refund to Moyasar and record the answer. Run from
     * ExecuteRefund, outside any transaction.
     *
     * @throws GatewayException when the outcome is unknown; the job retries
     */
    public function execute(Refund $refund): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Never call the payment provider inside a database transaction.');
        }

        $actor = Actor::system();
        $attempt = $this->prepare($refund, $actor);

        if ($attempt === null) {
            return;
        }

        $payment = PaymentAuthorization::query()->findOrFail($refund->payment_authorization_id);
        $providerId = (string) $payment->provider_payment_id;

        // A pending row from an earlier try means its outcome is unknown: ask first.
        if ($attempt->attempts > 0 && $this->landed($this->gateway->fetch($providerId), $payment, $attempt)) {
            $this->succeeded($refund, $attempt, $providerId, ['resolved_by' => 'fetch'], $actor);

            return;
        }

        $attempt->increment('attempts');
        Log::info('payments.refund.sending', ['refund' => $refund->uuid, 'type' => $attempt->type->value, 'idempotency_key' => $attempt->idempotency_key, 'amount_halalas' => $attempt->amount_halalas]);

        try {
            $result = $attempt->type === TransactionType::Void
                ? $this->gateway->void($providerId, $attempt->idempotency_key)
                : $this->gateway->refund($providerId, $attempt->amount_halalas, $attempt->idempotency_key);
        } catch (GatewayException $e) {
            if ($this->landed($this->gateway->fetch($providerId), $payment, $attempt)) {
                $this->succeeded($refund, $attempt, $providerId, ['resolved_by' => 'fetch'], $actor);

                return;
            }

            throw $e;
        }

        if ($result->succeeded) {
            $this->succeeded($refund, $attempt, $result->providerReference, $result->payload, $actor);

            return;
        }

        // A refusal: an earlier call may still have landed.
        try {
            if ($this->landed($this->gateway->fetch($providerId), $payment, $attempt)) {
                $this->succeeded($refund, $attempt, $providerId, ['resolved_by' => 'fetch'], $actor);

                return;
            }
        } catch (GatewayException) {
            // The refusal itself is a definite answer.
        }

        $this->refused($refund, $attempt, $result, $actor);

        // A refused void (the capture settled, say) is sent again as a refund.
        if ($attempt->type === TransactionType::Void) {
            $this->execute($refund);
        }
    }

    /**
     * Step 1: lock, move to processing, and write (or find) the attempt.
     * Returns null when there is nothing to send.
     */
    private function prepare(Refund $refund, Actor $actor): ?PaymentTransaction
    {
        return DB::transaction(function () use ($refund, $actor): ?PaymentTransaction {
            $payment = PaymentAuthorization::query()->whereKey($refund->payment_authorization_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (! in_array($refund->status, [RefundStatus::Approved, RefundStatus::Processing], true)) {
                return null;
            }

            if ($payment->provider_payment_id === null) {
                throw new LogicException("Payment {$payment->id} has no Moyasar payment to refund.");
            }

            // Fetch-first retries compare refunded totals, so a payment's refunds go one at a time.
            $inFlight = Refund::query()
                ->where('payment_authorization_id', $payment->id)
                ->where('status', RefundStatus::Processing)
                ->whereKeyNot($refund->id)
                ->exists();

            if ($inFlight) {
                // It stays approved; the one in flight sends it when it settles.
                Log::info('payments.refund.waiting', ['refund' => $refund->uuid, 'payment' => $payment->uuid]);

                return null;
            }

            if ($refund->status === RefundStatus::Approved) {
                if ($payment->refunded_amount_halalas + $refund->amount_halalas > $payment->captured_amount_halalas) {
                    throw new LogicException("Refund {$refund->id} is more than is left on payment {$payment->id}.");
                }

                // Approval can come after the void window: then it is a refund.
                if ($refund->method === RefundMethod::Void && ! self::voidable($payment)) {
                    $refund->method = RefundMethod::Refund;
                }

                $refund->status = RefundStatus::Processing;
                $refund->save();
                $this->history->record($refund, RefundStatus::Approved, RefundStatus::Processing, $actor);
            }

            $key = $refund->method === RefundMethod::Void ? $refund->idempotency_key : self::refundKey($refund);
            $attempt = PaymentTransaction::query()->where('idempotency_key', $key)->lockForUpdate()->first();

            if ($attempt !== null) {
                return $attempt->status === TransactionStatus::Pending ? $attempt : null;
            }

            return PaymentTransaction::create([
                'payment_authorization_id' => $payment->id,
                'type' => $refund->method === RefundMethod::Void ? TransactionType::Void : TransactionType::Refund,
                'amount_halalas' => $refund->amount_halalas,
                'idempotency_key' => $key,
                'requested_by_type' => $refund->requested_by_type,
                'requested_by_id' => $refund->requested_by_id,
            ]);
        });
    }

    /**
     * Step 3, success: everything about the money going back, once.
     *
     * @param  array<string, mixed>  $payload
     */
    private function succeeded(Refund $refund, PaymentTransaction $attempt, ?string $reference, array $payload, Actor $actor): void
    {
        DB::transaction(function () use ($refund, $attempt, $reference, $payload, $actor): void {
            $participationId = PaymentAuthorization::query()->whereKey($refund->payment_authorization_id)->value('participation_id');
            $campaignId = CampaignParticipation::query()->whereKey($participationId)->value('campaign_id');
            $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            CampaignParticipation::query()->whereKey($participationId)->lockForUpdate()->firstOrFail();
            $payment = PaymentAuthorization::query()->whereKey($refund->payment_authorization_id)->lockForUpdate()->firstOrFail();
            $order = $refund->customer_order_id === null ? null : CustomerOrder::query()->whereKey($refund->customer_order_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $row = PaymentTransaction::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== TransactionStatus::Pending) {
                return;
            }

            self::complete($row, true, null, null, $payload, $reference);

            if ($refund->status !== RefundStatus::Processing) {
                Log::critical('payments.refund.after_settled', ['refund' => $refund->uuid, 'status' => $refund->status->value]);

                return;
            }

            $from = $payment->status;
            $payment->refunded_amount_halalas += $refund->amount_halalas;
            $payment->status = $payment->refunded_amount_halalas === $payment->captured_amount_halalas ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
            $payment->provider_status = $row->type === TransactionType::Void ? 'voided' : 'refunded';
            $payment->save();

            if ($from !== $payment->status) {
                $this->history->record($payment, $from, $payment->status, $actor, 'refund');
            }

            $creditNote = $order !== null && Invoice::query()->where('customer_order_id', $order->id)->exists()
                ? $this->invoices->issueCreditNote($order, $refund->amount_halalas, $actor)
                : null;

            $refund->status = RefundStatus::Succeeded;
            $refund->processed_at = CarbonImmutable::now();
            $refund->provider_refund_id = $reference === null ? null : mb_substr($reference, 0, 100);
            $refund->credit_note_id = $creditNote?->id;
            $refund->save();
            $this->history->record($refund, RefundStatus::Processing, RefundStatus::Succeeded, $actor);

            $this->ledger->postRefund($refund, $order, $creditNote, $actor);
            $this->sendWaiting($payment);
            $this->audit->record($actor, 'refund.succeed', $refund, ['status' => RefundStatus::Processing->value], [
                'status' => RefundStatus::Succeeded->value,
                'amount_halalas' => $refund->amount_halalas,
                'via' => $row->type->value,
                'credit_note' => $creditNote?->invoice_number,
            ]);

            if ($order === null) {
                return;
            }

            if ($payment->status === PaymentStatus::Refunded) {
                $this->closeOrder($campaign, $order, $actor);
            }

            $order->loadMissing(['user', 'campaign']);
            $this->notifier->send($order->user, NotificationTemplate::Refunded, $order, [
                'campaign' => CampaignNotices::title($order->campaign),
                'amount_halalas' => $refund->amount_halalas,
                'order' => $order->order_number,
            ], "refunded:refund:{$refund->id}");
        });
    }

    /**
     * Step 3, refusal. A refused void falls back to a refund (the refund
     * stays processing); a refused refund fails and goes to finance.
     */
    private function refused(Refund $refund, PaymentTransaction $attempt, GatewayResult $result, Actor $actor): void
    {
        DB::transaction(function () use ($refund, $attempt, $result, $actor): void {
            $payment = PaymentAuthorization::query()->whereKey($refund->payment_authorization_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $row = PaymentTransaction::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== TransactionStatus::Pending) {
                return;
            }

            self::complete($row, false, $result->errorCode, $result->errorMessage, $result->payload, null);

            if ($refund->status !== RefundStatus::Processing) {
                return;
            }

            if ($row->type === TransactionType::Void) {
                Log::warning('payments.refund.void_refused', ['refund' => $refund->uuid, 'error' => $result->errorCode]);
                $refund->method = RefundMethod::Refund;
                $refund->save();

                return;
            }

            Log::critical('payments.refund.refused', ['refund' => $refund->uuid, 'error' => $result->errorCode]);
            $refund->status = RefundStatus::Failed;
            $refund->processed_at = CarbonImmutable::now();
            $refund->save();
            $this->history->record($refund, RefundStatus::Processing, RefundStatus::Failed, $actor, $result->errorCode);
            $this->audit->record($actor, 'refund.fail', $refund, ['status' => RefundStatus::Processing->value], [
                'status' => RefundStatus::Failed->value,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ]);
            $this->sendWaiting($payment);
        });
    }

    /**
     * After commit, send the payment's refunds that waited for the one in flight.
     */
    private function sendWaiting(PaymentAuthorization $payment): void
    {
        Refund::query()
            ->where('payment_authorization_id', $payment->id)
            ->where('status', RefundStatus::Approved)
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => ExecuteRefund::dispatch($id)->afterCommit());
    }

    /**
     * Everything went back: an order not yet shipped is cancelled, a
     * delivered one is returned. A shipped order is left for the courier.
     */
    private function closeOrder(Campaign $campaign, CustomerOrder $order, Actor $actor): void
    {
        $to = match ($order->status) {
            OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyToShip => OrderStatus::Cancelled,
            OrderStatus::Delivered, OrderStatus::ReturnRequested => OrderStatus::Returned,
            default => null,
        };

        if ($to === null) {
            return;
        }

        $from = $order->status;
        $order->status = $to;
        $order->save();
        $this->history->record($order, $from, $to, $actor, 'refunded');
        $this->shipments->completeCampaignIfSettled($campaign, $actor);
    }

    private function landed(ProviderPayment $remote, PaymentAuthorization $payment, PaymentTransaction $attempt): bool
    {
        if ($attempt->type === TransactionType::Void) {
            return $remote->status === 'voided';
        }

        return $remote->refundedHalalas >= $payment->refunded_amount_halalas + $attempt->amount_halalas;
    }

    private function newRefund(PaymentAuthorization $payment, ?CustomerOrder $order, Money $amount, RefundReason $reason, ?string $note, RefundStatus $status, ?int $requestedBy, Actor $actor): Refund
    {
        $outstanding = (int) $payment->refunds()->whereIn('status', RefundStatus::outstanding())->sum('amount_halalas');
        // A full refund soon after capture is a void: no fee, nothing settles.
        $voidable = $outstanding === 0 && $amount->equals(Money::sar($payment->captured_amount_halalas)) && self::voidable($payment);

        $refund = new Refund;
        $uuid = $refund->newUniqueId();
        $refund->forceFill([
            'uuid' => $uuid,
            'payment_authorization_id' => $payment->id,
            'customer_order_id' => $order?->id,
            'amount_halalas' => $amount->minor,
            'method' => $voidable ? RefundMethod::Void : RefundMethod::Refund,
            'reason' => $reason,
            'status' => $status,
            'note' => $note,
            'idempotency_key' => "refund:{$uuid}",
            'requested_by_type' => 'admin',
            'requested_by_id' => $requestedBy,
        ])->save();

        $this->history->record($refund, null, $status, $actor, $reason->value);

        return $refund;
    }

    /**
     * Captured, less what is refunded or asked for.
     */
    private static function refundable(PaymentAuthorization $payment): Money
    {
        $outstanding = (int) $payment->refunds()->whereIn('status', RefundStatus::outstanding())->sum('amount_halalas');

        return Money::sar($payment->captured_amount_halalas)->minus(Money::sar($outstanding));
    }

    private static function voidable(PaymentAuthorization $payment): bool
    {
        return $payment->refunded_amount_halalas === 0 && $payment->captured_at !== null
            && $payment->captured_at->greaterThan(CarbonImmutable::now()->subMinutes((int) config('groupbuy.payments.void_after_capture_minutes')));
    }

    /**
     * A refund's own key, or the fallback after a refused void.
     */
    private static function refundKey(Refund $refund): string
    {
        $voided = PaymentTransaction::query()
            ->where('idempotency_key', $refund->idempotency_key)
            ->where('type', TransactionType::Void)
            ->exists();

        return $voided ? $refund->idempotency_key.':refund' : $refund->idempotency_key;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function complete(PaymentTransaction $row, bool $succeeded, ?string $errorCode, ?string $errorMessage, array $payload, ?string $reference): void
    {
        $row->forceFill([
            'status' => $succeeded ? TransactionStatus::Succeeded : TransactionStatus::Failed,
            'provider_reference' => $reference === null ? null : mb_substr($reference, 0, 100),
            'error_code' => $errorCode,
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 500),
            'response_payload' => $payload,
            'completed_at' => CarbonImmutable::now(),
        ])->save();
    }
}
