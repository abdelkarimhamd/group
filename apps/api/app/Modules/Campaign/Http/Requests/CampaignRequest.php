<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Campaign\Enums\DeliveryMode;
use Illuminate\Validation\Rule;

/**
 * The `AdminCampaignInput` schema, for creating a draft and for editing one
 * (which also needs the `lock_version` read). An edit replaces the draft's
 * terms: `per_customer_max_qty` left out means no per-customer limit, and
 * `delivery_mode` left out means `warehouse`.
 */
final class CampaignRequest extends ApiRequest
{
    /** Largest quantity a campaign may offer; keeps every amount far from overflow. */
    public const MAX_QTY = 100_000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string', 'uuid', Rule::exists('products', 'uuid')],
            'supplier_quotation_id' => ['required', 'string', 'uuid', Rule::exists('supplier_quotations', 'uuid')],
            'title_ar' => ['required', 'string', 'max:200'],
            'title_en' => ['required', 'string', 'max:200'],
            'product_variant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_variant_ids.*' => ['required', 'string', 'uuid', 'distinct'],
            'min_target_qty' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QTY],
            'max_qty' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QTY, 'gte:min_target_qty'],
            'per_customer_max_qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'lte:max_qty'],
            'starts_at' => ['required', 'date'],
            // `direct` delivery comes after the pilot.
            'delivery_mode' => ['sometimes', Rule::in([DeliveryMode::Warehouse->value])],
            'lock_version' => $this->isMethod('PATCH') ? ['required', 'integer', 'min:1'] : ['exclude'],
        ];
    }
}
