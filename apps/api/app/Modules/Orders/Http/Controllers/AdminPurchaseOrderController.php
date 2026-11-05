<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Orders\Enums\PurchaseOrderDocumentKind;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Orders\Http\Requests\AdminListPurchaseOrdersRequest;
use App\Modules\Orders\Http\Requests\AdvancePurchaseOrderRequest;
use App\Modules\Orders\Http\Requests\PurchaseOrderDocumentRequest;
use App\Modules\Orders\Http\Requests\PurchaseOrderRequest;
use App\Modules\Orders\Http\Resources\PurchaseOrderResource;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Orders\Services\PurchaseOrderDocuments;
use App\Modules\Orders\Services\PurchaseOrders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminPurchaseOrderController extends AdminController
{
    public function __construct(
        private readonly PurchaseOrders $purchaseOrders,
        private readonly PurchaseOrderDocuments $documents,
    ) {}

    /**
     * Newest first.
     */
    public function index(AdminListPurchaseOrdersRequest $request): JsonResponse
    {
        $page = SupplierPurchaseOrder::query()
            ->with(PurchaseOrderResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, PurchaseOrderResource::class);
    }

    public function show(string $purchaseOrder): PurchaseOrderResource
    {
        return $this->resource($purchaseOrder);
    }

    public function update(PurchaseOrderRequest $request, string $purchaseOrder): PurchaseOrderResource
    {
        $this->purchaseOrders->update($purchaseOrder, $request->validated(), $this->admin($request));

        return $this->resource($purchaseOrder);
    }

    public function advance(AdvancePurchaseOrderRequest $request, string $purchaseOrder): PurchaseOrderResource
    {
        $occurredAt = $request->validated('occurred_at');

        $this->purchaseOrders->advance(
            $purchaseOrder,
            PurchaseOrderStatus::from($request->validated('status')),
            $occurredAt === null ? null : CarbonImmutable::parse($occurredAt),
            $request->validated('note'),
            $this->admin($request),
        );

        return $this->resource($purchaseOrder);
    }

    public function attachDocument(PurchaseOrderDocumentRequest $request, string $purchaseOrder): JsonResponse
    {
        $this->documents->attach(
            $purchaseOrder,
            $request->file('file'),
            PurchaseOrderDocumentKind::from($request->validated('kind')),
            $this->admin($request),
        );

        return $this->resource($purchaseOrder)->response()->setStatusCode(201);
    }

    public function removeDocument(Request $request, string $purchaseOrder, string $document): PurchaseOrderResource
    {
        $this->documents->remove($purchaseOrder, $document, $this->admin($request));

        return $this->resource($purchaseOrder);
    }

    private function resource(string $uuid): PurchaseOrderResource
    {
        return PurchaseOrderResource::make(
            SupplierPurchaseOrder::query()->where('uuid', $uuid)->with(PurchaseOrderResource::RELATIONS)->firstOrFail(),
        );
    }
}
