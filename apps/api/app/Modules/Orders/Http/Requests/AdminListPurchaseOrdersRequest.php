<?php

namespace App\Modules\Orders\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use Illuminate\Validation\Rule;

final class AdminListPurchaseOrdersRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(PurchaseOrderStatus::class)],
            ...CursorPage::rules(),
        ];
    }
}
