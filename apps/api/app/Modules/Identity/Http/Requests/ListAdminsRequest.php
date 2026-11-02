<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Identity\Enums\AdminStatus;
use Illuminate\Validation\Rule;

final class ListAdminsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(AdminStatus::class)],
            ...CursorPage::rules(),
        ];
    }
}
