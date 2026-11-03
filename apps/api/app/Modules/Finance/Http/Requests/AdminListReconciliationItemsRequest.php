<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;

final class AdminListReconciliationItemsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A query string carries true and false as words.
            'resolved' => ['sometimes', 'in:true,false,1,0'],
            ...CursorPage::rules(),
        ];
    }

    public function resolvedFilter(): ?bool
    {
        $value = $this->validated('resolved');

        return $value === null ? null : in_array($value, ['true', '1'], true);
    }
}
