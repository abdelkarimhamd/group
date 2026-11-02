<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Http\Requests\AdminListOrdersRequest;
use App\Modules\Orders\Http\Resources\AdminOrderResource;
use App\Modules\Orders\Models\CustomerOrder;
use App\Support\Phone\SaudiMobile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class AdminOrderController extends AdminController
{
    /**
     * Newest first. `q` matches an order number exactly (any case) or the
     * customer's mobile number in any common Saudi format.
     */
    public function index(AdminListOrdersRequest $request): JsonResponse
    {
        $q = $request->validated('q');

        $page = CustomerOrder::query()
            ->with(AdminOrderResource::RELATIONS)
            ->when($request->validated('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->validated('campaign_id'), fn (Builder $query, string $campaign) => $query->whereIn(
                'campaign_id',
                Campaign::query()->select('id')->where('uuid', $campaign),
            ))
            ->when(is_string($q) && trim($q) !== '', function (Builder $query) use ($q): void {
                $phone = SaudiMobile::normalize($q);
                $query->where(fn (Builder $query) => $query
                    ->where('order_number', strtoupper(trim($q)))
                    ->when($phone !== null, fn (Builder $query) => $query->orWhereIn('user_id', User::query()->select('id')->where('phone_e164', $phone))));
            })
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, AdminOrderResource::class);
    }

    public function show(string $order): AdminOrderResource
    {
        return AdminOrderResource::make(
            CustomerOrder::query()->where('uuid', $order)->with(AdminOrderResource::RELATIONS)->firstOrFail(),
        );
    }
}
