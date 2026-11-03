<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Finance\Models\ReconciliationItem;
use App\Modules\Finance\Models\ReconciliationRun;
use App\Modules\Finance\Services\Reconciliation;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Models\PaymentAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\ClosesCampaigns;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * The daily check of our payments against Moyasar (spec, Payments:
 * reconciliation): mismatches are listed for finance and closed with a
 * note; nothing here moves money.
 */
class ReconciliationTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    /** @var list<PaymentAuthorization> */
    private array $payments;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
        [$first] = $this->paidJoin($this->campaign, 2);
        [$second] = $this->paidJoin($this->campaign, 1);
        $this->captureAll($this->campaign);
        $this->payments = [$first->latestPayment()->firstOrFail(), $second->latestPayment()->firstOrFail()];
        $this->day = CarbonImmutable::now('Asia/Riyadh')->toDateString();
    }

    #[Test]
    public function a_day_where_moyasar_agrees_is_matched(): void
    {
        $this->artisan('payments:reconcile', ['--date' => $this->day])->assertSuccessful();

        $run = ReconciliationRun::query()->sole();
        $this->assertSame(['matched', 2, 0], [$run->status->value, $run->payments_checked, $run->mismatch_count]);
        $this->asAdmin(AdminRole::Finance)->getJson('/api/v1/admin/reconciliation-runs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $run->uuid)
            ->assertJsonPath('data.0.run_date', $this->day)
            ->assertJsonPath('data.0.provider', 'moyasar')
            ->assertJsonPath('data.0.status', 'matched')
            ->assertJsonPath('data.0.payments_checked', 2);

        // A day with no changes and nothing open checks nothing.
        $this->travel(2)->days();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame([0, 'matched'], [ReconciliationRun::query()->latest('id')->first()->payments_checked, ReconciliationRun::query()->latest('id')->first()->status->value]);
    }

    #[Test]
    public function mismatches_are_listed_and_closed_with_a_note(): void
    {
        $gateway = new ScriptedMoyasarGateway;
        [$first, $second] = $this->payments;
        // Moyasar shows the first one refunded; it has never heard of the second.
        $gateway->report((string) $first->provider_payment_id, $first, status: 'captured');
        $gateway->setStatus((string) $first->provider_payment_id, 'refunded', $first->captured_amount_halalas, 5000);
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->artisan('payments:reconcile', ['--date' => $this->day])->assertSuccessful();

        $run = ReconciliationRun::query()->sole();
        $this->assertSame(['mismatches', 2, 2], [$run->status->value, $run->payments_checked, $run->mismatch_count]);

        $items = $this->asAdmin(AdminRole::Finance)->getJson("/api/v1/admin/reconciliation-runs/{$run->uuid}/items")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');
        $this->assertSame([
            [$first->uuid, 'status', 'captured', 'refunded', 5000],
            [$second->uuid, 'missing_at_provider', 'captured', null, null],
        ], array_map(fn (array $item) => [
            $item['payment_id'], $item['mismatch_type'], $item['our_value']['status'], $item['provider_value']['status'] ?? null, $item['provider_value']['refunded_halalas'] ?? null,
        ], $items));
        $this->assertSame($run->uuid, $items[0]['run_id']);
        $this->assertNull($items[0]['resolved_by']);

        // Closing one records who and why, once.
        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/reconciliation-items/{$items[0]['id']}/resolve", ['note' => 'no'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');
        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/reconciliation-items/{$items[0]['id']}/resolve", ['note' => 'Refund made in the Moyasar dashboard; requested through the portal'])
            ->assertOk()
            ->assertJsonPath('data.resolved_by.type', 'admin')
            ->assertJsonPath('data.resolution_note', 'Refund made in the Moyasar dashboard; requested through the portal');
        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/reconciliation-items/{$items[0]['id']}/resolve", ['note' => 'Again'])
            ->assertConflict()
            ->assertJsonPath('code', 'already_resolved');
        $this->assertSame(1, AuditLog::query()->where('action', 'reconciliation.resolve')->count());

        $this->asAdmin(AdminRole::Finance)->getJson("/api/v1/admin/reconciliation-runs/{$run->uuid}/items?resolved=false")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $items[1]['id']);
        $this->asAdmin(AdminRole::Finance)->getJson("/api/v1/admin/reconciliation-runs/{$run->uuid}/items?resolved=true")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $items[0]['id']);
        $this->asAdmin(AdminRole::Finance)->getJson("/api/v1/admin/reconciliation-runs/{$run->uuid}/items?resolved=maybe")
            ->assertUnprocessable()->assertJsonValidationErrors('resolved');

        // Nothing about the payments changed.
        $this->assertSame(['captured', 0], [$first->refresh()->status->value, $first->refunded_amount_halalas]);
    }

    #[Test]
    public function a_hold_moyasar_still_has_after_we_released_it_is_reported(): void
    {
        $other = $this->closableCampaign();
        [$join] = $this->paidJoin($other, 1);
        $payment = $join->latestPayment()->firstOrFail();
        $payment->forceFill(['status' => 'voided', 'voided_at' => now()])->save();

        $gateway = new ScriptedMoyasarGateway;
        $gateway->report((string) $payment->provider_payment_id, $payment);
        foreach ($this->payments as $captured) {
            $gateway->report((string) $captured->provider_payment_id, $captured, status: 'captured');
            $gateway->setStatus((string) $captured->provider_payment_id, 'captured', $captured->captured_amount_halalas);
        }
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->artisan('payments:reconcile', ['--date' => CarbonImmutable::now('Asia/Riyadh')->toDateString()])->assertSuccessful();

        $item = ReconciliationItem::query()->sole();
        $this->assertSame([$payment->id, 'stuck_void'], [$item->payment_authorization_id, $item->mismatch_type->value]);
    }

    #[Test]
    public function a_hold_we_are_releasing_that_moyasar_still_holds_is_reported_after_the_grace_period(): void
    {
        // A cancelled join: the hold stays authorized, with a reason, until the void lands.
        $other = $this->closableCampaign();
        [$join] = $this->paidJoin($other, 1);
        $payment = $join->latestPayment()->firstOrFail();
        $payment->forceFill(['rejection_reason' => 'participation_cancelled'])->save();

        $gateway = new ScriptedMoyasarGateway;
        $gateway->report((string) $payment->provider_payment_id, $payment);
        foreach ($this->payments as $captured) {
            $gateway->report((string) $captured->provider_payment_id, $captured, status: 'captured');
            $gateway->setStatus((string) $captured->provider_payment_id, 'captured', $captured->captured_amount_halalas);
        }
        $this->app->instance(PaymentGateway::class, $gateway);
        $today = CarbonImmutable::now('Asia/Riyadh')->toDateString();

        // The void was only just sent: not yet a mismatch.
        $this->artisan('payments:reconcile', ['--date' => $today])->assertSuccessful();
        $this->assertSame(0, ReconciliationItem::count());

        // Hours later Moyasar still holds the money.
        $this->travel(2)->hours();
        $this->artisan('payments:reconcile', ['--date' => $today])->assertSuccessful();
        $item = ReconciliationItem::query()->sole();
        $this->assertSame([$payment->id, 'stuck_void'], [$item->payment_authorization_id, $item->mismatch_type->value]);
    }

    #[Test]
    public function a_run_that_breaks_is_marked_failed_and_one_cut_off_is_closed_by_the_next(): void
    {
        $cutOff = ReconciliationRun::create(['run_date' => '2026-11-01', 'started_at' => CarbonImmutable::now()->subHours(5)]);
        $this->mock(PaymentGateway::class, fn ($mock) => $mock->shouldReceive('fetch')->andThrow(new RuntimeException('Unexpected response shape')));

        try {
            app(Reconciliation::class)->run(CarbonImmutable::parse($this->day, 'Asia/Riyadh'));
            $this->fail('An unexpected error is not swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Unexpected response shape', $e->getMessage());
        }

        $run = ReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertSame(['failed', 'The run stopped on an unexpected error; see the logs.'], [$run->status->value, $run->error]);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(['failed', 'The run stopped before it finished.'], [$cutOff->refresh()->status->value, $cutOff->error]);
    }

    #[Test]
    public function a_date_that_is_not_a_calendar_day_is_refused(): void
    {
        foreach (['abc', '2026/11/02', '2026-11-02x', '2026-13-45', '26-11-02'] as $date) {
            $this->artisan('payments:reconcile', ['--date' => $date])->expectsOutput('Give --date as YYYY-MM-DD.')->assertExitCode(2);
        }

        $this->assertSame(0, ReconciliationRun::count());
    }

    #[Test]
    public function a_run_moyasar_cannot_answer_fails_and_says_why(): void
    {
        $gateway = new ScriptedMoyasarGateway;
        $gateway->down = true;
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->artisan('payments:reconcile', ['--date' => $this->day])->assertFailed();

        $run = ReconciliationRun::query()->sole();
        $this->assertSame(['failed', 'Moyasar is unreachable.'], [$run->status->value, $run->error]);
        $this->assertNotNull($run->finished_at);
    }

    #[Test]
    public function reconciliation_needs_its_permissions(): void
    {
        $this->artisan('payments:reconcile', ['--date' => $this->day])->assertSuccessful();
        $run = ReconciliationRun::query()->sole();

        $this->asAdmin(AdminRole::Operations)->getJson('/api/v1/admin/reconciliation-runs')->assertForbidden();
        $this->asAdmin(AdminRole::Support)->getJson("/api/v1/admin/reconciliation-runs/{$run->uuid}/items")->assertForbidden();
        $this->asAdmin(AdminRole::Finance)->getJson('/api/v1/admin/reconciliation-runs/00000000-0000-4000-8000-000000000000/items')->assertNotFound();
        $this->asAdmin(AdminRole::Finance)->postJson('/api/v1/admin/reconciliation-items/00000000-0000-4000-8000-000000000000/resolve', ['note' => 'Checked'])->assertNotFound();
    }

    private function asAdmin(AdminRole $role): static
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();

        return $this->withToken($token);
    }
}
