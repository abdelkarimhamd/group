<?php

namespace App\Modules\Fulfillment\Http\Requests;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

/**
 * The `InboundReceiptInput` schema: one line per variant of the purchase
 * order, with damaged units counted among those received.
 */
final class InboundReceiptRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_name' => ['required', 'string', 'max:100'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:product_variant_id,qty_received,qty_damaged'],
            'lines.*.product_variant_id' => ['required', 'string', 'uuid', 'distinct'],
            'lines.*.qty_received' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lines.*.qty_damaged' => ['required', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('lines', []) as $i => $line) {
                    if (is_array($line) && is_int($line['qty_damaged'] ?? null) && is_int($line['qty_received'] ?? null)
                        && $line['qty_damaged'] > $line['qty_received']) {
                        $validator->errors()->add("lines.{$i}.qty_damaged", __('validation.lte.numeric', ['attribute' => 'qty_damaged', 'value' => $line['qty_received']]));
                    }
                }
            },
        ];
    }
}
