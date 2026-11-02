<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

final class UpdateProfileRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:191'],
            'locale' => ['sometimes', 'string', 'in:'.implode(',', config('groupbuy.locales'))],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['name', 'email', 'locale'])) {
                    $validator->errors()->add('name', __('messages.errors.nothing_to_update'));
                }
            },
        ];
    }
}
