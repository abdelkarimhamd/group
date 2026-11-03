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
     * Polled by the campaign page, and read after every Reverb (re)connect to
     * catch up on missed pushes; cached for at most 2 seconds. A push stores
     * what it sends in the same entry first, so the catch-up read is never
     * older than a push sent before it.
     */
    public function progress(string $campaign): JsonResponse
    {
        $key = Progress::cacheKey($campaign);
        $data = Cache::get($key);

        if ($data === null) {
            $model = self::visible()->with('currentTiers')->where('uuid', $campaign)->first();
            abort_if($model === null, 404);

            $data = Progress::payload($model);
            // add, not put: never replace what a push stored meanwhile, which
            // clients may already show.
            Cache::add($key, $data, (int) config('groupbuy.campaigns.progress_cache_seconds'));
        }

        return response()->json(['data' => $data]);
    }

    /**
     * @return Builder<Campaign>
     */
    private static function visible(): Builder
    {
        return Campaign::visibleToCustomers();
    }
}
