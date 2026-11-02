<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Form requests check that a value is free, but another request can take it
 * before this one commits. This answers that race as the same validation
 * error the form request gives, not as a server error.
 */
final class UniqueViolation
{
    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @param  array<string, string>  $fields  Unique index name => the request field it guards.
     * @return T
     */
    public static function asValidationError(Closure $write, array $fields): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            foreach ($fields as $index => $field) {
                if (str_contains($e->getMessage(), $index)) {
                    throw ValidationException::withMessages([$field => [__('validation.unique', ['attribute' => $field])]]);
                }
            }

            throw $e;
        }
    }
}
