<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;

final class ListCampaignEventsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CursorPage::rules();
    }
}
