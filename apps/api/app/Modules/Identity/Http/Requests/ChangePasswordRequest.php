<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;

final class ChangePasswordRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'different:current_password'],
        ];
    }
}
