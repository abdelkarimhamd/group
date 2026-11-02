<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Http\Requests\ApiRequest;

final class VariantRequest extends ApiRequest
{
    /** Fields an update resets when the client leaves them out. */
    public const CLEARED_IF_MISSING = [
        'option_values' => [], 'weight_grams' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null,
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return VariantRules::for(ignoreVariantUuid: $this->route('variant'));
    }
}
