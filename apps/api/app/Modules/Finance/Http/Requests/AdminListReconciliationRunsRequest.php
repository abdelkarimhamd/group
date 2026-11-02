<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;

final class AdminListReconciliationRunsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CursorPage::rules();
    }
}
