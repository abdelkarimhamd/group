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
     * Validation rules for the `cursor` and `per_page` query parameters. Every
     * list pages by `id`, so a cursor must decode to an integer id; anything
     * else is refused here instead of reaching the database.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:500', self::validCursor()],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    private static function validCursor(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $cursor = is_string($value) ? Cursor::fromEncoded($value) : null;
            $parameters = $cursor?->toArray() ?? [];
            unset($parameters['_pointsToNextItems']);

            if ($cursor === null || array_keys($parameters) !== ['id'] || ! is_int($parameters['id'])) {
                $fail(__('validation.regex', ['attribute' => $attribute]));
            }
        };
    }
}
