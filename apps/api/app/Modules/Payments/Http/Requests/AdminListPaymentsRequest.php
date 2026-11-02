<?php

namespace App\Modules\Payments\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Validation\Rule;

/**
 * Admin payment list. Newest first; with `expiring_within_days`, only
 * authorized holds, soonest expiry first (the hold-expiry alert list).
 */
final class AdminListPaymentsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'campaign_id' => ['sometimes', 'string', 'uuid'],
            'expiring_within_days' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('groupbuy.payments.hold_lifetime_days')],
            ...CursorPage::rules($this->expiring() ? ['expires_at', 'id'] : ['id']),
        ];
    }

    public function expiring(): bool
    {
        return $this->query('expiring_within_days') !== null;
    }
}
