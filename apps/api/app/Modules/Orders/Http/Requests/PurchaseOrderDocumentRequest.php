<?php

namespace App\Modules\Orders\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Orders\Enums\PurchaseOrderDocumentKind;
use Illuminate\Validation\Rule;

final class PurchaseOrderDocumentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpeg,png', 'max:'.(int) config('groupbuy.media.attachment_max_kb')],
            'kind' => ['required', 'string', Rule::enum(PurchaseOrderDocumentKind::class)],
        ];
    }
}
