<?php

namespace App\Modules\Payments\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Payments\Enums\RefundStatus;
use Illuminate\Validation\Rule;

final class AdminListRefundsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(RefundStatus::class)],
            ...CursorPage::rules(),
        ];
    }
}
