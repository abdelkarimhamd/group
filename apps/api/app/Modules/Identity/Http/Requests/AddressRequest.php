<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `AddressInput` schema: Saudi National Address fields. Used for create
 * and for update (an update sends the whole address).
 */
final class AddressRequest extends ApiRequest
{
    protected array $phoneFields = ['recipient_phone'];

    protected array $digitFields = ['building_no', 'postal_code', 'additional_no', 'short_address'];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($short = $this->input('short_address'))) {
            $this->merge(['short_address' => strtoupper(str_replace(' ', '', $short))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', $this->saudiMobileRule()],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'street' => ['required', 'string', 'max:150'],
            'building_no' => ['required', 'string', 'regex:/^[0-9]{4}$/'],
            'postal_code' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'additional_no' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'short_address' => ['nullable', 'string', 'regex:/^[A-Z]{4}[0-9]{4}$/'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
