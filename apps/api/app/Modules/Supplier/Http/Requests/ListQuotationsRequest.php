<?php

namespace App\Modules\Supplier\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Supplier\Enums\QuotationStatus;
use Illuminate\Validation\Rule;

final class ListQuotationsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'string', 'uuid'],
            'product_id' => ['sometimes', 'string', 'uuid'],
            'status' => ['sometimes', Rule::enum(QuotationStatus::class)],
            ...CursorPage::rules(),
        ];
    }
}
