<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `JoinRequest` schema. `shown_unit_price_halalas` is only compared with
 * the current price, never charged.
 */
final class JoinCampaignRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'uuid'],
            'product_variant_id' => ['required', 'string', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CampaignRequest::MAX_QTY],
            'shown_unit_price_halalas' => ['required', 'integer', 'min:1', 'max:'.TierSetRequest::MAX_UNIT_PRICE_HALALAS],
            'delivery_address_id' => ['required', 'string', 'uuid'],
        ];
    }
}
