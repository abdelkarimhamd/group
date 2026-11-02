<?php

namespace App\Modules\Campaign\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CursorPage;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Http\Requests\ListCampaignsRequest;
use App\Modules\Campaign\Http\Resources\CampaignDetailResource;
use App\Modules\Campaign\Http\Resources\CampaignSummaryResource;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\Progress;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * What customers see of campaigns. Drafts, campaigns awaiting approval and
 * campaigns cancelled before approval are never shown.
 */
final class CampaignController extends Controller
{
    public function index(ListCampaignsRequest $request): JsonResponse
    {
        $statuses = match ($request->filter()) {
            'open' => [CampaignStatus::Active],
            'upcoming' => [CampaignStatus::Scheduled],
            'ended' => CampaignStatus::ended(),
        };

        $page = self::visible()
            ->with(CampaignSummaryResource::RELATIONS)
            ->whereIn('status', $statuses)
            ->when($request->validated('category'), fn (Builder $query, string $slug) => $query->whereIn(
                'product_id',
                Product::query()->select('id')->whereIn('category_id', Category::query()->select('id')->where('slug', $slug)),
            ))
            ->orderBy($request->orderColumn(), $request->orderDirection())
            ->orderBy('id', $request->orderDirection())
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, CampaignSummaryResource::class);
    }

    public function show(string $campaign): CampaignDetailResource
    {
        return CampaignDetailResource::make(
            self::visible()->with(CampaignDetailResource::RELATIONS)->where('uuid', $campaign)->firstOrFail(),
        );
    }

    /**
     * Polled by the campaign page; cached for at most 2 seconds.
     */
    public function progress(string $campaign): JsonResponse
    {
        $data = Cache::remember(
            'campaign-progress:'.$campaign,
            (int) config('groupbuy.campaigns.progress_cache_seconds'),
            // Plain arrays only: a real cache store serializes what it keeps,
            // and value objects such as Money would not come back whole.
            fn (): ?array => ($model = self::visible()->with('currentTiers')->where('uuid', $campaign)->first()) === null
                ? null
                : json_decode(json_encode(Progress::of($model), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        );

        abort_if($data === null, 404);

        return response()->json(['data' => $data]);
    }

    /**
     * @return Builder<Campaign>
     */
    private static function visible(): Builder
    {
        return Campaign::query()
            ->whereIn('status', CampaignStatus::visibleToCustomers())
            ->whereNotNull('approved_at');
    }
}
