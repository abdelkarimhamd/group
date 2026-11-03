<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Finance\Enums\ApprovalStatus;
use Illuminate\Validation\Rule;

final class AdminListApprovalRequestsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ApprovalStatus::class)],
            ...CursorPage::rules(),
        ];
    }
}
