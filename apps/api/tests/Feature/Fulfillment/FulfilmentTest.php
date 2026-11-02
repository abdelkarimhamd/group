<?php

namespace Tests\Feature\Fulfillment;

use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Orders\Services\PurchaseOrders;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\TestCase;

/**
 * Warehouse and last mile (spec, E13): goods counted at the warehouse make
 * orders ready to ship, each order gets one tracked shipment, delivery
 * starts the return window, and the campaign completes when nothing is
 * left on its way.
 */
class FulfilmentTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    private SupplierPurchaseOrder $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
    }

    #[Test]
    public function goods_counted_at_the_warehouse_make_the_orders_they_cover_ready_to_ship(): void
    {
        [$first, $second, $third] = $this->shippedToWarehouse([2, 1, 2]);
        $variant = $this->campaign->variants()->firstOrFail();

        // Four of five arrived and one of those is broken: three good units.
        $this->receive([['product_variant_id' => $variant->uuid, 'qty_received' => 4, 'qty_damaged' => 1]], ['notes' => 'One box crushed'])
            ->assertCreated()
            ->assertJsonPath('data.warehouse_name', 'Riyadh pilot warehouse')
            ->assertJsonPath('data.received_by.type', 'admin')
            ->assertJsonPath('data.notes', 'One box crushed')
            ->assertJsonPath('data.lines.0.product_variant_id', $variant->uuid)
            ->assertJsonPath('data.lines.0.qty_expected', 5)
            ->assertJsonPath('data.lines.0.qty_missing', 1)
            ->assertJsonPath('data.photo_urls', []);

        // Oldest first: the first two orders are covered, the third waits for a refund.
        $this->assertSame(['ready_to_ship', 'ready_to_ship', 'processing'], array_map(fn ($join) => $this->orderOf($join)->status->value, [$first, $second, $third]));
        $this->assertSame(['processing', 'ready_to_ship', 'goods_received'], $this->history($this->orderOf($first))[2]);
        $this->assertSame(['received', '2026-11-20T08:00:00Z'], [$this->purchaseOrder->refresh()->status->value, $this->purchaseOrder->received_at->toIso8601ZuluString()]);
        $event = CampaignEvent::query()->where('campaign_id', $this->campaign->id)->where('event_type', 'goods_received')->sole();
        $this->assertSame([1, 1, [$this->orderOf($third)->order_number]], [$event->payload['missing'], $event->payload['damaged'], $event->payload['orders_waiting']]);

        $this->admin('get', "/api/v1/admin/purchase-orders/{$this->purchaseOrder->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.receipts.0.lines.0.qty_damaged', 1);

        // Counted once.
        $this->receive([['product_variant_id' => $variant->uuid, 'qty_received' => 5, 'qty_damaged' => 0]])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    #[Test]
    public function a_receipt_counts_every_variant_of_the_purchase_order_once(): void
    {
        $blue = ProductVariant::factory()->for($this->campaign->product)->create();
        $this->campaign->variants()->attach($blue->id);
        $red = $this->campaign->variants()->where('product_variants.id', '!=', $blue->id)->firstOrFail();
        $this->paidJoin($this->campaign, 2);
        $this->paidJoin($this->campaign, 1, overrides: ['product_variant_id' => $blue->uuid]);
        $this->captureAll($this->campaign);
        $this->purchaseOrder = SupplierPurchaseOrder::query()->sole();

        // Not before the supplier has shipped.
        $this->receive(
            [['product_variant_id' => $red->uuid, 'qty_received' => 2, 'qty_damaged' => 0], ['product_variant_id' => $blue->uuid, 'qty_received' => 1, 'qty_damaged' => 0]],
            ['received_at' => now()->subHour()->toIso8601ZuluString()],
        )->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->shipPurchaseOrder();

        $this->receive([['product_variant_id' => $red->uuid, 'qty_received' => 2, 'qty_damaged' => 0]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'receipt_lines_mismatch');
        $this->receive([
            ['product_variant_id' => $red->uuid, 'qty_received' => 2, 'qty_damaged' => 0],
            ['product_variant_id' => $blue->uuid, 'qty_received' => 1, 'qty_damaged' => 0],
            ['product_variant_id' => ProductVariant::factory()->create()->uuid, 'qty_received' => 1, 'qty_damaged' => 0],
        ])->assertUnprocessable()->assertJsonPath('code', 'receipt_lines_mismatch');
        $this->receive([
            ['product_variant_id' => $red->uuid, 'qty_received' => 2, 'qty_damaged' => 3],
            ['product_variant_id' => $red->uuid, 'qty_received' => 1, 'qty_damaged' => 0],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.qty_damaged', 'lines.0.product_variant_id']);
        $this->receive([['product_variant_id' => $red->uuid, 'qty_received' => 2, 'qty_damaged' => 0]], ['received_at' => '2099-01-01T00:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('received_at');

        // Nothing changed until a receipt was accepted.
        $this->assertSame('shipped', $this->purchaseOrder->refresh()->status->value);

        $this->receive([
            ['product_variant_id' => $blue->uuid, 'qty_received' => 1, 'qty_damaged' => 0],
            ['product_variant_id' => $red->uuid, 'qty_received' => 2, 'qty_damaged' => 0],
        ])->assertCreated();
        $this->assertSame(['ready_to_ship', 'ready_to_ship'], CustomerOrder::query()->orderBy('id')->pluck('status')->map->value->all());
    }

    #[Test]
    public function orders_are_shipped_tracked_and_delivered_then_the_campaign_completes(): void
    {
        [$first, $second] = $this->shippedToWarehouse([2, 1]);
        $this->receive([['product_variant_id' => $this->campaign->variants()->firstOrFail()->uuid, 'qty_received' => 3, 'qty_damaged' => 0]])->assertCreated();
        $order = $this->orderOf($first);

        $shipment = $this->ship($order)
            ->assertCreated()
            ->assertJsonPath('data.courier', 'SMSA')
            ->assertJsonPath('data.tracking_number', 'SMSA-0001')
            ->assertJsonPath('data.status', 'picked_up')
            ->assertJsonPath('data.shipped_at', now()->toIso8601ZuluString())
            ->assertJsonPath('data.delivered_at', null)
            ->assertJsonCount(1, 'data.events')
            ->json('data');
        $this->assertSame('shipped', $order->refresh()->status->value);
        $this->ship($order)->assertStatus(409)->assertJsonPath('code', 'shipment_exists');

        // Tracking updates, one arriving late: the newest sets the status.
        $this->travel(1)->days();
        $this->track($shipment['id'], ['status' => 'in_transit', 'location' => 'Riyadh hub', 'occurred_at' => now()->subHour()->toIso8601ZuluString()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_transit');
        $this->track($shipment['id'], ['status' => 'failed_attempt', 'description' => 'Nobody home', 'occurred_at' => now()->subHours(3)->toIso8601ZuluString()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.events.1.status', 'failed_attempt')
            ->assertJsonPath('data.events.2.status', 'in_transit');
        $this->track($shipment['id'], ['status' => 'teleported', 'occurred_at' => now()->toIso8601ZuluString()])->assertUnprocessable();
        $this->track($shipment['id'], ['status' => 'out_for_delivery', 'occurred_at' => now()->addDay()->toIso8601ZuluString()])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

        // The customer follows it.
        $this->forgetAuthenticatedUsers();
        $this->withToken($this->tokens[$first->id])->getJson("/api/v1/me/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.shipment.tracking_number', 'SMSA-0001')
            ->assertJsonPath('data.shipment.events.2.location', 'Riyadh hub');
        $this->forgetAuthenticatedUsers();

        // Delivered: the return window starts; the campaign waits for the other order.
        $deliveredAt = now()->subMinutes(10);
        $this->track($shipment['id'], ['status' => 'delivered', 'occurred_at' => $deliveredAt->toIso8601ZuluString()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.delivered_at', $deliveredAt->toIso8601ZuluString());
        $order->refresh();
        $this->assertSame(['delivered', $deliveredAt->toIso8601ZuluString(), $deliveredAt->addDays(7)->toIso8601ZuluString()], [$order->status->value, $order->delivered_at->toIso8601ZuluString(), $order->return_window_ends_at->toIso8601ZuluString()]);
        $this->assertSame('fulfilling', $this->campaign->refresh()->status->value);
        $this->track($shipment['id'], ['status' => 'returned', 'occurred_at' => now()->toIso8601ZuluString()])->assertStatus(409);

        // The last delivery completes the campaign.
        $other = $this->ship($this->orderOf($second))->assertCreated()->json('data.id');
        $this->track($other, ['status' => 'delivered', 'occurred_at' => now()->toIso8601ZuluString()])->assertCreated();
        $this->assertSame('completed', $this->campaign->refresh()->status->value);
        $this->assertSame(['fulfilling', 'completed'], [
            CampaignEvent::query()->where('campaign_id', $this->campaign->id)->where('event_type', 'completed')->sole()->from_status->value,
            CampaignEvent::query()->where('campaign_id', $this->campaign->id)->where('event_type', 'completed')->sole()->to_status->value,
        ]);

        // Seven days on, the return windows have closed.
        $this->artisan('orders:complete-delivered')->assertSuccessful();
        $this->assertSame(['delivered', 'delivered'], CustomerOrder::query()->orderBy('id')->pluck('status')->map->value->all());
        $this->travelTo($deliveredAt->addDays(7));
        $this->artisan('orders:complete-delivered')->assertSuccessful();
        $this->assertSame(['completed', 'delivered'], CustomerOrder::query()->orderBy('id')->pluck('status')->map->value->all());
        $this->assertSame(['delivered', 'completed', 'return_window_ended'], $this->history($order)[5]);
    }

    #[Test]
    public function only_ready_orders_ship_and_tracking_history_never_changes(): void
    {
        [$first] = $this->shippedToWarehouse([3]);
        $order = $this->orderOf($first);

        $this->ship($order)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->ship($order, ['tracking_url' => 'not a url'])->assertUnprocessable()->assertJsonValidationErrors('tracking_url');
        $this->admin('post', '/api/v1/admin/orders/00000000-0000-4000-8000-000000000000/shipment', ['courier' => 'SMSA', 'tracking_number' => 'X'])->assertNotFound();
        $this->admin('post', '/api/v1/admin/shipments/00000000-0000-4000-8000-000000000000/events', ['status' => 'in_transit', 'occurred_at' => now()->toIso8601ZuluString()])->assertNotFound();

        $this->receive([['product_variant_id' => $this->campaign->variants()->firstOrFail()->uuid, 'qty_received' => 3, 'qty_damaged' => 0]])->assertCreated();
        $this->admin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", ['courier' => 'SMSA', 'tracking_number' => 'X'], AdminRole::Finance)->assertForbidden();
        $this->admin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", ['courier' => 'SMSA', 'tracking_number' => 'X'], AdminRole::Support)->assertForbidden();
        $this->ship($order, ['tracking_url' => 'https://smsaexpress.com/track/SMSA-0001', 'shipping_cost_halalas' => 2500])
            ->assertCreated()
            ->assertJsonPath('data.tracking_url', 'https://smsaexpress.com/track/SMSA-0001');
        $this->assertSame(2500, Shipment::query()->sole()->shipping_cost_halalas);

        foreach ([
            fn () => DB::table('shipment_events')->update(['status' => 'delivered']),
            fn () => DB::table('shipment_events')->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('Tracking history was changed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    /** @var array<int, string> customer tokens by participation ID */
    private array $tokens = [];

    /**
     * Joins of these sizes, captured, bought and shipped by the supplier.
     *
     * @param  list<int>  $quantities
     * @return list<CampaignParticipation>
     */
    private function shippedToWarehouse(array $quantities): array
    {
        $joins = [];
        foreach ($quantities as $qty) {
            [$join, $token] = $this->paidJoin($this->campaign, $qty);
            $this->tokens[$join->id] = $token;
            $joins[] = $join;
        }

        $this->captureAll($this->campaign);
        $this->purchaseOrder = SupplierPurchaseOrder::query()->sole();
        $this->shipPurchaseOrder();

        return $joins;
    }

    private function shipPurchaseOrder(): void
    {
        [$admin] = $this->adminWithToken(AdminRole::Operations);
        $orders = app(PurchaseOrders::class);
        $orders->update($this->purchaseOrder->uuid, ['alibaba_order_ref' => 'ALI-1', 'freight_tracking_ref' => 'MAEU-1'], $admin);
        $orders->advance($this->purchaseOrder->uuid, PurchaseOrderStatus::Placed, null, null, $admin);
        $orders->advance($this->purchaseOrder->uuid, PurchaseOrderStatus::Shipped, null, null, $admin);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function receive(array $lines, array $extra = []): TestResponse
    {
        return $this->admin('post', "/api/v1/admin/purchase-orders/{$this->purchaseOrder->uuid}/receipts", $extra + [
            'warehouse_name' => 'Riyadh pilot warehouse',
            'received_at' => '2026-11-20T08:00:00Z',
            'lines' => $lines,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function ship(CustomerOrder $order, array $extra = []): TestResponse
    {
        return $this->admin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", $extra + ['courier' => 'SMSA', 'tracking_number' => 'SMSA-0001']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function track(string $shipment, array $body): TestResponse
    {
        return $this->admin('post', "/api/v1/admin/shipments/{$shipment}/events", $body);
    }

    private function orderOf(CampaignParticipation $join): CustomerOrder
    {
        return CustomerOrder::query()->where('participation_id', $join->id)->sole();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function admin(string $method, string $uri, array $body = [], AdminRole $role = AdminRole::Operations): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    /**
     * @return list<array{string|null, string, string|null}>
     */
    private function history(CustomerOrder $order): array
    {
        return StatusTransition::query()
            ->where('subject_type', 'customer_order')
            ->where('subject_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(fn (StatusTransition $row) => [$row->from_status, $row->to_status, $row->reason])
            ->all();
    }
}
