<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Finance\Enums\LedgerAdjustmentStatus;
use Illuminate\Validation\Rule;

final class AdminListLedgerAdjustmentsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(LedgerAdjustmentStatus::class)],
            ...CursorPage::rules(),
        ];
    }
}
