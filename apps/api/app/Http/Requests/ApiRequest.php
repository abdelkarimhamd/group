<?php

namespace App\Http\Requests;

use App\Support\Phone\SaudiMobile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for API form requests. Normalizes Saudi mobile numbers and Arabic-Indic
 * digits before validation, so `٠٥١٢٣٤٥٦٧٨` and `0512345678` both arrive as
 * +966512345678.
 */
abstract class ApiRequest extends FormRequest
{
    /**
     * Fields holding a Saudi mobile number.
     *
     * @var list<string>
     */
    protected array $phoneFields = [];

    /**
     * Fields holding digits that people may type in Arabic-Indic numerals.
     *
     * @var list<string>
     */
    protected array $digitFields = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ($this->phoneFields as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                // An unrecognised number is kept as typed so the error names it.
                $normalized[$field] = SaudiMobile::normalize($value) ?? $value;
            }
        }

        foreach ($this->digitFields as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = SaudiMobile::asciiDigits($value);
            }
        }

        $this->merge($normalized);
    }

    /**
     * Validation rule for a normalized Saudi mobile number.
     */
    protected function saudiMobileRule(): string
    {
        return 'regex:'.SaudiMobile::PATTERN;
    }
}
