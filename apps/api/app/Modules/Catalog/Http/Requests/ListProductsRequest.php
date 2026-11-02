<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Catalog\Enums\ProductStatus;
use Illuminate\Validation\Rule;

final class ListProductsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            ...CursorPage::rules(),
        ];
    }
}
