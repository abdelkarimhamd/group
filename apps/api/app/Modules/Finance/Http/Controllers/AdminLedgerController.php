<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Finance\Http\Requests\AdminListLedgerAdjustmentsRequest;
use App\Modules\Finance\Http\Requests\AdminListLedgerTransactionsRequest;
use App\Modules\Finance\Http\Requests\LedgerAdjustmentRequest;
use App\Modules\Finance\Http\Resources\LedgerAdjustmentResource;
use App\Modules\Finance\Http\Resources\LedgerTransactionResource;
use App\Modules\Finance\Models\LedgerAccount;
use App\Modules\Finance\Models\LedgerAdjustment;
use App\Modules\Finance\Models\LedgerEntry;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Finance\Services\LedgerAdjustments;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The double-entry ledger (spec, E15): balances, postings, and hand-made
 * adjustments that wait for a second admin.
 */
final class AdminLedgerController extends AdminController
{
    /**
     * Every account with its balance, debits minus credits.
     */
    public function accounts(): JsonResponse
    {
        $sums = LedgerEntry::query()
            ->selectRaw("ledger_account_id, COALESCE(SUM(amount_halalas) FILTER (WHERE direction = 'D'), 0) AS debits, COALESCE(SUM(amount_halalas) FILTER (WHERE direction = 'C'), 0) AS credits")
            ->groupBy('ledger_account_id')
            ->get()
            ->keyBy('ledger_account_id');

        return response()->json([
            'data' => LedgerAccount::query()->orderBy('id')->get()->map(function (LedgerAccount $account) use ($sums): array {
                $debits = (int) $sums->get($account->id)?->getAttribute('debits');
                $credits = (int) $sums->get($account->id)?->getAttribute('credits');

                return [
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'debits' => Money::sar($debits),
                    'credits' => Money::sar($credits),
                    'balance' => Money::sar($debits - $credits),
                ];
            })->all(),
        ]);
    }

    public function transactions(AdminListLedgerTransactionsRequest $request): JsonResponse
    {
        $page = LedgerTransaction::query()
            ->with(LedgerTransactionResource::RELATIONS)
            ->when($request->validated('type'), fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($request->validated('account'), fn (Builder $query, string $account) => $query->whereHas(
                'entries',
                fn (Builder $entries) => $entries->whereIn('ledger_account_id', LedgerAccount::query()->select('id')->where('code', $account)),
            ))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, LedgerTransactionResource::class);
    }

    public function adjustments(AdminListLedgerAdjustmentsRequest $request): JsonResponse
    {
        $page = LedgerAdjustment::query()
            ->with(LedgerAdjustmentResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, LedgerAdjustmentResource::class);
    }

    /**
     * Asks for balanced lines to be posted, or for a posting to be
     * reversed; a second admin with the finance role approves it.
     */
    public function requestAdjustment(LedgerAdjustmentRequest $request, LedgerAdjustments $adjustments): JsonResponse
    {
        $by = $this->admin($request);
        $reverses = $request->validated('reverses_transaction_id');
        $adjustment = $reverses === null
            ? $adjustments->requestPosting($request->validated('description'), $request->lines(), $by)
            : $adjustments->requestReversal($reverses, $request->validated('description'), $by);

        return LedgerAdjustmentResource::make($adjustment->load(LedgerAdjustmentResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }
}
