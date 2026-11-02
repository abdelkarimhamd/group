<?php

namespace App\Modules\Campaign\Http\Resources;

use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Catalog\Http\Resources\AdminVariantResource;
use App\Modules\Payments\Http\Resources\AdminPaymentResource;
use App\Support\Money\Money;
use App\Support\Phone\SaudiMobile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminParticipation` schema, with the customer's phone masked. Load RELATIONS first.
 *
 * @mixin CampaignParticipation
 */
class AdminParticipationResource extends JsonResource
{
    public const RELATIONS = ['user', 'variant', 'campaign', 'latestPayment.participation.campaign'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'customer' => [
                'id' => $this->user->uuid,
                'name' => $this->user->name,
                'phone' => $this->user->phone_e164 === null ? '' : SaudiMobile::mask($this->user->phone_e164),
            ],
            'product_variant' => AdminVariantResource::make($this->variant)->resolve($request),
            'quantity' => $this->qty,
            'status' => $this->status->value,
            'hold_unit_price' => Money::sar($this->hold_unit_price_halalas),
            'hold_amount' => Money::sar($this->hold_amount_halalas),
            'final_unit_price' => $this->final_unit_price_halalas === null ? null : Money::sar($this->final_unit_price_halalas),
            'capture_amount' => $this->capture_amount_halalas === null ? null : Money::sar($this->capture_amount_halalas),
            'payment' => $this->latestPayment === null ? null : AdminPaymentResource::make($this->latestPayment)->resolve($request),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
