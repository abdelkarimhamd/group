<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CursorPage;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Http\Requests\ListMyOrdersRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\CustomerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MyOrderController extends Controller
{
    /**
     * My orders, newest first. An order exists once its hold is captured.
     */
    public function index(ListMyOrdersRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $page = CustomerOrder::query()
            ->where('user_id', $user->id)
            ->with(OrderResource::RELATIONS)
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, OrderResource::class);
    }

    public function show(Request $request, string $order): OrderResource
    {
        /** @var User $user */
        $user = $request->user();

        return OrderResource::make(
            CustomerOrder::query()->where('user_id', $user->id)->where('uuid', $order)->with(OrderResource::RELATIONS)->firstOrFail(),
        );
    }
}
