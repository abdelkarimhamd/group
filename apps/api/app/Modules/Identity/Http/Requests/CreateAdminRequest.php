<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Identity\Enums\AdminRole;
use Illuminate\Validation\Rule;

final class CreateAdminRequest extends ApiRequest
{
    protected array $phoneFields = ['phone'];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($email = $this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:191', Rule::unique('admin_users', 'email')],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', $this->saudiMobileRule()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::enum(AdminRole::class)],
            'password' => ['required', 'string', 'min:12', 'max:200'],
        ];
    }
}
