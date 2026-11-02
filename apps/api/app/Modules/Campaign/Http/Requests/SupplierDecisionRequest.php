<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * The `SupplierDecisionInput` schema. A confirmation carries the supplier's
 * unit cost and the stock it can supply.
 */
final class SupplierDecisionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['confirmed', 'rejected'])],
            'unit_cost_minor' => ['exclude_unless:decision,confirmed', 'required', 'integer', 'min:1', 'max:100000000000'],
            'currency' => ['exclude_unless:decision,confirmed', 'nullable', 'string', Rule::in(['USD', 'CNY', 'SAR'])],
            'available_qty' => ['exclude_unless:decision,confirmed', 'required', 'integer', 'min:0', 'max:10000000'],
            'quotation_reference' => ['exclude_unless:decision,confirmed', 'nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
