<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;

final class AdminVerifyRequest extends ApiRequest
{
    protected array $digitFields = ['code'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'uuid'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }
}
