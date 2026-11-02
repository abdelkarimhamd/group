<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Campaign\Enums\CampaignStatus;
use Illuminate\Validation\Rule;

final class AdminListCampaignsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(CampaignStatus::class)],
            'needs_attention' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            ...CursorPage::rules(),
        ];
    }
}
