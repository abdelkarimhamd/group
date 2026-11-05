<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Orders\Http\Requests\AdminListReturnsRequest;
use App\Modules\Orders\Http\Requests\ReturnApprovalRequest;
use App\Modules\Orders\Http\Requests\ReturnRejectionRequest;
use App\Modules\Orders\Http\Requests\ReturnRequest;
use App\Modules\Orders\Http\Resources\OrderReturnResource;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Services\Returns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class AdminReturnController extends AdminController
{
    public function __construct(private readonly Returns $returns) {}

    /**
     * Newest first.
     */
    public function index(AdminListReturnsRequest $request): JsonResponse
    {
        $page = OrderReturn::query()
            ->with(OrderReturnResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, OrderReturnResource::class);
    }

    public function store(ReturnRequest $request, string $order): JsonResponse
    {
        $return = $this->returns->record($order, $request->validated(), $this->admin($request));

        return OrderReturnResource::make($return->load(OrderReturnResource::RELATIONS))->response()->setStatusCode(201);
    }

    public function approve(ReturnApprovalRequest $request, string $return): OrderReturnResource
    {
        $approved = $this->returns->approve($return, $request->validated(), $this->admin($request));

        return OrderReturnResource::make($approved->fresh(OrderReturnResource::RELATIONS));
    }

    public function reject(ReturnRejectionRequest $request, string $return): OrderReturnResource
    {
        $rejected = $this->returns->reject($return, $request->validated(), $this->admin($request));

        return OrderReturnResource::make($rejected->fresh(OrderReturnResource::RELATIONS));
    }
}
