<?php

namespace Tests\Feature\Orders;

use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\Closing;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Services\Invoices;
use App\Modules\Orders\Support\ZatcaQr;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Jobs\CapturePayment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\Captures;
use App\Support\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * Orders and VAT invoices (spec, Orders and invoices): one order per
 * captured join, made with its simplified tax invoice when the money is taken.
 */
class OrdersTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        config(['groupbuy.seller.legal_name_ar' => 'شركة الشراء الجماعي', 'groupbuy.seller.vat_number' => '310123456700003']);
        $this->campaign = $this->closableCampaign();
    }

    #[Test]
    public function every_captured_join_gets_an_order_and_a_tax_invoice_at_the_final_price(): void
    {
        [$two] = $this->paidJoin($this->campaign, 2);
        $this->paidJoin($this->campaign, 1);
        [$late] = $this->paidJoin($this->campaign, 1);
        $this->assertSame(10500, $late->hold_unit_price_halalas);

        $this->captureAll($this->campaign);

        $this->assertSame(3, CustomerOrder::count());
        $order = CustomerOrder::query()->where('participation_id', $two->id)->sole();
        $this->assertMatchesRegularExpression('/^ORD-2611-\d{6}$/', $order->order_number);
        $this->assertSame(
            ['confirmed', 2, 10500, 21000, 0, 21000, 2739, 1500],
            [$order->status->value, $order->qty, $order->unit_price_halalas, $order->goods_total_halalas, $order->delivery_fee_halalas, $order->total_halalas, $order->vat_halalas, $order->vat_rate_bp],
        );
        $this->assertSame($two->latestPayment->captured_amount_halalas, $order->total_halalas);
        $this->assertSame($two->delivery_address, $order->shipping_address);
        $this->assertSame([null, 'confirmed'], [
            StatusTransition::query()->where('subject_type', 'customer_order')->where('subject_id', $order->id)->sole()->from_status,
            StatusTransition::query()->where('subject_type', 'customer_order')->where('subject_id', $order->id)->sole()->to_status,
        ]);

        // The invoice: VAT-inclusive lines, each split with the spec's rounding rule.
        $invoice = $order->taxInvoice()->sole();
        $this->assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $invoice->invoice_number);
        $this->assertSame([18261, 2739, 21000, 'pending'], [$invoice->net_halalas, $invoice->vat_halalas, $invoice->total_halalas, $invoice->zatca_status->value]);
        // jsonb does not keep key order.
        $this->assertEquals([[
            'description_ar' => trim($this->campaign->product->name_ar.' - '.$two->variant->name_ar),
            'description_en' => trim($this->campaign->product->name_en.' - '.$two->variant->name_en),
            'qty' => 2,
            'unit_price_halalas' => 10500,
            'net_halalas' => 18261,
            'vat_halalas' => 2739,
            'total_halalas' => 21000,
            'vat_rate_bp' => 1500,
        ]], $invoice->lines);
        $this->assertSame('310123456700003', $invoice->seller_snapshot['vat_number']);

        // The QR code a simplified tax invoice must carry.
        $this->assertSame([
            1 => 'شركة الشراء الجماعي',
            2 => '310123456700003',
            3 => '2026-11-09T09:00:00Z',
            4 => '210.00',
            5 => '27.39',
        ], ZatcaQr::decode($invoice->qr_payload));
    }

    #[Test]
    public function the_customer_sees_their_orders_and_invoices(): void
    {
        [$mine, $token] = $this->paidJoin($this->campaign, 3);
        [, $other] = $this->paidJoin($this->campaign, 1);
        $this->captureAll($this->campaign);
        $order = CustomerOrder::query()->where('participation_id', $mine->id)->sole();
        $invoice = $order->taxInvoice()->sole();

        $this->asCustomer($token)->getJson('/api/v1/me/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->uuid)
            ->assertJsonPath('meta.next_cursor', null);

        $this->asCustomer($token)->getJson("/api/v1/me/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.unit_price.amount_minor', 10500)
            ->assertJsonPath('data.total.amount_minor', 31500)
            ->assertJsonPath('data.vat.amount_minor', $order->vat_halalas)
            ->assertJsonPath('data.payment.status', 'captured')
            ->assertJsonPath('data.payment.captured_amount.amount_minor', 31500)
            ->assertJsonPath('data.shipping_address.city', $mine->delivery_address['city'])
            ->assertJsonPath('data.shipment', null)
            ->assertJsonPath('data.invoices.0.id', $invoice->uuid)
            ->assertJsonPath('data.invoices.0.type', 'simplified_tax_invoice')
            ->assertJsonPath('data.invoices.0.total.amount_minor', 31500)
            ->assertJsonPath('data.invoices.0.pdf_url', null);

        // The join points at its order.
        $this->asCustomer($token)->getJson("/api/v1/me/participations/{$mine->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'captured')
            ->assertJsonPath('data.order_id', $order->uuid);

        // Nobody else's order is visible.
        $this->asCustomer($other)->getJson("/api/v1/me/orders/{$order->uuid}")->assertNotFound();
        $this->forgetAuthenticatedUsers();
        $this->withoutToken()->getJson('/api/v1/me/orders')->assertUnauthorized();
    }

    #[Test]
    public function capturing_again_never_makes_a_second_order(): void
    {
        [$join] = $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);

        CapturePayment::dispatchSync($join->latestPayment->id);
        app(Closing::class)->run($this->campaign->id);

        $this->assertSame(1, CustomerOrder::count());
        $this->assertSame(1, Invoice::count());
    }

    #[Test]
    public function an_issued_invoice_cannot_be_changed_or_deleted(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $invoice = Invoice::query()->sole();

        foreach ([
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total_halalas' => 1, 'net_halalas' => 1, 'vat_halalas' => 0]),
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['invoice_number' => 'INV-2026-999999']),
            fn () => DB::table('invoices')->where('id', $invoice->id)->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('The change was accepted.');
            } catch (QueryException $e) {
                $this->assertSame('23001', $e->getCode(), $e->getMessage());
            }
        }

        // Reporting to ZATCA still moves on.
        DB::table('invoices')->where('id', $invoice->id)->update(['zatca_status' => 'submitted']);
        $this->assertSame('submitted', $invoice->refresh()->zatca_status->value);
    }

    private function asCustomer(string $token): static
    {
        $this->forgetAuthenticatedUsers();

        return $this->withToken($token);
    }

    #[Test]
    public function no_money_is_taken_in_production_while_the_seller_of_record_is_a_placeholder(): void
    {
        config(['groupbuy.seller.legal_name_en' => 'Group Buying Co.']);
        $this->assertNull(Invoices::sellerProblem());

        foreach ([
            ['groupbuy.seller.vat_number' => '300000000000003'],
            ['groupbuy.seller.vat_number' => '31012345670000'],
            ['groupbuy.seller.legal_name_ar' => 'اسم البائع غير محدد'],
            ['groupbuy.seller.legal_name_en' => ' '],
        ] as $placeholder) {
            $configured = config('groupbuy.seller');
            config($placeholder);
            $this->assertStringContainsString('GROUPBUY_SELLER_VAT_NUMBER', (string) Invoices::sellerProblem(), (string) json_encode($placeholder));
            config(['groupbuy.seller' => $configured]);
        }

        // In production a capture stops before anything is written or sent.
        $gateway = new ScriptedMoyasarGateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        [$join] = $this->paidJoin($this->campaign, 1);
        config(['groupbuy.seller.vat_number' => '300000000000003']);
        $this->app['env'] = 'production';

        try {
            app(Captures::class)->capture($join->latestPayment()->firstOrFail(), Actor::system());
            $this->fail('A capture was attempted with the placeholder seller.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('GROUPBUY_SELLER_VAT_NUMBER', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame([], $gateway->captures);
        $this->assertSame(0, PaymentTransaction::query()->where('idempotency_key', 'like', 'capture:%')->count());
    }
}
