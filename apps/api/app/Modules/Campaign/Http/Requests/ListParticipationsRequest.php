<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use App\Modules\Campaign\Enums\ParticipationStatus;
use Illuminate\Validation\Rule;

final class ListParticipationsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ParticipationStatus::class)],
            ...CursorPage::rules(),
        ];
    }
}
