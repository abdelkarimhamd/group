<?php

namespace App\Modules\Supplier\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Supplier\Enums\SupplierStatus;
use Illuminate\Validation\Rule;

final class ListSuppliersRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(SupplierStatus::class)],
            ...CursorPage::rules(),
        ];
    }
}
