<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;

final class ResolveReconciliationItemRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
