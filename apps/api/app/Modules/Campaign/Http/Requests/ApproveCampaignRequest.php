<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;

final class ApproveCampaignRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'override_reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
