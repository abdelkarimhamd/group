<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;

final class RequestOtpRequest extends ApiRequest
{
    protected array $phoneFields = ['phone'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', $this->saudiMobileRule()],
        ];
    }
}
