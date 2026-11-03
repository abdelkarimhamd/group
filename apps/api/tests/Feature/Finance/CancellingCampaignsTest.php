<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\StatusTransition;
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
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
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
    public function refunds_still_waiting_for_approval_are_withdrawn_and_the_cancellation_returns_everything(): void
    {
        // Support asked to refund part of the first join; nobody decided yet.
        $payment = PaymentAuthorization::query()->where('status', 'captured')->orderBy('id')->firstOrFail();
        $manual = $this->asAdmin(AdminRole::Support)->postJson('/api/v1/admin/refunds', ['payment_id' => $payment->uuid, 'amount_halalas' => 5000, 'reason' => 'defective'])
            ->assertCreated()
            ->json('data');

        $approval = $this->requestCancellation()->assertStatus(202)->json('data.id');
        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/approval-requests/{$approval}/approve")->assertOk();

        // The waiting refund is rejected and its request withdrawn, undecided.
        $withdrawn = Refund::query()->where('uuid', $manual['id'])->sole();
        $request = ApprovalRequest::query()->where('uuid', $manual['approval_request_id'])->sole();
        $this->assertSame(['rejected', 'cancelled', null], [$withdrawn->status->value, $request->status->value, $request->decided_by_admin_id]);
        $this->assertSame(['pending', 'cancelled'], $this->transitions($request));
        $this->assertSame(1, AuditLog::query()->where('action', 'approval.withdraw')->count());
        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/approval-requests/{$manual['approval_request_id']}/reject", ['reason' => 'Already refunded'])
            ->assertConflict();

        // Every captured join gets all of its money back.
        $this->assertSame([24000, 12000], Refund::query()->where('reason', 'campaign_cancelled_after_capture')->orderBy('id')->pluck('amount_halalas')->all());
        $this->assertSame(['refunded', 'refunded'], PaymentAuthorization::query()->where('status', '!=', 'voided')->orderBy('id')->pluck('status')->map->value->all());
        $this->assertSame([0, 0, 0], $this->balances());

        $cancellation = ApprovalRequest::query()->where('uuid', $approval)->sole();
        $this->assertNotNull($cancellation->executed_at);
        $this->assertSame(['pending', 'approved'], $this->transitions($cancellation));
        $event = CampaignEvent::query()->where('campaign_id', $this->campaign->id)->where('event_type', 'cancelled')->sole();
        $this->assertSame(1, $event->payload['withdrawn_refund_requests']);
    }

    #[Test]
    public function an_approved_cancellation_holds_the_campaign_until_carried_out_and_a_lost_one_is_queued_again(): void
    {
        Queue::fake([ExecuteCampaignCancellation::class]);
        $approval = $this->requestCancellation()->assertStatus(202)->json('data.id');
        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/approval-requests/{$approval}/approve")->assertOk();
        $id = ApprovalRequest::query()->where('uuid', $approval)->value('id');
        $pushed = fn () => Queue::pushed(ExecuteCampaignCancellation::class, fn (ExecuteCampaignCancellation $job) => $job->approvalRequestId === $id);

        // Until it is carried out, the purchase order does not move.
        $purchaseOrder = SupplierPurchaseOrder::query()->sole();
        $this->asAdmin(AdminRole::Operations)->postJson("/api/v1/admin/purchase-orders/{$purchaseOrder->uuid}/status", ['status' => 'placed'])
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');

        // Its job was lost (and its lock expired): the sweep queues it again, once.
        (new UniqueLock(app(Cache::class)))->release(new ExecuteCampaignCancellation($id));
        $this->artisan('finance:requeue-approved')->assertSuccessful();
        $this->assertCount(1, $pushed());
        $this->travel(16)->minutes();
        $this->artisan('finance:requeue-approved')->expectsOutputToContain('1 campaign cancellations')->assertSuccessful();
        $this->artisan('finance:requeue-approved')->assertSuccessful();
        $this->assertCount(2, $pushed());

        $pushed()->last()->handle(app(CampaignCancellations::class));
        $this->assertSame('cancelled', $this->campaign->refresh()->status->value);
        $this->assertSame('cancelled', $purchaseOrder->refresh()->status->value);
        $this->assertNotNull(ApprovalRequest::query()->whereKey($id)->value('executed_at'));

        $this->travel(16)->minutes();
        $this->artisan('finance:requeue-approved')->expectsOutputToContain('0 campaign cancellations')->assertSuccessful();
    }

    #[Test]
    public function a_cancellation_that_can_no_longer_be_carried_out_says_so_and_stops(): void
    {
        Queue::fake([ExecuteCampaignCancellation::class]);
        $approval = $this->requestCancellation()->assertStatus(202)->json('data.id');
        $this->asAdmin(AdminRole::Finance)->postJson("/api/v1/admin/approval-requests/{$approval}/approve")->assertOk();
        $id = ApprovalRequest::query()->where('uuid', $approval)->value('id');

        // Changed by hand behind the application's back.
        $this->campaign->forceFill(['status' => 'fulfilling'])->save();

        $this->assertTrue(app(CampaignCancellations::class)->execute($id));
        $this->assertSame(0, Refund::count());
        $this->assertNotNull(ApprovalRequest::query()->whereKey($id)->value('executed_at'));
        $this->assertSame(1, AuditLog::query()->where('action', 'approval.not_carried_out')->count());
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

    /**
     * @return list<string>
     */
    private function transitions(ApprovalRequest $request): array
    {
        return StatusTransition::query()->where('subject_type', 'approval_request')->where('subject_id', $request->id)->orderBy('id')->pluck('to_status')->all();
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
