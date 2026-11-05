<?php

namespace App\Modules\Orders\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Orders\Enums\ReturnReason;
use Illuminate\Validation\Rule;

/**
 * The `ReturnInput` schema.
 */
final class ReturnRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
