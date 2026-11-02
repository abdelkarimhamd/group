<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Enums\AdminStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAdminRequest extends ApiRequest
{
    protected array $phoneFields = ['phone'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'required', 'string', $this->saudiMobileRule()],
            'roles' => ['sometimes', 'required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::enum(AdminRole::class)],
            'status' => ['sometimes', 'required', Rule::enum(AdminStatus::class)],
            'password' => ['sometimes', 'required', 'string', 'min:12', 'max:200'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['name', 'phone', 'roles', 'status', 'password'])) {
                    $validator->errors()->add('name', __('messages.errors.nothing_to_update'));
                }
            },
        ];
    }
}
