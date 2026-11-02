<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Campaign\Http\Resources\CampaignDetailResource;
use App\Modules\Campaign\Http\Resources\CampaignSummaryResource;
use App\Modules\Orders\Models\CustomerOrder;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Order` schema: what the customer sees.
 *
 * @mixin CustomerOrder
 */
class OrderResource extends JsonResource
{
    public const RELATIONS = ['campaign.currentTiers', 'campaign.product.images.variant', 'variant', 'payment', 'invoices'];

    /** The address fields of the `AddressInput` schema, in its order. */
    private const ADDRESS_FIELDS = [
        'recipient_name', 'recipient_phone', 'city', 'district', 'street', 'building_no',
        'postal_code', 'additional_no', 'short_address', 'lat', 'lng',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->payment;
        $address = [];

        foreach (self::ADDRESS_FIELDS as $field) {
            $address[$field] = $this->shipping_address[$field] ?? null;
        }

        return [
            'id' => $this->uuid,
            'order_number' => $this->order_number,
            'campaign' => CampaignSummaryResource::make($this->campaign)->resolve($request),
            'product_variant' => CampaignDetailResource::variant($this->variant),
            'quantity' => $this->qty,
            'unit_price' => Money::sar($this->unit_price_halalas),
            'goods_total' => Money::sar($this->goods_total_halalas),
            'delivery_fee' => Money::sar($this->delivery_fee_halalas),
            'total' => Money::sar($this->total_halalas),
            'vat' => Money::sar($this->vat_halalas),
            'status' => $this->status->value,
            'shipping_address' => $address,
            'payment' => [
                'status' => $payment->status->value,
                'captured_amount' => Money::sar($payment->captured_amount_halalas),
                'refunded_amount' => Money::sar($payment->refunded_amount_halalas),
            ],
            // Shipments arrive with fulfilment (E13).
            'shipment' => null,
            'invoices' => InvoiceRefResource::collection($this->invoices)->resolve($request),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'return_window_ends_at' => $this->return_window_ends_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
