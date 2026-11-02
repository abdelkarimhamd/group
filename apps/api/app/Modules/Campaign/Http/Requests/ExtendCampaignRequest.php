<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;

final class ExtendCampaignRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_deadline_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
