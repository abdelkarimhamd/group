<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\Rule;

final class AdminListAuditLogsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A subject is named by its public ID, so its type is needed to find it.
            'subject_type' => ['required_with:subject_id', 'string', Rule::in(array_keys(Relation::morphMap()))],
            'subject_id' => ['sometimes', 'uuid'],
            'actor_admin_id' => ['sometimes', 'uuid'],
            'action' => ['sometimes', 'string', 'max:100'],
            ...CursorPage::rules(),
        ];
    }
}
