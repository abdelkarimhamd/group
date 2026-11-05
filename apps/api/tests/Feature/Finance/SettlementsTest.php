<?php

namespace Tests\Feature\Finance;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Finance\Models\MoyasarSettlement;
use App\Modules\Finance\Models\ReconciliationItem;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Payments\Gateway\PaymentReports;
use App\Modules\Payments\Gateway\ProviderPayment;
use App\Modules\Payments\Models\PaymentAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\Fakes\ScriptedPaymentReports;
use Tests\TestCase;

/**
 * Moyasar's payouts to the bank (spec, E15: fees in the ledger), and
 * payments Moyasar has that we do not (reconciliation's missing_locally).
 */
class SettlementsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    /** @var list<PaymentAuthorization> */
    private array $payments;

    private ScriptedPaymentReports $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
        [$first] = $this->paidJoin($this->campaign, 2);
        [$second] = $this->paidJoin($this->campaign, 1);
        $this->captureAll($this->campaign);
        $this->payments = [$first->latestPayment()->firstOrFail(), $second->latestPayment()->firstOrFail()];
        $this->reports = new ScriptedPaymentReports;
        $this->app->instance(PaymentReports::class, $this->reports);
    }

    #[Test]
    public function a_settlement_is_imported_once_and_moves_the_money_to_the_bank(): void
    {
        [$first, $second] = $this->payments;
        $clearing = $this->balance(LedgerAccountCode::MoyasarClearing);
        $this->assertSame($first->captured_amount_halalas + $second->captured_amount_halalas, $clearing);

        // Fees are 2.75% + VAT, rounded by Moyasar; one line names a payment we do not know.
        $this->reports->settle('stl_1', '2026-11-04T06:00:00Z',
            ScriptedPaymentReports::line('payment', $first->provider_payment_id, $first->captured_amount_halalas - 760, 760, 99, $first->captured_amount_halalas),
            ScriptedPaymentReports::line('payment', $second->provider_payment_id, $second->captured_amount_halalas - 380, 380, 50, $second->captured_amount_halalas),
            ScriptedPaymentReports::line('payment', 'pay_unknown', 9620, 380, 50, 10000),
        );

        $this->artisan('payments:settlements')->expectsOutputToContain('Settlements read: 1, imported: 1.')->assertSuccessful();
        $this->artisan('payments:settlements')->expectsOutputToContain('Settlements read: 1, imported: 0.')->assertSuccessful();

        $settlement = MoyasarSettlement::query()->sole();
        $gross = $clearing + 10000;
        $this->assertSame([$gross, 1520, 199, $gross - 1520, 3, 1], [$settlement->gross_halalas, $settlement->fee_halalas, $settlement->fee_vat_halalas, $settlement->net_halalas, $settlement->line_count, $settlement->unmatched_count]);

        // Bank and fees debited, Moyasar clearing credited with the gross.
        $posting = LedgerTransaction::query()->whereKey($settlement->ledger_transaction_id)->sole();
        $this->assertSame(['settlement', 'settlement:stl_1'], [$posting->type->value, $posting->reference]);
        $this->assertSame($gross - 1520, $this->balance(LedgerAccountCode::Bank));
        $this->assertSame(1520 - 199, $this->balance(LedgerAccountCode::PaymentFees));
        $this->assertSame(199, $this->balance(LedgerAccountCode::VatInputRecoverable));
        $this->assertSame(-10000, $this->balance(LedgerAccountCode::MoyasarClearing));

        $page = $this->asAdmin(AdminRole::Finance, 'get', '/api/v1/admin/settlements')
            ->assertOk()
            ->assertJsonPath('data.0.provider_settlement_id', 'stl_1')
            ->assertJsonPath('data.0.net.amount_minor', $gross - 1520)
            ->assertJsonPath('data.0.unmatched_count', 1)
            ->assertJsonPath('data.0.ledger_transaction_id', $posting->uuid)
            ->json('data.0');
        $this->asAdmin(AdminRole::Finance, 'get', "/api/v1/admin/settlements/{$page['id']}/lines")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.payment_id', $first->uuid)
            ->assertJsonPath('data.2.payment_id', null)
            ->assertJsonPath('data.2.provider_payment_id', 'pay_unknown');
        $this->asAdmin(AdminRole::Finance, 'get', '/api/v1/admin/ledger/transactions?type=settlement')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entries.0.account', 'bank');
    }

    #[Test]
    public function every_page_is_read_so_a_settlement_skipped_once_is_imported_later(): void
    {
        foreach (range(1, 5) as $n) {
            $this->reports->settle("stl_{$n}", "2026-10-2{$n}T06:00:00Z", ScriptedPaymentReports::line('payment', 'pay_x', 1000, 0, 0));
        }

        // The lines of stl_4 cannot be read: it is skipped, the older ones are not.
        $this->reports->unreadable = ['stl_4'];
        $this->artisan('payments:settlements')
            ->expectsOutputToContain('Settlements read: 5, imported: 4.')
            ->expectsOutputToContain('Settlements that could not be imported: 1.')
            ->assertFailed();
        $this->assertSame([1, 2, 3], $this->reports->settlementPages);
        $this->assertFalse(MoyasarSettlement::query()->where('provider_settlement_id', 'stl_4')->exists());

        // Nothing new on the first page; stl_4 is on the second and is imported now.
        $this->reports->unreadable = [];
        $this->reports->settlementPages = [];
        $this->artisan('payments:settlements')->expectsOutputToContain('Settlements read: 5, imported: 1.')->assertSuccessful();
        $this->assertSame([1, 2, 3], $this->reports->settlementPages);
        $this->assertSame(5, MoyasarSettlement::count());

        // A settlement with nothing in it is kept without a posting.
        $this->reports->settle('stl_7', '2026-11-05T06:00:00Z');
        $this->artisan('payments:settlements')->expectsOutputToContain('imported: 1.')->assertSuccessful();
        $this->assertNull(MoyasarSettlement::query()->where('provider_settlement_id', 'stl_7')->value('ledger_transaction_id'));
    }

    #[Test]
    public function an_unreachable_moyasar_imports_nothing(): void
    {
        $this->reports->settle('stl_1', '2026-11-04T06:00:00Z', ScriptedPaymentReports::line('payment', 'pay_x', 1000, 0, 0));
        $this->reports->down = true;

        $this->artisan('payments:settlements')->expectsOutputToContain('Moyasar is unreachable.')->assertFailed();
        $this->assertSame(0, MoyasarSettlement::count());
    }

    #[Test]
    public function a_payment_moyasar_took_that_we_have_no_record_of_is_reported(): void
    {
        [$first] = $this->payments;
        $row = fn (string $id, string $status, ?string $ours) => new ProviderPayment($id, $status, 12000, 'SAR', 'creditcard', 'mada', '4242', 0, $ours, ['id' => $id]);
        $this->reports->payments = [
            $row((string) $first->provider_payment_id, 'captured', $first->uuid),
            $row('pay_lost', 'authorized', $first->uuid),
            $row('pay_failed', 'failed', null),
            $row('pay_stranger', 'paid', null),
        ];

        $this->artisan('payments:reconcile', ['--date' => CarbonImmutable::now('Asia/Riyadh')->toDateString()])->assertSuccessful();

        $items = ReconciliationItem::query()->where('mismatch_type', 'missing_locally')->orderBy('id')->get();
        $this->assertSame(['pay_lost', 'pay_stranger'], $items->pluck('provider_payment_id')->all());
        $this->assertSame([$first->id, null], $items->pluck('payment_authorization_id')->all());
        $this->assertSame('authorized', $items[0]->provider_value['status']);
    }

    #[Test]
    public function settlements_need_their_permission(): void
    {
        $this->asAdmin(AdminRole::Support, 'get', '/api/v1/admin/settlements')->assertForbidden();
        $this->asAdmin(AdminRole::Operations, 'get', '/api/v1/admin/settlements')->assertForbidden();
        $this->asAdmin(AdminRole::SuperAdmin, 'get', '/api/v1/admin/settlements')->assertOk();
        $this->asAdmin(AdminRole::Finance, 'get', '/api/v1/admin/settlements/00000000-0000-4000-8000-000000000000/lines')->assertNotFound();
    }

    private function balance(LedgerAccountCode $account): int
    {
        return app(Ledger::class)->balance($account)->minor;
    }

    private function asAdmin(AdminRole $role, string $method, string $uri): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri);
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
