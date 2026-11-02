<?php

namespace App\Modules\Payments\Services;

use App\Exceptions\ApiException;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Services\Approvals;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\Refund;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Refund requests (spec, Payments: refunds). An admin asks; a second admin
 * with the finance role approves (E15), and only then is Moyasar called.
 * Never more than was captured minus what is already refunded or asked for.
 */
final class Refunds
{
    public function __construct(
        private readonly Approvals $approvals,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
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

            if (($input['customer_order_id'] ?? null) !== null && $order?->uuid !== $input['customer_order_id']) {
                throw ApiException::unprocessable('order_not_for_payment', 'customer_order_id');
            }

            $amount = Money::sar((int) $input['amount_halalas']);
            $captured = Money::sar($payment->captured_amount_halalas);
            $outstanding = Money::sar((int) $payment->refunds()->whereIn('status', RefundStatus::outstanding())->sum('amount_halalas'));

            if ($amount->greaterThan($captured->minus($outstanding))) {
                throw ApiException::unprocessable('refund_exceeds_captured', 'amount_halalas');
            }

            // A full refund soon after capture is a void: no fee, nothing settles.
            $voidable = $outstanding->isZero() && $amount->equals($captured) && $payment->captured_at !== null
                && $payment->captured_at->greaterThan(CarbonImmutable::now()->subMinutes((int) config('groupbuy.payments.void_after_capture_minutes')));

            $refund = new Refund;
            $uuid = $refund->newUniqueId();
            $refund->forceFill([
                'uuid' => $uuid,
                'payment_authorization_id' => $payment->id,
                'customer_order_id' => $order?->id,
                'amount_halalas' => $amount->minor,
                'method' => $voidable ? RefundMethod::Void : RefundMethod::Refund,
                'reason' => RefundReason::from($input['reason']),
                'status' => RefundStatus::PendingApproval,
                'note' => $input['note'] ?? null,
                'idempotency_key' => "refund:{$uuid}",
                'requested_by_type' => 'admin',
                'requested_by_id' => $by->id,
            ])->save();

            $actor = Actor::admin($by->id);
            $this->history->record($refund, null, RefundStatus::PendingApproval, $actor, $refund->reason->value);

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
}
