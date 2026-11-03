<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Finance\Http\Requests\AdminListReconciliationItemsRequest;
use App\Modules\Finance\Http\Requests\AdminListReconciliationRunsRequest;
use App\Modules\Finance\Http\Requests\ResolveReconciliationItemRequest;
use App\Modules\Finance\Http\Resources\ReconciliationItemResource;
use App\Modules\Finance\Http\Resources\ReconciliationRunResource;
use App\Modules\Finance\Models\ReconciliationItem;
use App\Modules\Finance\Models\ReconciliationRun;
use App\Modules\Finance\Services\Reconciliation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The daily check of our payments against Moyasar (spec, E15) and the
 * mismatches it found.
 */
final class AdminReconciliationController extends AdminController
{
    public function runs(AdminListReconciliationRunsRequest $request): JsonResponse
    {
        $page = ReconciliationRun::query()
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, ReconciliationRunResource::class);
    }

    public function items(AdminListReconciliationItemsRequest $request, string $run): JsonResponse
    {
        $found = ReconciliationRun::query()->where('uuid', $run)->firstOrFail();
        $resolved = $request->resolvedFilter();

        $page = ReconciliationItem::query()
            ->with(ReconciliationItemResource::RELATIONS)
            ->where('reconciliation_run_id', $found->id)
            ->when($resolved !== null, fn (Builder $query) => $resolved ? $query->whereNotNull('resolved_at') : $query->whereNull('resolved_at'))
            ->orderBy('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, ReconciliationItemResource::class);
    }

    /**
     * Records what was done about a mismatch. Anything that moves money
     * goes through an approval request instead.
     */
    public function resolve(ResolveReconciliationItemRequest $request, string $item, Reconciliation $reconciliation): ReconciliationItemResource
    {
        $resolved = $reconciliation->resolve($item, $request->validated('note'), $this->admin($request));

        return ReconciliationItemResource::make($resolved->load(ReconciliationItemResource::RELATIONS));
    }
}
