<?php

namespace App\Modules\Orders\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `ReturnApprovalInput` schema.
 */
final class ReturnApprovalRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_halalas' => ['required', 'integer', 'min:1', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
