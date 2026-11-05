<?php

namespace Tests\Concerns;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Fulfillment\Services\Receipts;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Orders\Services\PurchaseOrders;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * Orders taken to the warehouse door, for tests of the last mile
 * (shipments, couriers, returns).
 */
trait FulfilsOrders
{
    use ClosesCampaigns;

    /** @var array<int, string> customer tokens by order ID */
    protected array $customerTokens = [];

    /**
     * Joins of these sizes, captured, bought, shipped by the supplier and
     * counted at the warehouse on 2026-11-20: their orders are ready to
     * ship, oldest first.
     *
     * @param  list<int>  $quantities
     * @return list<CustomerOrder>
     */
    protected function readyOrders(Campaign $campaign, array $quantities): array
    {
        $joins = [];

        foreach ($quantities as $qty) {
            [$join, $token] = $this->paidJoin($campaign, $qty);
            $joins[$join->id] = $token;
        }

        $this->captureAll($campaign);
        $purchaseOrder = SupplierPurchaseOrder::query()->where('campaign_id', $campaign->id)->sole();
        [$admin] = $this->adminWithToken(AdminRole::Operations);
        $purchasing = app(PurchaseOrders::class);
        $purchasing->update($purchaseOrder->uuid, ['alibaba_order_ref' => 'ALI-1', 'freight_tracking_ref' => 'MAEU-1'], $admin);
        $purchasing->advance($purchaseOrder->uuid, PurchaseOrderStatus::Placed, null, null, $admin);
        $purchasing->advance($purchaseOrder->uuid, PurchaseOrderStatus::Shipped, null, null, $admin);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
        app(Receipts::class)->record($purchaseOrder->uuid, [
            'warehouse_name' => 'Riyadh pilot warehouse',
            'received_at' => '2026-11-20T08:00:00Z',
            'lines' => [['product_variant_id' => $campaign->variants()->firstOrFail()->uuid, 'qty_received' => array_sum($quantities), 'qty_damaged' => 0]],
        ], $admin);

        $orders = CustomerOrder::query()->whereIn('participation_id', array_keys($joins))->orderBy('id')->get();

        foreach ($orders as $order) {
            $this->customerTokens[$order->id] = $joins[$order->participation_id];
        }

        return $orders->all();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function asAdmin(string $method, string $uri, array $body = [], AdminRole $role = AdminRole::Operations): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    protected function asCustomer(CustomerOrder $order): TestResponse
    {
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($this->customerTokens[$order->id])->getJson("/api/v1/me/orders/{$order->uuid}");
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
