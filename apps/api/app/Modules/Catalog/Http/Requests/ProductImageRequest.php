<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Http\Requests\ApiRequest;

final class ProductImageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:'.(int) config('groupbuy.media.image_max_kb')],
            'product_variant_id' => ['nullable', 'string', 'uuid'],
            'alt_ar' => ['nullable', 'string', 'max:200'],
            'alt_en' => ['nullable', 'string', 'max:200'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
