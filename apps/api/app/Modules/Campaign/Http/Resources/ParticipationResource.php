<?php

namespace App\Modules\Campaign\Http\Resources;

use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer `Participation` schema. Load RELATIONS first.
 *
 * @mixin CampaignParticipation
 */
class ParticipationResource extends JsonResource
{
    public const RELATIONS = [
        'campaign.currentTiers', 'campaign.product.images.variant', 'variant', 'latestPayment.participation.campaign', 'order',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->campaign;

        return [
            'id' => $this->uuid,
            'campaign' => CampaignSummaryResource::make($campaign)->resolve($request),
            'product_variant' => CampaignDetailResource::variant($this->variant),
            'quantity' => $this->qty,
            'status' => $this->status->value,
            'hold_unit_price' => Money::sar($this->hold_unit_price_halalas),
            'hold_amount' => Money::sar($this->hold_amount_halalas),
            'final_unit_price' => $this->final_unit_price_halalas === null ? null : Money::sar($this->final_unit_price_halalas),
            'capture_amount' => $this->capture_amount_halalas === null ? null : Money::sar($this->capture_amount_halalas),
            'reservation_expires_at' => $this->reservation_expires_at->toIso8601ZuluString(),
            // Proposed rule (Q16): only while active and before the deal is confirmed.
            'can_cancel' => in_array($this->status, [ParticipationStatus::PendingPayment, ParticipationStatus::Authorized], true)
                && $campaign->status === CampaignStatus::Active
                && $campaign->deal_confirmed_at === null,
            'payment' => $this->latestPayment === null ? null : PaymentResource::make($this->latestPayment)->resolve($request),
            'order_id' => $this->order?->uuid,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
