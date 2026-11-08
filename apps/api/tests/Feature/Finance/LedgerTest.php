<?php

namespace Tests\Feature\Finance;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Enums\LedgerTransactionType;
use App\Modules\Finance\Models\LedgerEntry;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Orders\Services\PurchaseOrders;
use App\Modules\Payments\Models\Refund;
use App\Support\Actor;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\TestCase;

/**
 * The double-entry ledger (spec, E15): every money event posted as
 * balanced entries that are never changed.
 */
class LedgerTest extends TestCase
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
    public function capture_delivery_and_a_later_refund_are_posted_as_balanced_entries(): void
    {
        [$join] = $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $order = CustomerOrder::query()->sole();

        // Charged 360.00 SAR including 15% VAT.
        $this->assertSame([
            ['moyasar_clearing', 'D', 36000], ['customer_prepayments', 'C', 31304], ['vat_output_payable', 'C', 4696],
        ], $this->entries(LedgerTransactionType::Capture));
        $capture = LedgerTransaction::query()->sole();
        $this->assertSame(["payment:{$join->latestPayment->uuid}", $order->id, 'system'], [$capture->reference, $capture->customer_order_id, $capture->actor_type]);

        // Delivered: the prepayment becomes revenue.
        $this->deliver($order);
        $this->assertSame([['customer_prepayments', 'D', 31304], ['sales_revenue', 'C', 31304]], $this->entries(LedgerTransactionType::RevenueRecognition));

        // A refund after delivery comes out of revenue.
        $approval = $this->admin('post', '/api/v1/admin/refunds', ['payment_id' => $join->latestPayment->uuid, 'amount_halalas' => 12000, 'reason' => 'defective'], AdminRole::Support)
            ->assertCreated()->json('data.approval_request_id');
        $this->admin('post', "/api/v1/admin/approval-requests/{$approval}/approve")->assertOk();
        $this->assertSame('succeeded', Refund::query()->sole()->status->value);
        $this->assertSame([
            ['sales_revenue', 'D', 10435], ['vat_output_payable', 'D', 1565], ['moyasar_clearing', 'C', 12000],
        ], $this->entries(LedgerTransactionType::Refund));

        $ledger = app(Ledger::class);
        $this->assertSame([24000, 0, -3131, -20869], [
            $ledger->balance(LedgerAccountCode::MoyasarClearing)->minor,
            $ledger->balance(LedgerAccountCode::CustomerPrepayments)->minor,
            $ledger->balance(LedgerAccountCode::VatOutputPayable)->minor,
            $ledger->balance(LedgerAccountCode::SalesRevenue)->minor,
        ]);

        // Every transaction balances, and posting an event again posts nothing.
        foreach (LedgerTransaction::query()->with('entries')->get() as $transaction) {
            $this->assertSame(
                $transaction->entries->where('direction', 'D')->sum('amount_halalas'),
                $transaction->entries->where('direction', 'C')->sum('amount_halalas'),
            );
        }
        $again = DB::transaction(fn () => $ledger->recogniseRevenue($order->refresh(), Actor::system()));
        $this->assertSame(LedgerTransaction::query()->where('type', 'revenue_recognition')->value('id'), $again?->id);
        // Shipping the supplier's order on the way to delivery posted its payment.
        $this->assertSame(
            ['capture', 'refund', 'revenue_recognition', 'supplier_payment'],
            LedgerTransaction::query()->pluck('type')->map->value->sort()->values()->all(),
        );
    }

    #[Test]
    public function the_ledger_refuses_entries_that_do_not_balance_and_any_change(): void
    {
        $ledger = app(Ledger::class);

        try {
            DB::transaction(fn () => $ledger->post(LedgerTransactionType::Adjustment, 'test:1', 'Unbalanced', [
                [LedgerAccountCode::Bank, 'D', Money::sar(100)],
                [LedgerAccountCode::MoyasarClearing, 'C', Money::sar(99)],
            ], Actor::system()));
            $this->fail('An unbalanced posting was accepted.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('does not balance', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        DB::transaction(fn () => $ledger->post(LedgerTransactionType::Capture, 'test:2', 'Outside the rules', [
            [LedgerAccountCode::Bank, 'D', Money::sar(-100)],
            [LedgerAccountCode::MoyasarClearing, 'C', Money::sar(-100)],
        ], Actor::system()));
    }

    #[Test]
    public function the_database_itself_keeps_the_ledger_balanced_and_unchanged(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $transaction = LedgerTransaction::query()->sole();

        // Entries that do not balance are refused when the transaction commits.
        try {
            DB::transaction(function () use ($transaction): void {
                DB::table('ledger_entries')->insert([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => DB::table('ledger_accounts')->where('code', 'bank')->value('id'),
                    'direction' => 'D',
                    'amount_halalas' => 1,
                ]);
                // In tests nothing commits, so check now.
                DB::statement('SET CONSTRAINTS ledger_entries_balanced IMMEDIATE');
            });
            $this->fail('An entry that unbalances its transaction was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('does not balance', $e->getMessage());
        }

        foreach ([
            'UPDATE ledger_entries SET amount_halalas = amount_halalas + 1',
            'DELETE FROM ledger_entries',
            "UPDATE ledger_transactions SET description = 'rewritten'",
            'DELETE FROM ledger_transactions',
        ] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("The database allowed: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        // A hand-made adjustment needs an approval; the models refuse changes too.
        try {
            DB::transaction(fn () => app(Ledger::class)->post(LedgerTransactionType::Adjustment, 'test:3', 'No approval', [
                [LedgerAccountCode::Bank, 'D', Money::sar(100)],
                [LedgerAccountCode::MoyasarClearing, 'C', Money::sar(100)],
            ], Actor::system()));
            $this->fail('An adjustment without an approval was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ledger_transactions_adjustment_check', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        LedgerEntry::query()->firstOrFail()->update(['amount_halalas' => 1]);
    }

    /**
     * The entries of the only transaction of a type, as [account, direction, amount].
     *
     * @return list<array{string, string, int}>
     */
    private function entries(LedgerTransactionType $type): array
    {
        return LedgerEntry::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_transaction_id', LedgerTransaction::query()->where('type', $type)->sole()->id)
            ->orderBy('ledger_entries.id')
            ->get(['ledger_accounts.code', 'ledger_entries.direction', 'ledger_entries.amount_halalas'])
            ->map(fn ($entry) => [$entry->code, $entry->direction, (int) $entry->amount_halalas])
            ->all();
    }

    /**
     * The supplier ships, the warehouse counts every unit, the courier delivers.
     */
    private function deliver(CustomerOrder $order): void
    {
        [$admin] = $this->adminWithToken(AdminRole::Operations);
        $purchaseOrder = SupplierPurchaseOrder::query()->sole();
        $orders = app(PurchaseOrders::class);
        $orders->update($purchaseOrder->uuid, ['alibaba_order_ref' => 'ALI-1', 'freight_tracking_ref' => 'MAEU-1'], $admin);
        $orders->advance($purchaseOrder->uuid, PurchaseOrderStatus::Placed, null, null, $admin);
        $orders->advance($purchaseOrder->uuid, PurchaseOrderStatus::Shipped, null, null, $admin);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));

        $variant = $this->campaign->variants()->firstOrFail();
        $this->admin('post', "/api/v1/admin/purchase-orders/{$purchaseOrder->uuid}/receipts", [
            'warehouse_name' => 'Riyadh pilot warehouse',
            'received_at' => '2026-11-20T08:00:00Z',
            'lines' => [['product_variant_id' => $variant->uuid, 'qty_received' => $order->qty, 'qty_damaged' => 0]],
        ], AdminRole::Operations)->assertCreated();
        $shipment = $this->admin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", ['courier' => 'SMSA', 'tracking_number' => 'SMSA-1'], AdminRole::Operations)
            ->assertCreated()->json('data.id');
        $this->travel(3)->hours();
        $this->admin('post', "/api/v1/admin/shipments/{$shipment}/events", ['status' => 'delivered', 'occurred_at' => '2026-11-20T11:30:00Z'], AdminRole::Operations)
            ->assertCreated();
        $this->assertSame('delivered', $order->refresh()->status->value);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function admin(string $method, string $uri, array $body = [], AdminRole $role = AdminRole::Finance): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
