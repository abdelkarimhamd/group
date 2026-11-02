<?php

namespace App\Modules\Orders\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Orders\Enums\OrderStatus;
use Illuminate\Validation\Rule;

final class AdminListOrdersRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'campaign_id' => ['sometimes', 'string', 'uuid'],
            'q' => ['sometimes', 'string', 'max:50'],
            ...CursorPage::rules(),
        ];
    }
}
