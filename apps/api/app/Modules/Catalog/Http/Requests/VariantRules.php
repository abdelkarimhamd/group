<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\VariantStatus;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validation for the `AdminVariantInput` schema, shared by product creation
 * (variants.*) and the variant endpoints.
 */
final class VariantRules
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $prefix = '', ?string $ignoreVariantUuid = null, bool $withStatus = true, bool $distinct = false): array
    {
        $rules = [
            $prefix.'sku' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/',
                ...($distinct ? ['distinct:ignore_case'] : []),
                Rule::unique('product_variants', 'sku')->ignore($ignoreVariantUuid, 'uuid'),
            ],
            $prefix.'name_ar' => ['required', 'string', 'max:100'],
            $prefix.'name_en' => ['required', 'string', 'max:100'],
            $prefix.'option_values' => ['sometimes', 'array', 'max:10', self::optionMap()],
            $prefix.'option_values.*' => ['string', 'max:100'],
            $prefix.'weight_grams' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            $prefix.'length_mm' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            $prefix.'width_mm' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            $prefix.'height_mm' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];

        if ($withStatus) {
            $rules[$prefix.'status'] = ['sometimes', Rule::enum(VariantStatus::class)];
        }

        return $rules;
    }

    /**
     * option_values is a map such as {"color": "black"}, never a list.
     */
    private static function optionMap(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                $fail(__('validation.array', ['attribute' => $attribute]));

                return;
            }

            foreach (array_keys($value) as $key) {
                if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key)) {
                    $fail(__('validation.regex', ['attribute' => $attribute]));

                    return;
                }
            }
        };
    }
}
