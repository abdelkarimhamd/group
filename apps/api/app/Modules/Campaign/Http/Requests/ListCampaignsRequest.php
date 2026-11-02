<?php

namespace App\Modules\Campaign\Http\Requests;

use App\Http\Requests\ApiRequest;
use App\Http\Resources\CursorPage;
use Illuminate\Validation\Rule;

/**
 * Customer campaign list: `open` (active, soonest deadline first),
 * `upcoming` (scheduled, soonest start first) or `ended` (most recent
 * deadline first). A cursor only works with the filter that issued it.
 */
final class ListCampaignsRequest extends ApiRequest
{
    public const ORDER = [
        'open' => ['deadline_at', 'asc'],
        'upcoming' => ['starts_at', 'asc'],
        'ended' => ['deadline_at', 'desc'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', Rule::in(array_keys(self::ORDER))],
            'category' => ['sometimes', 'string', 'max:120'],
            ...CursorPage::rules([$this->orderColumn(), 'id']),
        ];
    }

    public function filter(): string
    {
        $filter = $this->query('filter', 'open');

        return is_string($filter) && isset(self::ORDER[$filter]) ? $filter : 'open';
    }

    public function orderColumn(): string
    {
        return self::ORDER[$this->filter()][0];
    }

    public function orderDirection(): string
    {
        return self::ORDER[$this->filter()][1];
    }
}
