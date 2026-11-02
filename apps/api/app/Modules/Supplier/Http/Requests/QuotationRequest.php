<?php

namespace App\Modules\Supplier\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Supplier\Enums\QuotationStatus;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Support\Money\Currency;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The `QuotationInput` schema, for create and update. Update may also set
 * `status` to draft, accepted or rejected; only the scheduler sets expired.
 */
final class QuotationRequest extends ApiRequest
{
    /** Upper bound for any per-unit amount, far above anything real. */
    private const MAX_MINOR = 1_000_000_000_000;

    /** Matches the contract and numeric(18,8). */
    private const FX_PATTERN = '/^[0-9]{1,10}(\.[0-9]{1,8})?$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'supplier_id' => ['required', 'string', 'uuid', Rule::exists('suppliers', 'uuid')],
            'product_id' => ['required', 'string', 'uuid', Rule::exists('products', 'uuid')],
            'product_variant_id' => ['nullable', 'string', 'uuid'],
            'moq' => ['required', 'integer', 'min:1', 'max:10000000'],
            'unit_cost_minor' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MINOR],
            'currency' => ['required', Rule::enum(Currency::class)],
            'shipping_terms' => ['required', 'string', 'max:20'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:730'],
            'valid_until' => ['required', 'date_format:Y-m-d'],
            'fx_rate_to_sar' => ['required', 'string', 'regex:'.self::FX_PATTERN, 'regex:/[1-9]/'],
            'landed_cost_basis_qty' => ['required', 'integer', 'min:1', 'max:10000000'],
            'landed_cost_breakdown' => ['required', 'array'],
        ];

        foreach (SupplierQuotation::BREAKDOWN as $part) {
            $rules["landed_cost_breakdown.{$part}_halalas"] = ['required', 'integer', 'min:0', 'max:'.self::MAX_MINOR];
        }

        if ($this->isMethod('patch')) {
            $rules['status'] = ['sometimes', Rule::in([
                QuotationStatus::Draft->value,
                QuotationStatus::Accepted->value,
                QuotationStatus::Rejected->value,
            ])];
        }

        return $rules;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // A SAR quote needs no conversion.
                if ($this->input('currency') === Currency::SAR->value
                    && ! preg_match('/^0*1(\.0*)?$/', (string) $this->input('fx_rate_to_sar'))) {
                    $validator->errors()->add('fx_rate_to_sar', __('validation.in', ['attribute' => 'fx_rate_to_sar']));
                }

                // A variant-specific quote must name a variant of the quoted product.
                if (($variant = $this->input('product_variant_id')) !== null) {
                    $belongs = ProductVariant::query()
                        ->where('uuid', $variant)
                        ->whereIn('product_id', Product::query()->select('id')->where('uuid', $this->input('product_id')))
                        ->exists();

                    if (! $belongs) {
                        $validator->errors()->add('product_variant_id', __('validation.exists', ['attribute' => 'product_variant_id']));
                    }
                }
            },
        ];
    }

    /**
     * The breakdown keyed by SupplierQuotation::BREAKDOWN, in halalas.
     *
     * @return array<string, int>
     */
    public function breakdown(): array
    {
        $input = $this->validated('landed_cost_breakdown');

        return array_combine(
            SupplierQuotation::BREAKDOWN,
            array_map(fn (string $part) => (int) $input[$part.'_halalas'], SupplierQuotation::BREAKDOWN),
        );
    }
}
