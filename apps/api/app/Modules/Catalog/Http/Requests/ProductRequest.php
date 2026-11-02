<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Catalog\Enums\ProductStatus;
use Illuminate\Validation\Rule;

/**
 * The `AdminProductInput` schema. On create, `variants` may come along and
 * `status` is ignored (new products start as draft).
 */
final class ProductRequest extends ApiRequest
{
    /** Fields an update clears when the client leaves them out. */
    public const CLEARED_IF_MISSING = ['description_ar' => null, 'description_en' => null, 'hs_code' => null];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        $rules = [
            'category_id' => ['required', 'string', 'uuid', Rule::exists('categories', 'uuid')],
            'name_ar' => ['required', 'string', 'max:200'],
            'name_en' => ['required', 'string', 'max:200'],
            'description_ar' => ['nullable', 'string', 'max:10000'],
            'description_en' => ['nullable', 'string', 'max:10000'],
            'alibaba_url' => ['required', 'string', 'url:http,https', 'max:2000'],
            'hs_code' => ['nullable', 'string', 'regex:/^[0-9]{6,12}$/'],
        ];

        if (! $creating) {
            $rules['status'] = ['sometimes', Rule::enum(ProductStatus::class)];

            return $rules;
        }

        return [
            ...$rules,
            'variants' => ['sometimes', 'array', 'list', 'max:50'],
            'variants.*' => ['array'],
            ...VariantRules::for('variants.*.', withStatus: false, distinct: true),
        ];
    }
}
