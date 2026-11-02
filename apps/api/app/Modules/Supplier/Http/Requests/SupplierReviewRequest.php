<?php

namespace App\Modules\Supplier\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Modules\Supplier\Enums\SupplierStatus;
use Illuminate\Validation\Rule;

/**
 * The `SupplierReviewInput` schema: the decision, with an optional rating and
 * notes. Fields left out keep their current values.
 */
final class SupplierReviewRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(SupplierStatus::class)],
            'quality_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'review_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
