<?php

namespace App\Modules\Finance\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use Closure;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
            // Only subjects with a public ID can be looked up by one.
            'subject_id' => ['sometimes', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                $class = Relation::getMorphedModel((string) $this->input('subject_type'));

                if ($class !== null && ! self::hasPublicId($class)) {
                    $fail('validation.in')->translate();
                }
            }],
            'actor_admin_id' => ['sometimes', 'uuid'],
            'action' => ['sometimes', 'string', 'max:100'],
            ...CursorPage::rules(),
        ];
    }

    /**
     * @param  class-string  $class
     */
    public static function hasPublicId(string $class): bool
    {
        return in_array(HasUuids::class, class_uses_recursive($class), true);
    }
}
