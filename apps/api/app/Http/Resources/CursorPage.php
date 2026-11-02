<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

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
     * Validation rules for the `cursor` and `per_page` query parameters.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:500'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
