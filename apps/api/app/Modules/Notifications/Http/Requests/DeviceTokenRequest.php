<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class DeviceTokenRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::in(['ios', 'android', 'web'])],
            'token' => ['required', 'string', 'max:4096'],
        ];
    }
}
