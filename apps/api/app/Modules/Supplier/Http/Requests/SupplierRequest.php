<?php

namespace App\Modules\Supplier\Http\Requests;

use App\Http\Requests\ApiRequest;

/**
 * The `SupplierInput` schema, for create and update. Status changes go through
 * the review endpoint (SupplierReviewRequest).
 */
final class SupplierRequest extends ApiRequest
{
    /** Fields an update clears when the client leaves them out. */
    public const CLEARED_IF_MISSING = ['contact_name' => null, 'contact_channel' => null, 'quality_rating' => null, 'review_notes' => null];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($country = $this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($country))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'alibaba_store_url' => ['required', 'string', 'url:http,https', 'max:2000'],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_channel' => ['nullable', 'string', 'max:200'],
            'quality_rating' => ['nullable', 'integer', 'between:1,5'],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
