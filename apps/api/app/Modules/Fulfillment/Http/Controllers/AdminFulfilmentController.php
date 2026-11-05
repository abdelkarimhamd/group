<?php

namespace App\Modules\Fulfillment\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Modules\Fulfillment\Http\Requests\InboundReceiptRequest;
use App\Modules\Fulfillment\Http\Requests\ReceiptPhotoRequest;
use App\Modules\Fulfillment\Http\Requests\ShipmentEventRequest;
use App\Modules\Fulfillment\Http\Requests\ShipmentRequest;
use App\Modules\Fulfillment\Http\Resources\InboundReceiptResource;
use App\Modules\Fulfillment\Http\Resources\ShipmentResource;
use App\Modules\Fulfillment\Services\ReceiptPhotos;
use App\Modules\Fulfillment\Services\Receipts;
use App\Modules\Fulfillment\Services\Shipments;
use Illuminate\Http\JsonResponse;

final class AdminFulfilmentController extends AdminController
{
    public function __construct(
        private readonly Receipts $receipts,
        private readonly Shipments $shipments,
        private readonly ReceiptPhotos $photos,
    ) {}

    public function receive(InboundReceiptRequest $request, string $purchaseOrder): JsonResponse
    {
        $receipt = $this->receipts->record($purchaseOrder, $request->validated(), $this->admin($request));

        return InboundReceiptResource::make($receipt->load(InboundReceiptResource::RELATIONS))->response()->setStatusCode(201);
    }

    public function addPhoto(ReceiptPhotoRequest $request, string $receipt): JsonResponse
    {
        $updated = $this->photos->add($receipt, $request->file('file'), $this->admin($request));

        return InboundReceiptResource::make($updated->load(InboundReceiptResource::RELATIONS))->response()->setStatusCode(201);
    }

    public function ship(ShipmentRequest $request, string $order): JsonResponse
    {
        $shipment = $this->shipments->create($order, $request->validated(), $this->admin($request));

        return ShipmentResource::make($shipment->load('events'))->response()->setStatusCode(201);
    }

    public function track(ShipmentEventRequest $request, string $shipment): JsonResponse
    {
        $updated = $this->shipments->addEvent($shipment, $request->validated(), $this->admin($request));

        return ShipmentResource::make($updated->load('events'))->response()->setStatusCode(201);
    }
}
