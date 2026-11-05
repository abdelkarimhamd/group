<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Finance\Http\Requests\AdminListSettlementsRequest;
use App\Modules\Finance\Http\Resources\MoyasarSettlementLineResource;
use App\Modules\Finance\Http\Resources\MoyasarSettlementResource;
use App\Modules\Finance\Models\MoyasarSettlement;
use App\Modules\Finance\Models\MoyasarSettlementLine;
use Illuminate\Http\JsonResponse;

/**
 * Moyasar's payouts to the bank, imported daily by payments:settlements,
 * with the fees they kept.
 */
final class AdminSettlementController extends AdminController
{
    public function index(AdminListSettlementsRequest $request): JsonResponse
    {
        $page = MoyasarSettlement::query()
            ->with('posting')
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, MoyasarSettlementResource::class);
    }

    public function lines(AdminListSettlementsRequest $request, string $settlement): JsonResponse
    {
        $found = MoyasarSettlement::query()->where('uuid', $settlement)->firstOrFail();

        $page = MoyasarSettlementLine::query()
            ->with('payment')
            ->where('moyasar_settlement_id', $found->id)
            ->orderBy('id')
            ->cursorPaginate((int) $request->validated('per_page', 50));

        return CursorPage::response($page, MoyasarSettlementLineResource::class);
    }
}
