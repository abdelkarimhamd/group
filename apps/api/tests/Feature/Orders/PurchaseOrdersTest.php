<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Modules\Campaign\Services\Closing;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\TestCase;

/**
 * Supplier purchase orders (spec, E12): the closing job creates one per
 * successful campaign for the captured units, and admins record the
 * Alibaba order and move it forward until it ships.
 */
class PurchaseOrdersTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
    }

    #[Test]
    public function a_successful_campaign_gets_one_purchase_order_for_its_captured_units(): void
    {
        $red = $this->campaign->variants()->firstOrFail();
        $blue = ProductVariant::factory()->for($this->campaign->product)->create();
        $this->campaign->variants()->attach($blue->id);
        $this->paidJoin($this->campaign, 2);
        $this->paidJoin($this->campaign, 1);
        $this->paidJoin($this->campaign, 2, overrides: ['product_variant_id' => $blue->uuid]);

        $this->captureAll($this->campaign);

        $order = SupplierPurchaseOrder::query()->sole();
        $this->assertMatchesRegularExpression('/^PO-2611-\d{4}$/', $order->po_number);
        // Five units at the 40.00 USD the supplier reconfirmed, at the quote's 3.75.
        $this->assertSame(
            ['draft', 20000, 'USD', '3.75000000', 75000, $this->campaign->supplier_quotation_id],
            [$order->status->value, $order->total_minor, $order->currency->value, $order->fx_rate_to_sar, $order->total_halalas, $order->supplier_quotation_id],
        );
        $this->assertSame([[$red->id, 3, 4000], [$blue->id, 2, 4000]], $order->lines->map(fn ($line) => [$line->product_variant_id, $line->qty, $line->unit_cost_minor])->all());
        $this->assertSame([null, 'draft', 'captures_completed'], $this->history($order)[0]);

        // Re-running the closing job never makes a second one; nor can anything else.
        app(Closing::class)->run($this->campaign->id);
        $this->assertSame(1, SupplierPurchaseOrder::count());
        try {
            DB::transaction(fn () => SupplierPurchaseOrder::create($order->only(['campaign_id', 'supplier_id', 'supplier_quotation_id', 'total_minor', 'currency']) + ['po_number' => 'PO-2611-9999']));
            $this->fail('A second purchase order was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
        }

        $this->admin('get', '/api/v1/admin/purchase-orders?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->uuid);
        $this->admin('get', '/api/v1/admin/purchase-orders?status=paid')->assertOk()->assertJsonCount(0, 'data');
        $this->admin('get', '/api/v1/admin/purchase-orders?status=lost')->assertUnprocessable();
        $this->admin('get', "/api/v1/admin/purchase-orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.campaign.code', $this->campaign->code)
            ->assertJsonPath('data.supplier.name', $order->supplier->name)
            ->assertJsonPath('data.total.amount_minor', 20000)
            ->assertJsonPath('data.total.currency', 'USD')
            ->assertJsonPath('data.total_sar.amount_minor', 75000)
            ->assertJsonPath('data.lines.1.product_variant.id', $blue->uuid)
            ->assertJsonPath('data.lines.1.qty', 2)
            ->assertJsonPath('data.lines.1.unit_cost.amount_minor', 4000)
            ->assertJsonPath('data.receipts', []);
    }

    #[Test]
    public function admins_record_the_alibaba_order_and_move_it_forward_until_it_ships(): void
    {
        $this->paidJoin($this->campaign, 2);
        $this->paidJoin($this->campaign, 1);
        $this->captureAll($this->campaign);
        $order = SupplierPurchaseOrder::query()->sole();
        $uri = "/api/v1/admin/purchase-orders/{$order->uuid}";

        // Not without the Alibaba order number.
        $this->advance($order, ['status' => 'placed'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'purchase_order_incomplete')
            ->assertJsonValidationErrors('alibaba_order_ref');

        $this->admin('patch', $uri, ['alibaba_order_ref' => 'ALI-778899', 'total_minor' => 13000, 'freight_forwarder' => 'Aramex Freight'])
            ->assertOk()
            ->assertJsonPath('data.alibaba_order_ref', 'ALI-778899')
            ->assertJsonPath('data.total.amount_minor', 13000)
            ->assertJsonPath('data.total_sar.amount_minor', 48750)
            ->assertJsonPath('data.status', 'draft');
        $this->admin('patch', $uri, [])->assertUnprocessable()->assertJsonValidationErrors('alibaba_order_ref');

        // Placed yesterday: the customers' orders are now being processed.
        $this->advance($order, ['status' => 'placed', 'occurred_at' => '2026-11-08T10:00:00Z', 'note' => 'Trade assurance order'])
            ->assertOk()
            ->assertJsonPath('data.status', 'placed')
            ->assertJsonPath('data.placed_at', '2026-11-08T10:00:00Z');
        $this->assertSame(['processing', 'processing'], CustomerOrder::query()->orderBy('id')->pluck('status')->map->value->all());
        $customerOrder = CustomerOrder::query()->firstOrFail();
        $this->assertSame(['confirmed', 'processing', 'purchase_order_placed'], $this->history($customerOrder)[1]);
        $this->assertSame(['note' => 'Trade assurance order'], StatusTransition::query()->where('subject_type', 'supplier_purchase_order')->where('to_status', 'placed')->sole()->metadata);
        $this->assertNotNull($order->refresh()->placed_by_admin_id);

        $this->advance($order, ['status' => 'placed'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->advance($order, ['status' => 'paid', 'occurred_at' => '2099-01-01T00:00:00Z'])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->advance($order, ['status' => 'received'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->admin('patch', $uri, ['alibaba_order_ref' => null])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'purchase_order_incomplete');

        // Paid without a separate supplier confirmation: steps can be skipped.
        $this->advance($order, ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.paid_at', now()->toIso8601ZuluString())
            ->assertJsonPath('data.supplier_confirmed_at', null);

        // Shipping needs the freight tracking reference, then the campaign moves on.
        $this->advance($order, ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('freight_tracking_ref');
        $this->admin('patch', $uri, ['freight_tracking_ref' => 'MAEU-123456', 'eta' => '2026-12-01'])->assertOk()->assertJsonPath('data.eta', '2026-12-01');
        $this->advance($order, ['status' => 'shipped'])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.shipped_at', now()->toIso8601ZuluString());

        $this->campaign->refresh();
        $this->assertSame('fulfilling', $this->campaign->status->value);
        $event = CampaignEvent::query()->where('campaign_id', $this->campaign->id)->where('event_type', 'supplier_shipped')->sole();
        $this->assertSame([$order->uuid, 'MAEU-123456', 'purchasing', 'fulfilling'], [$event->payload['purchase_order_id'], $event->payload['freight_tracking_ref'], $event->from_status->value, $event->to_status->value]);

        $this->admin('patch', $uri, ['freight_tracking_ref' => null])->assertUnprocessable()->assertJsonValidationErrors('freight_tracking_ref');
        $this->advance($order, ['status' => 'supplier_confirmed'])->assertStatus(409);

        $this->assertSame(
            [null, 'draft', 'placed', 'paid', 'shipped'],
            StatusTransition::query()->where('subject_type', 'supplier_purchase_order')->orderBy('id')->pluck('to_status')->prepend(null)->all(),
        );
        $this->assertSame(3, AuditLog::query()->where('action', 'purchase_order.advance')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'purchase_order.update')->count());
    }

    #[Test]
    public function an_order_in_another_currency_than_the_quote_waits_for_its_rate(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign, ['unit_cost_minor' => 28000, 'currency' => 'CNY']);
        $order = SupplierPurchaseOrder::query()->sole();
        $uri = "/api/v1/admin/purchase-orders/{$order->uuid}";

        $this->admin('get', $uri)
            ->assertOk()
            ->assertJsonPath('data.total.amount_minor', 84000)
            ->assertJsonPath('data.total.currency', 'CNY')
            ->assertJsonPath('data.fx_rate_to_sar', null)
            ->assertJsonPath('data.total_sar', null);

        $this->admin('patch', $uri, ['alibaba_order_ref' => 'ALI-1'])->assertOk();
        $this->advance($order, ['status' => 'placed'])->assertUnprocessable()->assertJsonValidationErrors('fx_rate_to_sar');

        $this->admin('patch', $uri, ['fx_rate_to_sar' => '0.52345678'])
            ->assertOk()
            ->assertJsonPath('data.fx_rate_to_sar', '0.52345678')
            ->assertJsonPath('data.total_sar.amount_minor', 43970);

        // Paid in SAR in the end: the rate is 1, and no other rate is accepted.
        $this->admin('patch', $uri, ['currency' => 'SAR', 'fx_rate_to_sar' => '2'])->assertUnprocessable()->assertJsonValidationErrors('fx_rate_to_sar');
        $this->admin('patch', $uri, ['currency' => 'SAR', 'total_minor' => 160000])
            ->assertOk()
            ->assertJsonPath('data.fx_rate_to_sar', '1.00000000')
            ->assertJsonPath('data.total_sar.amount_minor', 160000);

        // Back to dollars: the old rate no longer applies.
        $this->admin('patch', $uri, ['currency' => 'USD'])->assertOk()->assertJsonPath('data.fx_rate_to_sar', null)->assertJsonPath('data.total_sar', null);
        $this->admin('patch', $uri, ['fx_rate_to_sar' => '0'])->assertUnprocessable()->assertJsonValidationErrors('fx_rate_to_sar');
    }

    #[Test]
    public function purchase_orders_need_their_permissions_and_an_open_order(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $order = SupplierPurchaseOrder::query()->sole();
        $uri = "/api/v1/admin/purchase-orders/{$order->uuid}";

        $this->admin('get', '/api/v1/admin/purchase-orders', AdminRole::Support)->assertForbidden();
        $this->admin('get', $uri, AdminRole::Finance)->assertOk();
        $this->admin('patch', $uri, ['alibaba_order_ref' => 'ALI-1'], AdminRole::Finance)->assertForbidden();
        $this->admin('post', "{$uri}/status", ['status' => 'placed'], AdminRole::Finance)->assertForbidden();
        $this->admin('patch', $uri, ['alibaba_order_ref' => 'ALI-1'], AdminRole::Sourcing)->assertOk();
        $this->admin('get', '/api/v1/admin/purchase-orders/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->admin('post', '/api/v1/admin/purchase-orders/00000000-0000-4000-8000-000000000000/status', ['status' => 'placed'])->assertNotFound();

        // A cancelled order (finance cancels with the campaign, E15) no longer changes.
        $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $this->admin('patch', $uri, ['freight_forwarder' => 'DHL'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->advance($order, ['status' => 'placed'])->assertStatus(409);
    }

    #[Test]
    public function a_campaign_must_be_purchasing_for_its_order_to_move(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $order = SupplierPurchaseOrder::query()->sole();
        $this->admin('patch', "/api/v1/admin/purchase-orders/{$order->uuid}", ['alibaba_order_ref' => 'ALI-1'])->assertOk();

        $this->campaign->forceFill(['status' => 'cancelled', 'end_reason' => 'admin_cancelled'])->save();

        $this->advance($order, ['status' => 'placed'])->assertStatus(409);
        $this->assertSame('draft', $order->refresh()->status->value);
        $this->assertSame('confirmed', CustomerOrder::query()->sole()->status->value);
    }

    private function advance(SupplierPurchaseOrder $order, array $body): TestResponse
    {
        return $this->admin('post', "/api/v1/admin/purchase-orders/{$order->uuid}/status", $body);
    }

    /**
     * @param  array<string, mixed>|AdminRole  $body  or the role, for a request without a body
     */
    private function admin(string $method, string $uri, array|AdminRole $body = [], AdminRole $role = AdminRole::Operations): TestResponse
    {
        if ($body instanceof AdminRole) {
            [$role, $body] = [$body, []];
        }

        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    /**
     * @return list<array{string|null, string, string|null}>
     */
    private function history(SupplierPurchaseOrder|CustomerOrder $subject): array
    {
        return StatusTransition::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->id)
            ->orderBy('id')
            ->get()
            ->map(fn (StatusTransition $row) => [$row->from_status, $row->to_status, $row->reason])
            ->all();
    }
}
