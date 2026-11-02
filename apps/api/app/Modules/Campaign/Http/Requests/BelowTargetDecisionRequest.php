<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class BelowTargetDecisionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['proceed', 'cancel'])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
