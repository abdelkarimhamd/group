<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;

final class PrivacyConsentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'policy_version' => ['required', 'string', 'max:20'],
        ];
    }
}
