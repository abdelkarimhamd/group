<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Payments\Http\Requests\AdminListRefundsRequest;
use App\Modules\Payments\Http\Requests\RefundRequest;
use App\Modules\Payments\Http\Resources\RefundResource;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\Refunds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class AdminRefundController extends AdminController
{
    public function index(AdminListRefundsRequest $request): JsonResponse
    {
        $page = Refund::query()
            ->with(RefundResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, RefundResource::class);
    }

    /**
     * Ask for a refund; it waits for a second admin with the finance role.
     */
    public function store(RefundRequest $request, Refunds $refunds): JsonResponse
    {
        $refund = $refunds->request($request->validated(), $this->admin($request));

        return RefundResource::make($refund->load(RefundResource::RELATIONS))->response()->setStatusCode(201);
    }
}
