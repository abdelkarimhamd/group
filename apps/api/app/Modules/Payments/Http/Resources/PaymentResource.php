<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Checkout;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer `Payment` schema. `checkout` is present only while the
 * payment waits for the customer. Load `participation.campaign` first.
 *
 * @mixin PaymentAuthorization
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $participation = $this->participation;

        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'amount' => Money::sar($this->authorized_amount_halalas),
            'captured_amount' => Money::sar($this->captured_amount_halalas),
            'refunded_amount' => Money::sar($this->refunded_amount_halalas),
            'scheme' => $this->scheme,
            'card_last4' => $this->card_last4,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'rejection_reason' => $this->rejection_reason?->value,
            'checkout' => $this->status === PaymentStatus::PendingAuthorization
                ? Checkout::for($this->resource, $participation->campaign->code.' x'.$participation->qty)
                : null,
        ];
    }
}
