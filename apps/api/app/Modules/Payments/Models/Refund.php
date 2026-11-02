<?php

namespace App\Modules\Payments\Models;

use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money returned after a capture (spec, Payments: refunds). An admin's
 * request waits for a second admin with the finance role; the call to
 * Moyasar is keyed by `idempotency_key` (refund:{uuid}).
 *
 * @property int $id
 * @property string $uuid
 * @property int $payment_authorization_id
 * @property int|null $customer_order_id
 * @property int $amount_halalas
 * @property string $currency
 * @property RefundMethod $method
 * @property RefundReason $reason
 * @property RefundStatus $status
 * @property string|null $note
 * @property string|null $provider_refund_id
 * @property string $idempotency_key
 * @property string $requested_by_type
 * @property int|null $requested_by_id
 * @property int|null $approval_request_id
 * @property int|null $credit_note_id
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PaymentAuthorization $payment
 * @property-read CustomerOrder|null $order
 * @property-read ApprovalRequest|null $approvalRequest
 * @property-read Invoice|null $creditNote
 * @property-read AdminUser|null $adminActor
 */
class Refund extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending_approval',
        'currency' => 'SAR',
    ];

    protected function casts(): array
    {
        return [
            'method' => RefundMethod::class,
            'reason' => RefundReason::class,
            'status' => RefundStatus::class,
            'amount_halalas' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<PaymentAuthorization, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'payment_authorization_id');
    }

    /**
     * @return BelongsTo<CustomerOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credit_note_id');
    }

    /**
     * The admin who asked, when `requested_by_type` is admin.
     *
     * @return BelongsTo<AdminUser, $this>
     */
    public function adminActor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_id');
    }
}
