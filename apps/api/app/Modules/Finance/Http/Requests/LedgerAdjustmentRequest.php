<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Services\LedgerAdjustments;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The `LedgerAdjustmentInput` schema: balanced lines, or a posting to reverse.
 */
final class LedgerAdjustmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:3', 'max:255'],
            'reverses_transaction_id' => ['nullable', 'string', 'uuid', 'required_without:lines', 'prohibits:lines'],
            'lines' => ['nullable', 'array', 'min:2', 'max:20', 'required_without:reverses_transaction_id'],
            'lines.*' => ['array:account,direction,amount_halalas'],
            'lines.*.account' => ['required', 'string', Rule::enum(LedgerAccountCode::class)],
            'lines.*.direction' => ['required', 'string', 'in:D,C'],
            'lines.*.amount_halalas' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('lines') === null) {
                    return;
                }

                if (! LedgerAdjustments::balanced($this->lines())) {
                    $validator->errors()->add('lines', __('messages.errors.ledger_unbalanced'));
                }
            },
        ];
    }

    /**
     * @return list<array{account: string, direction: 'D'|'C', amount_halalas: int}>
     */
    public function lines(): array
    {
        return array_values(array_map(fn (array $line) => [
            'account' => (string) $line['account'],
            'direction' => $line['direction'] === 'D' ? 'D' : 'C',
            'amount_halalas' => (int) $line['amount_halalas'],
        ], (array) $this->input('lines', [])));
    }
}
