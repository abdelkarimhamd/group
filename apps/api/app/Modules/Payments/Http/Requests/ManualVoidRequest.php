<?php

namespace App\Modules\Payments\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `ReasonInput` schema, for asking to release a hold.
 */
final class ManualVoidRequest extends ApiRequest
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
