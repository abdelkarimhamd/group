<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `TierSetInput` schema. Coverage and price order are checked against
 * the campaign by App\Modules\Pricing\TierSet.
 */
final class TierSetRequest extends ApiRequest
{
    /** 10,000,000 SAR per unit: far beyond any real price, far from overflow. */
    public const MAX_UNIT_PRICE_HALALAS = 1_000_000_000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tiers' => ['required', 'array', 'min:1', 'max:20'],
            'tiers.*.min_qty' => ['required', 'integer', 'min:1', 'max:'.CampaignRequest::MAX_QTY],
            'tiers.*.max_qty' => ['required', 'integer', 'min:1', 'max:'.CampaignRequest::MAX_QTY, 'gte:tiers.*.min_qty'],
            'tiers.*.unit_price_halalas' => ['required', 'integer', 'min:1', 'max:'.self::MAX_UNIT_PRICE_HALALAS],
        ];
    }
}
