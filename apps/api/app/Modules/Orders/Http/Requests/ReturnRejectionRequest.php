<?php

namespace App\Modules\Orders\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `ReturnRejectionInput` schema.
 */
final class ReturnRejectionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
