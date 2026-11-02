<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `ReasonInput` schema.
 */
final class ReasonRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
