<?php

namespace App\Http\Resources;

use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Cursor;

/**
 * The contract's list envelope: {"data": [...], "meta": {"next_cursor", "per_page"}}.
 */
final class CursorPage
{
    /**
     * @param  CursorPaginator<int, mixed>  $page
     * @param  class-string<JsonResource>  $resource
     */
    public static function response(CursorPaginator $page, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($page->items()),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /**
     * Validation rules for the `cursor` and `per_page` query parameters. A
     * cursor must decode to exactly the columns the list orders by: an
     * integer `id` and, for lists sorted by something else first, a string
     * for each other column. Anything else is refused here instead of
     * reaching the database.
     *
     * @param  list<string>  $columns  The list's order columns, ending with `id`.
     * @return array<string, list<mixed>>
     */
    public static function rules(array $columns = ['id']): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:500', self::validCursor($columns)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    private static function validCursor(array $columns): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($columns): void {
            $cursor = is_string($value) ? Cursor::fromEncoded($value) : null;
            $parameters = $cursor?->toArray() ?? [];
            unset($parameters['_pointsToNextItems']);

            $keys = array_keys($parameters);
            sort($keys);
            $expected = $columns;
            sort($expected);

            $valid = $cursor !== null && $keys === $expected && is_int($parameters['id'] ?? null);

            foreach ($columns as $column) {
                if ($column !== 'id' && ! is_string($parameters[$column] ?? null)) {
                    $valid = false;
                }
            }

            if (! $valid) {
                $fail(__('validation.regex', ['attribute' => $attribute]));
            }
        };
    }
}
