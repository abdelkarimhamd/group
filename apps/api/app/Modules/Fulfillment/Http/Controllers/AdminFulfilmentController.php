<?php

namespace App\Modules\Fulfillment\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Modules\Fulfillment\Couriers\CourierGateway;
use App\Modules\Fulfillment\Http\Requests\InboundReceiptRequest;
use App\Modules\Fulfillment\Http\Requests\ReceiptPhotoRequest;
use App\Modules\Fulfillment\Http\Requests\ShipmentBookingRequest;
use App\Modules\Fulfillment\Http\Requests\ShipmentCancelRequest;
use App\Modules\Fulfillment\Http\Requests\ShipmentEventRequest;
use App\Modules\Fulfillment\Http\Requests\ShipmentRequest;
use App\Modules\Fulfillment\Http\Resources\AdminShipmentResource;
use App\Modules\Fulfillment\Http\Resources\InboundReceiptResource;
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

        return AdminShipmentResource::make($shipment->load('events'))->response()->setStatusCode(201);
    }

    public function book(ShipmentBookingRequest $request, string $order): JsonResponse
    {
        $shipment = $this->shipments->book($order, $request->validated(), $this->admin($request));

        return AdminShipmentResource::make($shipment->load('events'))->response()->setStatusCode(202);
    }

    public function cancel(ShipmentCancelRequest $request, string $shipment): AdminShipmentResource
    {
        $cancelled = $this->shipments->cancel($shipment, $request->validated('reason'), $this->admin($request));

        return AdminShipmentResource::make($cancelled->load('events'));
    }

    public function track(ShipmentEventRequest $request, string $shipment): JsonResponse
    {
        $updated = $this->shipments->addEvent($shipment, $request->validated(), $this->admin($request));

        return AdminShipmentResource::make($updated->load('events'))->response()->setStatusCode(201);
    }

    /**
     * Which courier API is set up, so the portal offers booking only when
     * it can work.
     */
    public function courier(CourierGateway $courier): JsonResponse
    {
        return response()->json(['data' => [
            'name' => $courier->name(),
            'books' => $courier->books(),
            'tracks' => $courier->name() !== null,
        ]]);
    }
}
