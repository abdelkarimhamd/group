<?php

namespace App\Modules\Supplier\Http\Requests;

use App\Http\Requests\ApiRequest;

final class QuotationAttachmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpeg,png', 'max:'.(int) config('groupbuy.media.attachment_max_kb')],
        ];
    }
}
