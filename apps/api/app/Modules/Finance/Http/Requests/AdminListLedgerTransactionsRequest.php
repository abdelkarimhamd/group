<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Enums\LedgerTransactionType;
use Illuminate\Validation\Rule;

final class AdminListLedgerTransactionsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account' => ['sometimes', 'string', Rule::enum(LedgerAccountCode::class)],
            'type' => ['sometimes', 'string', Rule::enum(LedgerTransactionType::class)],
            ...CursorPage::rules(),
        ];
    }
}
