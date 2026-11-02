<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Modules\Campaign\Jobs\ExecuteCampaignCancellation;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Modules\Campaign\Services\CampaignCancellations;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\TestCase;

/**
 * Cancelling a campaign whose holds were captured (spec, Campaign
 * lifecycle: cancellation after capture): a second admin with the finance
 * role approves, then every captured join is refunded and the orders and
 * the purchase order are cancelled.
 */
class CancellingCampaignsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
        $this->paidJoin($this->campaign, 2);
        $this->paidJoin($this->campaign, 1);
        $this->captureAll($this->campaign);
        $this->assertSame('purchasing', $this->campaign->refresh()->status->value);
    }

    #[Test]
    public function an_approved_cancellation_refunds_every_captured_join_and_cancels_the_orders_and_the_purchase_order(): void
    {
        $approval = $this->requestCancellation()
            ->assertStatus(202)
            ->assertJsonPath('data.type', 'campaign_cancellation')
            ->assertJsonPath('data.subject_id', $this->campaign->uuid)
            ->assertJsonPath('data.payload.captured_participations', 2)
            ->assertJsonPath('data.payload.captured_amount_halalas', 36000)
            ->json('data.id');

        // Nothing changes until finance approves.
        $this->assertSame('purchasing', $this->campaign->refresh()->status->value);
        $this->assertSame(0, Refund::count());

        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/approval-requests/{$approval}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('cancelled', $this->campaign->refresh()->status->value);
        $this->assertSame(['cancelled', 'cancelled'], CustomerOrder::query()->orderBy('id')->pluck('status')->map->value->all());
        $this->assertSame('cancelled', SupplierPurchaseOrder::query()->sole()->status->value);

        // Each payment went back in full, as a void soon after capture.
        $refunds = Refund::query()->orderBy('id')->get();
        $this->assertSame([['succeeded', 'void', 'campaign_cancelled_after_capture', 24000], ['succeeded', 'void', 'campaign_cancelled_after_capture', 12000]],
            $refunds->map(fn (Refund $r) => [$r->status->value, $r->method->value, $r->reason->value, $r->amount_halalas])->all());
        $this->assertSame([$approval, $approval], $refunds->map(fn (Refund $r) => ApprovalRequest::query()->whereKey($r->approval_request_id)->value('uuid'))->all());
        $this->assertSame(['refunded', 'refunded'], PaymentAuthorization::query()->where('status', '!=', 'voided')->orderBy('id')->pluck('status')->map->value->all());
        $this->assertSame([0, 0, 0], $this->balances());

        // Customers hear about their money once; the event names the approval.
        $this->assertSame(2, Notification::query()->where('template_key', 'refunded')->where('channel', 'sms')->count());
        $event = CampaignEvent::query()->where('campaign_id', $this->campaign->id)->where('event_type', 'cancelled')->sole();
        $this->assertSame([$approval, 2, 2, true, false], [
            $event->payload['approval_request_id'], $event->payload['refunds'], $event->payload['cancelled_orders'],
            $event->payload['purchase_order_cancelled'], $event->payload['supplier_paid'],
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'purchase_order.cancel')->count());

        // Running it again changes nothing.
        $this->assertTrue(app(CampaignCancellations::class)->execute(ApprovalRequest::query()->where('uuid', $approval)->value('id')));
        $this->assertSame(2, Refund::count());
    }

    #[Test]
    public function a_cancellation_waits_while_a_capture_is_in_flight(): void
    {
        Queue::fake([ExecuteCampaignCancellation::class]);
        $approval = $this->requestCancellation()->assertStatus(202)->json('data.id');
        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/approval-requests/{$approval}/approve")->assertOk();
        $id = ApprovalRequest::query()->where('uuid', $approval)->value('id');
        Queue::assertPushed(ExecuteCampaignCancellation::class, fn (ExecuteCampaignCancellation $job) => $job->approvalRequestId === $id);

        $payment = PaymentAuthorization::query()->where('status', 'captured')->firstOrFail();
        $attempt = PaymentTransaction::query()->where('payment_authorization_id', $payment->id)->where('type', 'capture')->sole();
        $attempt->forceFill(['status' => 'pending', 'completed_at' => null])->save();

        $this->assertFalse(app(CampaignCancellations::class)->execute($id));
        $this->assertSame('purchasing', $this->campaign->refresh()->status->value);

        $attempt->forceFill(['status' => 'succeeded', 'completed_at' => now()])->save();
        Queue::fake();
        $this->assertTrue(app(CampaignCancellations::class)->execute($id));
        $this->assertSame('cancelled', $this->campaign->refresh()->status->value);
        $this->assertSame(['approved', 'approved'], Refund::query()->pluck('status')->map->value->all());
    }

    #[Test]
    public function a_campaign_that_moved_on_can_no_longer_be_cancelled(): void
    {
        $approval = $this->requestCancellation()->assertStatus(202)->json('data.id');
        $this->campaign->forceFill(['status' => 'fulfilling'])->save();

        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/approval-requests/{$approval}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');

        $this->assertSame('pending', ApprovalRequest::query()->where('uuid', $approval)->sole()->status->value);
        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function a_rejected_cancellation_leaves_the_campaign_as_it_was(): void
    {
        $approval = $this->requestCancellation()->assertStatus(202)->json('data.id');

        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/approval-requests/{$approval}/reject", ['reason' => 'The supplier already shipped'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame('purchasing', $this->campaign->refresh()->status->value);
        $this->assertSame(0, Refund::count());

        // It can be asked for again.
        $this->requestCancellation()->assertStatus(202)->assertJsonPath('data.status', 'pending');
    }

    private function requestCancellation(): TestResponse
    {
        return $this->asAdmin(AdminRole::CampaignManager)->postJson("/api/v1/admin/campaigns/{$this->campaign->uuid}/cancel", ['reason' => 'Supplier stopped making it']);
    }

    private function asAdmin(AdminRole $role): static
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();

        return $this->withToken($token);
    }

    /**
     * @return list<int>
     */
    private function balances(): array
    {
        $ledger = app(Ledger::class);

        return [
            $ledger->balance(LedgerAccountCode::MoyasarClearing)->minor,
            $ledger->balance(LedgerAccountCode::CustomerPrepayments)->minor,
            $ledger->balance(LedgerAccountCode::VatOutputPayable)->minor,
        ];
    }
}
