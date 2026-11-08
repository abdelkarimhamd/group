<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Modules\Campaign\Services\Closing;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Enums\LedgerTransactionType;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Finance\Services\Ledger;
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

        // A SAR order's rate stays 1, even when only the rate is sent.
        $this->admin('patch', $uri, ['fx_rate_to_sar' => '3.75'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'sar_rate_must_be_one')
            ->assertJsonValidationErrors('fx_rate_to_sar');

        // Back to dollars: the total must come in dollars, the old rate no
        // longer applies, and the lines keep the currency the supplier quoted.
        $this->admin('patch', $uri, ['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('total_minor');
        $this->admin('patch', $uri, ['currency' => 'USD', 'total_minor' => 11800])
            ->assertOk()
            ->assertJsonPath('data.total.amount_minor', 11800)
            ->assertJsonPath('data.total.currency', 'USD')
            ->assertJsonPath('data.fx_rate_to_sar', null)
            ->assertJsonPath('data.total_sar', null)
            ->assertJsonPath('data.lines.0.unit_cost.amount_minor', 28000)
            ->assertJsonPath('data.lines.0.unit_cost.currency', 'CNY');
        $this->admin('patch', $uri, ['fx_rate_to_sar' => '0'])->assertUnprocessable()->assertJsonValidationErrors('fx_rate_to_sar');
    }

    #[Test]
    public function step_dates_never_go_backwards_and_the_payment_date_can_be_corrected(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $order = SupplierPurchaseOrder::query()->sole();
        $uri = "/api/v1/admin/purchase-orders/{$order->uuid}";
        $this->admin('patch', $uri, ['alibaba_order_ref' => 'ALI-1'])->assertOk();

        // No payment date before the order is paid.
        $this->admin('patch', $uri, ['paid_at' => now()->subDay()->toIso8601ZuluString()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'purchase_order_not_paid')
            ->assertJsonValidationErrors('paid_at');

        $this->advance($order, ['status' => 'placed', 'occurred_at' => now()->subDays(2)->toIso8601ZuluString()])->assertOk();
        $this->advance($order, ['status' => 'paid', 'occurred_at' => now()->subDays(3)->toIso8601ZuluString()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_date_out_of_order')
            ->assertJsonValidationErrors('occurred_at');
        $this->advance($order, ['status' => 'paid', 'occurred_at' => now()->subDay()->toIso8601ZuluString()])->assertOk();

        // Corrected later, but never to before the order was placed.
        $this->admin('patch', $uri, ['paid_at' => now()->subDays(3)->toIso8601ZuluString()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_date_out_of_order');
        $corrected = now()->subHours(30)->startOfSecond()->toIso8601ZuluString();
        $this->admin('patch', $uri, ['paid_at' => $corrected])->assertOk()->assertJsonPath('data.paid_at', $corrected);
        $this->assertSame($corrected, $order->fresh()->paid_at->toIso8601ZuluString());
    }

    #[Test]
    public function paying_the_supplier_is_posted_to_the_ledger_and_later_changes_post_the_difference(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $order = SupplierPurchaseOrder::query()->sole();
        $uri = "/api/v1/admin/purchase-orders/{$order->uuid}";
        $this->admin('patch', $uri, ['alibaba_order_ref' => 'ALI-1', 'total_minor' => 12000])->assertOk();
        $this->advance($order, ['status' => 'placed'])->assertOk();

        // Nothing is posted before the supplier is paid.
        $this->assertSame([], $this->supplierPayments());

        // 120.00 USD at 3.75: 450.00 SAR out of the bank, into goods in transit.
        $this->advance($order, ['status' => 'paid'])->assertOk();
        $this->assertSame([["purchase_order:{$order->uuid}", [['inventory_in_transit', 'D', 45000], ['bank', 'C', 45000]]]], $this->supplierPayments());

        // The supplier's invoice said 118.00 USD at 3.76: the difference goes back.
        $this->admin('patch', $uri, ['total_minor' => 11800, 'fx_rate_to_sar' => '3.76'])->assertOk()->assertJsonPath('data.total_sar.amount_minor', 44368);
        // A change that leaves the SAR value as it is posts nothing.
        $this->admin('patch', $uri, ['freight_forwarder' => 'DHL'])->assertOk();
        $this->assertSame([
            ["purchase_order:{$order->uuid}", [['inventory_in_transit', 'D', 45000], ['bank', 'C', 45000]]],
            ["purchase_order:{$order->uuid}:2", [['inventory_in_transit', 'C', 632], ['bank', 'D', 632]]],
        ], $this->supplierPayments());
        $this->assertSame(44368, app(Ledger::class)->balance(LedgerAccountCode::InventoryInTransit)->minor);

        // Shipping it posts nothing more.
        $this->admin('patch', $uri, ['freight_tracking_ref' => 'MAEU-1'])->assertOk();
        $this->advance($order, ['status' => 'shipped'])->assertOk();
        $this->assertCount(2, $this->supplierPayments());
    }

    #[Test]
    public function an_order_shipped_without_the_payment_step_counts_as_paid(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $order = SupplierPurchaseOrder::query()->sole();
        $this->admin('patch', "/api/v1/admin/purchase-orders/{$order->uuid}", ['alibaba_order_ref' => 'ALI-1', 'freight_tracking_ref' => 'MAEU-1'])->assertOk();

        $this->advance($order, ['status' => 'shipped'])->assertOk();

        $this->assertSame([["purchase_order:{$order->uuid}", [['inventory_in_transit', 'D', $order->fresh()->total_halalas], ['bank', 'C', $order->fresh()->total_halalas]]]], $this->supplierPayments());
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
     * @return list<array{string, list<array{string, string, int}>}>
     */
    private function supplierPayments(): array
    {
        return LedgerTransaction::query()
            ->where('type', LedgerTransactionType::SupplierPayment)
            ->with('entries.account')
            ->orderBy('id')
            ->get()
            ->map(fn (LedgerTransaction $transaction) => [
                $transaction->reference,
                $transaction->entries->sortBy('id')->map(fn ($entry) => [$entry->account->code, $entry->direction, $entry->amount_halalas])->values()->all(),
            ])
            ->all();
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
