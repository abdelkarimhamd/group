<?php

namespace Tests\Feature\Campaign;

use App\Models\StatusTransition;
use App\Modules\Campaign\Events\CampaignAwaitingSupplier;
use App\Modules\Campaign\Events\CampaignFailed;
use App\Modules\Campaign\Events\CampaignNeedsAdmin;
use App\Modules\Campaign\Events\CampaignSucceeded;
use App\Modules\Campaign\Http\Resources\ParticipationResource;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\Closing;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Jobs\CapturePayment;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\Captures;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Support\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * The closing job (spec, Campaign lifecycle: "Closing job"): settle the
 * joins, fix the final price, wait for the supplier, capture every hold at
 * the final price, then purchase, or fail and void every hold.
 *
 * The campaign starts on 2 Nov at 09:00 UTC and its deadline is 9 Nov.
 * Holds made on 2 Nov expire on 16 Nov, so captures must be made before
 * 15 Nov 09:00 (capture_by) and the supplier must answer before 14 Nov.
 */
class ClosingCampaignsTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    private ScriptedMoyasarGateway $gateway;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->gateway = new ScriptedMoyasarGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        // 120.00 SAR up to 3 units, 105.00 from 4; the target and the supplier's MOQ are 3.
        $this->campaign = Campaign::factory()->active()
            ->withTiers([
                ['min_qty' => 1, 'max_qty' => 3, 'unit_price_halalas' => 12000],
                ['min_qty' => 4, 'max_qty' => 10, 'unit_price_halalas' => 10500],
            ])
            ->create([
                'min_target_qty' => 3,
                'max_qty' => 10,
                'supplier_quotation_id' => fn (array $attributes) => SupplierQuotation::factory()
                    ->for(Supplier::factory()->approved())
                    ->accepted()
                    ->create(['product_id' => $attributes['product_id'], 'moq' => 3])
                    ->id,
            ]);
    }

    #[Test]
    public function everyone_pays_the_final_price_and_the_campaign_moves_to_purchasing(): void
    {
        Event::fake([CampaignAwaitingSupplier::class, CampaignSucceeded::class]);
        $two = $this->paidJoin(2);
        $one = $this->paidJoin(1);
        $other = $this->paidJoin(1);

        $this->closeAtDeadline();

        // Four units reached the 105.00 tier: the price is fixed once, for everyone.
        $this->campaign->refresh();
        $this->assertSame(['closing', 'awaiting_supplier', 4, 10500], [
            $this->campaign->status->value, $this->campaign->closing_step->value, $this->campaign->final_qty, $this->campaign->final_unit_price_halalas,
        ]);
        $this->assertSame('2026-11-15T09:00:00Z', $this->campaign->capture_by->toIso8601ZuluString());
        $this->assertSame([[10500, 21000], [10500, 10500], [10500, 10500]], array_map(
            fn (CampaignParticipation $p): array => [$p->refresh()->final_unit_price_halalas, $p->capture_amount_halalas],
            [$two, $one, $other],
        ));
        $final = $this->campaign->events()->where('event_type', 'price_changed')->latest('id')->firstOrFail();
        $this->assertSame([true, 4, 10500], [$final->payload['final'], $final->payload['final_qty'], $final->payload['to_unit_price_halalas']]);
        Event::assertDispatchedTimes(CampaignAwaitingSupplier::class, 1);
        $this->assertSame([], $this->gateway->captures);

        $this->admin("/api/v1/admin/campaigns/{$this->campaign->uuid}")
            ->assertOk()
            ->assertJsonPath('data.supplier_decision_due_at', '2026-11-14T09:00:00Z')
            ->assertJsonPath('data.capture_by', '2026-11-15T09:00:00Z')
            ->assertJsonPath('data.final_unit_price.amount_minor', 10500)
            ->assertJsonPath('data.available_actions', ['cancel', 'record_supplier_decision']);

        $this->confirm()
            ->assertOk()
            ->assertJsonPath('data.status', 'purchasing')
            ->assertJsonPath('data.captured_qty', 4);

        // Each hold was captured once, for its share at the final price.
        $this->assertSame([
            [$this->providerId($two), 21000, 'capture:'.$two->latestPayment->uuid],
            [$this->providerId($one), 10500, 'capture:'.$one->latestPayment->uuid],
            [$this->providerId($other), 10500, 'capture:'.$other->latestPayment->uuid],
        ], $this->gateway->captures);

        $payment = $two->latestPayment->refresh();
        $this->assertSame(['captured', 21000], [$payment->status->value, $payment->captured_amount_halalas]);
        $this->assertNotNull($payment->captured_at);
        $this->assertSame(['pending_authorization', 'authorized', 'captured'], $this->transitions($payment));
        $this->assertSame(['pending_payment', 'authorized', 'captured'], $this->transitions($two));
        $this->assertSame(3, PaymentTransaction::query()->where('type', 'capture')->where('status', 'succeeded')->count());

        $this->campaign->refresh();
        $this->assertSame(['purchasing', 'done', 4], [$this->campaign->status->value, $this->campaign->closing_step->value, $this->campaign->captured_qty]);
        $confirmed = $this->campaign->events()->where('event_type', 'supplier_confirmed')->sole();
        $this->assertSame([4000, 'USD', 4, 'PI-2611-07', 4], [
            $confirmed->payload['unit_cost_minor'], $confirmed->payload['currency'], $confirmed->payload['available_qty'], $confirmed->payload['quotation_reference'], $confirmed->payload['final_qty'],
        ]);
        $this->assertSame(['supplier_confirmed', 'captures_completed'], $this->campaign->events()->whereIn('event_type', ['supplier_confirmed', 'captures_completed', 'failed'])->orderBy('id')->pluck('event_type')->all());
        Event::assertDispatchedTimes(CampaignSucceeded::class, 1);

        // Running the job, or a capture, again changes nothing.
        app(Closing::class)->run($this->campaign->id);
        CapturePayment::dispatchSync($payment->id);
        $this->assertCount(3, $this->gateway->captures);
        Event::assertDispatchedTimes(CampaignSucceeded::class, 1);
    }

    #[Test]
    public function below_the_target_the_campaign_fails_and_every_hold_is_voided(): void
    {
        Event::fake([CampaignFailed::class]);
        $first = $this->paidJoin(1);
        $second = $this->paidJoin(1);

        $this->closeAtDeadline();

        $this->campaign->refresh();
        $this->assertSame(['failed', 'target_missed', null], [$this->campaign->status->value, $this->campaign->end_reason, $this->campaign->final_qty]);
        $this->assertSame([$this->providerId($first), $this->providerId($second)], $this->gateway->voids);
        $this->assertSame(['released', 'voided'], [$first->refresh()->status->value, $first->latestPayment->status->value]);
        $this->assertSame([], $this->gateway->captures);
        $failed = $this->campaign->events()->where('event_type', 'failed')->sole();
        $this->assertSame(['target_missed', 2, 2, 3], [$failed->payload['end_reason'], $failed->payload['released_participations'], $failed->payload['final_qty'], $failed->payload['min_target_qty']]);
        Event::assertDispatchedTimes(CampaignFailed::class, 1);
    }

    #[Test]
    public function closing_waits_for_joins_still_paying(): void
    {
        $this->paidJoin(3);
        [, $token, $address] = $this->customerReadyToJoin();
        $this->travelTo($this->campaign->deadline_at->subMinutes(5));
        $waiting = $this->joinAs($token, $this->campaign, $address, ['quantity' => 2, 'shown_unit_price_halalas' => $this->price()]);

        $this->travelTo($this->campaign->deadline_at);
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame(['settling_joins', 2], [$this->campaign->refresh()->closing_step->value, $this->campaign->reserved_qty]);

        // The sweep finds it still waiting.
        $this->artisan('campaigns:sweep-closing')->assertSuccessful();
        $this->assertSame('settling_joins', $this->campaign->refresh()->closing_step->value);

        // The customer never pays: once the reservation runs out, closing goes on without it.
        $this->travel(16)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();

        $this->campaign->refresh();
        $this->assertSame('reservation_expired', $waiting->refresh()->status->value);
        $this->assertSame(['awaiting_supplier', 0, 3, 12000], [$this->campaign->closing_step->value, $this->campaign->reserved_qty, $this->campaign->final_qty, $this->campaign->final_unit_price_halalas]);
    }

    #[Test]
    public function a_campaign_whose_wakeup_was_lost_is_picked_up_by_the_sweep(): void
    {
        $this->paidJoin(3);
        // As if the job queued at closing never ran.
        $this->campaign->forceFill(['status' => 'closing', 'closing_step' => 'settling_joins', 'close_reason' => 'deadline', 'closed_at' => now()])->save();

        $this->artisan('campaigns:sweep-closing')->assertSuccessful();

        $this->assertSame(['awaiting_supplier', 3], [$this->campaign->refresh()->closing_step->value, $this->campaign->final_qty]);
    }

    #[Test]
    public function the_supplier_saying_no_fails_the_campaign(): void
    {
        $join = $this->paidJoin(3);
        $this->closeAtDeadline();

        $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/supplier-decision", ['decision' => 'rejected', 'available_qty' => 0, 'note' => 'Out of stock until March'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.end_reason', 'supplier_rejected');

        $this->assertSame([$this->providerId($join)], $this->gateway->voids);
        $this->assertSame([], $this->gateway->captures);
        $this->assertSame('Out of stock until March', $this->campaign->events()->where('event_type', 'supplier_rejected')->sole()->payload['note']);

        // The price worked out at settling was never charged: customers are not told it was.
        $this->assertSame([null, null], $this->customerView($join, ['final_unit_price', 'capture_amount']));
        $this->getJson("/api/v1/campaigns/{$this->campaign->uuid}")->assertOk()->assertJsonPath('data.final_unit_price', null);
    }

    #[Test]
    public function a_supplier_that_cannot_supply_every_unit_is_refused(): void
    {
        $this->paidJoin(3);
        $this->closeAtDeadline();

        $this->confirm(['available_qty' => 2])
            ->assertStatus(422)
            ->assertJsonPath('code', 'supplier_stock_short')
            ->assertJsonValidationErrors('available_qty');

        $this->assertSame('awaiting_supplier', $this->campaign->refresh()->closing_step->value);
        $this->assertSame([], $this->gateway->captures);
    }

    #[Test]
    public function without_the_supplier_s_answer_by_its_deadline_the_campaign_fails(): void
    {
        $join = $this->paidJoin(3);
        $this->closeAtDeadline();

        $this->travelTo(CarbonImmutable::parse('2026-11-14 08:59:00', 'UTC'));
        $this->artisan('campaigns:sweep-closing')->assertSuccessful();
        $this->assertSame('awaiting_supplier', $this->campaign->refresh()->closing_step->value);

        $this->travelTo(CarbonImmutable::parse('2026-11-14 09:00:00', 'UTC'));
        $this->confirm()->assertStatus(409)->assertJsonPath('code', 'supplier_decision_overdue');
        $this->admin("/api/v1/admin/campaigns/{$this->campaign->uuid}")->assertOk()->assertJsonPath('data.available_actions', ['cancel']);

        $this->artisan('campaigns:sweep-closing')->assertSuccessful();
        $this->campaign->refresh();
        $this->assertSame(['failed', 'no_supplier_decision'], [$this->campaign->status->value, $this->campaign->end_reason]);
        $this->assertSame([$this->providerId($join)], $this->gateway->voids);
    }

    #[Test]
    public function closing_decisions_need_the_closing_permission_and_the_right_step(): void
    {
        $this->paidJoin(3);

        $this->confirm()->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/below-target-decision", ['decision' => 'proceed', 'reason' => 'Go ahead'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition');

        $this->closeAtDeadline();
        [, $operations] = $this->adminWithToken(AdminRole::Operations);
        $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/supplier-decision", $this->confirmation(), $operations)->assertForbidden();
        $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/below-target-decision", ['decision' => 'proceed', 'reason' => 'Go ahead'], $operations)->assertForbidden();

        $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/supplier-decision", ['decision' => 'confirmed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_cost_minor', 'available_qty']);
        $this->assertSame('awaiting_supplier', $this->campaign->refresh()->closing_step->value);
    }

    #[Test]
    public function a_declined_capture_leaves_the_join_out_and_an_admin_decides_below_the_target(): void
    {
        Event::fake([CampaignNeedsAdmin::class]);
        $declined = $this->paidJoin(2);
        $kept = $this->paidJoin(2);
        $this->gateway->captureOutcomes[$this->providerId($declined)] = ['declined'];
        $this->closeAtDeadline();

        $this->confirm()->assertOk()->assertJsonPath('data.closing_step', 'needs_admin');

        // The declined join is left out and its hold voided.
        $payment = $declined->latestPayment->refresh();
        $this->assertSame(['capture_failed', 'voided', 'capture_declined'], [$declined->refresh()->status->value, $payment->status->value, $payment->rejection_reason->value]);
        $this->assertSame(['pending_authorization', 'authorized', 'capture_failed', 'voided'], $this->transitions($payment));
        $this->assertSame([$this->providerId($declined)], $this->gateway->voids);
        $this->assertSame('failed', PaymentTransaction::query()->where('idempotency_key', 'capture:'.$payment->uuid)->sole()->status->value);
        $this->assertSame([null, null], $this->customerView($declined, ['final_unit_price', 'capture_amount']));
        // Four units were settled at 105.00; the kept join's two were charged at that price.
        $this->assertSame([10500, 21000], $this->customerView($kept, ['final_unit_price.amount_minor', 'capture_amount.amount_minor']));
        $this->getJson("/api/v1/campaigns/{$this->campaign->uuid}")->assertOk()->assertJsonPath('data.final_unit_price', null);

        // Two captured units are below the target of three.
        $this->campaign->refresh();
        $this->assertSame(['closing', 'needs_admin', 2], [$this->campaign->status->value, $this->campaign->closing_step->value, $this->campaign->captured_qty]);
        Event::assertDispatchedTimes(CampaignNeedsAdmin::class, 1);
        $this->artisan('campaigns:sweep-closing')->assertSuccessful();
        $this->assertSame('needs_admin', $this->campaign->refresh()->closing_step->value);

        $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/below-target-decision", ['decision' => 'proceed', 'reason' => 'The supplier accepts two units'])
            ->assertOk()
            ->assertJsonPath('data.status', 'purchasing')
            ->assertJsonPath('data.closing_step', 'done');

        $decision = $this->campaign->events()->where('event_type', 'below_target_decision')->sole();
        $this->assertSame(['proceed', 2, 3, 3], [$decision->payload['decision'], $decision->payload['captured_qty'], $decision->payload['min_target_qty'], $decision->payload['supplier_moq']]);
        $this->assertSame('captured', $kept->refresh()->status->value);
        $this->getJson("/api/v1/campaigns/{$this->campaign->uuid}")->assertOk()->assertJsonPath('data.final_unit_price.amount_minor', 10500);
    }

    #[Test]
    public function cancelling_once_money_was_taken_waits_for_finance(): void
    {
        $this->paidJoin(2);
        $declined = $this->paidJoin(2);
        $this->gateway->captureOutcomes[$this->providerId($declined)] = ['declined'];
        $this->closeAtDeadline();
        $this->confirm()->assertOk();
        $this->assertSame('needs_admin', $this->campaign->refresh()->closing_step->value);

        $request = $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/below-target-decision", ['decision' => 'cancel', 'reason' => 'Too few units to buy'])
            ->assertStatus(202)
            ->assertJsonPath('data.type', 'campaign_cancellation')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subject_id', $this->campaign->uuid)
            ->assertJsonPath('data.payload.source', 'below_target')
            ->assertJsonPath('data.payload.captured_participations', 1)
            // Two units at the final 105.00.
            ->assertJsonPath('data.payload.captured_amount_halalas', 21000)
            ->json('data.id');

        // Nothing changes until finance approves, and asking again finds the same request.
        $this->assertSame(['closing', 'needs_admin'], [$this->campaign->refresh()->status->value, $this->campaign->closing_step->value]);
        $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/cancel", ['reason' => 'Cancel it'])
            ->assertStatus(202)
            ->assertJsonPath('data.id', $request);
        $this->assertSame(1, ApprovalRequest::count());
        $this->assertSame(1, $this->campaign->events()->where('event_type', 'cancellation_requested')->count());
        $this->assertSame([$this->providerId($declined)], $this->gateway->voids);
    }

    #[Test]
    public function a_purchasing_campaign_can_only_be_cancelled_through_finance(): void
    {
        $this->paidJoin(3);
        $this->closeAtDeadline();
        $this->confirm()->assertOk()->assertJsonPath('data.status', 'purchasing');

        $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/cancel", ['reason' => 'Supplier went quiet'])
            ->assertStatus(202)
            ->assertJsonPath('data.payload.source', 'cancel')
            ->assertJsonPath('data.payload.campaign_status', 'purchasing')
            ->assertJsonPath('data.payload.captured_amount_halalas', 36000);

        $this->assertSame('purchasing', $this->campaign->refresh()->status->value);
        $this->assertSame([], $this->gateway->voids);
    }

    #[Test]
    public function a_capture_that_timed_out_but_went_through_is_recorded_once(): void
    {
        $join = $this->paidJoin(3);
        $this->gateway->captureOutcomes[$this->providerId($join)] = ['timeout_after_capture'];
        $this->closeAtDeadline();

        $this->confirm()->assertOk()->assertJsonPath('data.status', 'purchasing');

        $this->assertCount(1, $this->gateway->captures);
        $payment = $join->latestPayment->refresh();
        $this->assertSame(['captured', 36000], [$payment->status->value, $payment->captured_amount_halalas]);
        $attempt = PaymentTransaction::query()->where('idempotency_key', 'capture:'.$payment->uuid)->sole();
        $this->assertSame(['succeeded', 1, 'fetch'], [$attempt->status->value, $attempt->attempts, $attempt->response_payload['resolved_by']]);
    }

    #[Test]
    public function an_unknown_capture_outcome_is_checked_with_moyasar_before_sending_it_again(): void
    {
        Queue::fake([CapturePayment::class]);
        $join = $this->paidJoin(3);
        $payment = $join->latestPayment;
        $this->gateway->captureOutcomes[$this->providerId($join)] = ['timeout'];
        $this->closeAtDeadline();
        $this->confirm()->assertOk();
        Queue::assertPushed(CapturePayment::class, fn (CapturePayment $job) => $job->paymentId === $payment->id);

        try {
            app(Captures::class)->capture($payment, Actor::system());
            $this->fail('An unknown outcome is retried.');
        } catch (GatewayException) {
        }

        $attempt = PaymentTransaction::query()->where('idempotency_key', 'capture:'.$payment->uuid)->sole();
        $this->assertSame(['pending', 1], [$attempt->status->value, $attempt->attempts]);
        $this->assertSame('authorized', $payment->refresh()->status->value);

        // The retry asks first (nothing was captured), then sends the same capture again.
        $fetchesBefore = count($this->gateway->fetches);
        app(Captures::class)->capture($payment, Actor::system());

        $this->assertGreaterThan($fetchesBefore, count($this->gateway->fetches));
        $key = 'capture:'.$payment->uuid;
        $this->assertSame([[$this->providerId($join), 36000, $key], [$this->providerId($join), 36000, $key]], $this->gateway->captures);
        $this->assertSame(['succeeded', 2], [$attempt->refresh()->status->value, $attempt->attempts]);
        $this->assertSame('captured', $payment->refresh()->status->value);

        // The last result woke the job, which moved the campaign on.
        $this->assertSame(['purchasing', 'done'], [$this->campaign->refresh()->status->value, $this->campaign->closing_step->value]);
    }

    #[Test]
    public function a_hold_past_its_capture_cutoff_is_never_captured(): void
    {
        Queue::fake([CapturePayment::class]);
        $join = $this->paidJoin(3);
        $payment = $join->latestPayment;
        $this->closeAtDeadline();
        $this->confirm()->assertOk();

        // The capture job only runs once the cutoff has passed.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', 'UTC'));
        app(Captures::class)->capture($payment, Actor::system());

        $this->assertSame([], $this->gateway->captures);
        $payment->refresh();
        $this->assertSame(['voided', 'capture_cutoff_passed'], [$payment->status->value, $payment->rejection_reason->value]);
        $this->assertSame('capture_failed', $join->refresh()->status->value);

        // Nothing was captured: the campaign fails.
        $this->campaign->refresh();
        $this->assertSame(['failed', 'captures_failed', 0], [$this->campaign->status->value, $this->campaign->end_reason, $this->campaign->captured_qty]);
    }

    #[Test]
    public function holds_past_their_expiry_are_marked_expired(): void
    {
        Log::spy();
        $live = $this->paidJoin(1)->latestPayment;
        $failing = $this->paidJoin(1)->latestPayment;
        // A capture failed and its void has not gone through yet.
        $failing->forceFill(['status' => 'capture_failed', 'rejection_reason' => 'capture_declined'])->save();

        $this->travelTo($live->expires_at->subMinute());
        $this->artisan('payments:expire-holds')->assertSuccessful();
        $this->assertSame(['authorized', 'capture_failed'], [$live->refresh()->status->value, $failing->refresh()->status->value]);

        // Moyasar is down: the last void attempt fails, and the hold expires anyway.
        $this->gateway->down = true;
        $this->travelTo($live->expires_at);
        $this->artisan('payments:expire-holds')->assertSuccessful();

        $this->assertSame(['expired', 'expired'], [$live->refresh()->status->value, $failing->refresh()->status->value]);
        $this->assertSame('hold_expired', StatusTransition::query()->where('subject_type', 'payment_authorization')->where('subject_id', $live->id)->where('to_status', 'expired')->value('reason'));
        $this->assertSame([$this->providerId($failing->participation)], $this->gateway->voids);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context = []) => $message === 'payments.hold.expired_uncaptured' && $context['payment'] === $live->uuid)->once();
    }

    #[Test]
    public function a_hold_whose_capture_may_have_gone_through_never_expires(): void
    {
        Log::spy();
        Queue::fake([CapturePayment::class]);
        $join = $this->paidJoin(3);
        $payment = $join->latestPayment;
        $this->gateway->captureOutcomes[$this->providerId($join)] = ['timeout'];
        $this->closeAtDeadline();
        $this->confirm()->assertOk();

        try {
            app(Captures::class)->capture($payment, Actor::system());
            $this->fail('An unknown outcome is retried.');
        } catch (GatewayException) {
        }

        // Moyasar did take the money, then could not be reached past the hold's expiry.
        $this->gateway->setStatus($this->providerId($join), 'captured', 36000);
        $this->gateway->down = true;
        $this->travelTo($payment->expires_at);
        $this->artisan('payments:expire-holds')->assertSuccessful();

        $this->assertSame('authorized', $payment->refresh()->status->value);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context = []) => $message === 'payments.hold.expiry_blocked_by_capture' && $context['payment'] === $payment->uuid)->once();

        // The closing job keeps waiting for it rather than leaving the customer out.
        app(Closing::class)->run($this->campaign->id);
        $this->assertSame(['closing', 'capturing', 'authorized'], [$this->campaign->refresh()->status->value, $this->campaign->closing_step->value, $join->refresh()->status->value]);

        // Moyasar answers again: the capture is recorded, never sent twice.
        $this->gateway->down = false;
        app(Captures::class)->capture($payment, Actor::system());

        $this->assertCount(1, $this->gateway->captures);
        $this->assertSame(['captured', 36000], [$payment->refresh()->status->value, $payment->captured_amount_halalas]);
        $this->assertSame(['captured', 'purchasing'], [$join->refresh()->status->value, $this->campaign->refresh()->status->value]);
    }

    #[Test]
    public function a_refused_capture_of_a_payment_moyasar_shows_captured_for_another_amount_is_left_to_finance(): void
    {
        Log::spy();
        Queue::fake([CapturePayment::class]);
        $join = $this->paidJoin(3);
        $payment = $join->latestPayment;
        $this->closeAtDeadline();
        $this->confirm()->assertOk();

        // Someone captured part of it by hand; Moyasar refuses ours.
        $this->gateway->setStatus($this->providerId($join), 'captured', 20000);
        $this->gateway->captureOutcomes[$this->providerId($join)] = ['declined'];
        app(Captures::class)->capture($payment, Actor::system());

        $this->assertSame([], $this->gateway->voids);
        $this->assertSame(['authorized', null], [$payment->refresh()->status->value, $payment->rejection_reason]);
        $this->assertSame('authorized', $join->refresh()->status->value);
        $this->assertSame('pending', PaymentTransaction::query()->where('idempotency_key', 'capture:'.$payment->uuid)->sole()->status->value);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context = []) => $message === 'payments.capture.amount_mismatch' && $context['captured_halalas'] === 20000)->once();

        // It never expires as an uncaptured hold either.
        $this->travelTo($payment->expires_at);
        $this->artisan('payments:expire-holds')->assertSuccessful();
        $this->assertSame('authorized', $payment->refresh()->status->value);
    }

    #[Test]
    public function a_failed_capture_whose_void_goes_through_is_voided_not_expired(): void
    {
        $failing = $this->paidJoin(1)->latestPayment;
        $failing->forceFill(['status' => 'capture_failed', 'rejection_reason' => 'capture_declined'])->save();

        $this->travelTo($failing->expires_at);
        $this->artisan('payments:expire-holds')->assertSuccessful();

        $this->assertSame('voided', $failing->refresh()->status->value);
        $this->assertSame([$this->providerId($failing->participation)], $this->gateway->voids);
    }

    /**
     * Fields of the join as its customer sees it.
     *
     * @param  list<string>  $keys  dot paths
     * @return list<mixed>
     */
    private function customerView(CampaignParticipation $join, array $keys): array
    {
        $view = json_decode(json_encode(ParticipationResource::make($join->refresh()->load(ParticipationResource::RELATIONS))->resolve(request())), true);

        return array_map(fn (string $key) => data_get($view, $key), $keys);
    }

    /**
     * A customer joins and their hold goes through, as Moyasar reports it.
     */
    private function paidJoin(int $qty): CampaignParticipation
    {
        [, $token, $address] = $this->customerReadyToJoin();
        $participation = $this->joinAs($token, $this->campaign, $address, ['quantity' => $qty, 'shown_unit_price_halalas' => $this->price()]);
        $this->assertTrue($this->authorizeHold($participation));
        $payment = $participation->latestPayment()->firstOrFail();
        $this->gateway->report($payment->provider_payment_id, $payment);

        return $participation->refresh();
    }

    /**
     * The price a customer joining now is shown.
     */
    private function price(): int
    {
        $this->campaign->refresh();

        return $this->campaign->tierSet()->current($this->campaign->committed_qty)->unitPriceHalalas;
    }

    private function providerId(CampaignParticipation $participation): string
    {
        return (string) $participation->latestPayment()->value('provider_payment_id');
    }

    private function closeAtDeadline(): void
    {
        $this->travelTo($this->campaign->deadline_at);
        $this->artisan('campaigns:tick')->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function confirmation(array $overrides = []): array
    {
        return $overrides + [
            'decision' => 'confirmed',
            'unit_cost_minor' => 4000,
            'currency' => 'USD',
            'available_qty' => 4,
            'quotation_reference' => 'PI-2611-07',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function confirm(array $overrides = []): TestResponse
    {
        return $this->postAsAdmin("/api/v1/admin/campaigns/{$this->campaign->uuid}/supplier-decision", $this->confirmation($overrides));
    }

    /**
     * Sent by a campaign manager signed in now (admin tokens last hours, the tests span days).
     *
     * @param  array<string, mixed>  $body
     */
    private function postAsAdmin(string $uri, array $body, ?string $token = null): TestResponse
    {
        $response = $this->withToken($token ?? $this->managerToken())->postJson($uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    private function managerToken(): string
    {
        [, $token] = $this->adminWithToken(AdminRole::CampaignManager);

        return $token;
    }

    private function admin(string $uri): TestResponse
    {
        $response = $this->withToken($this->managerToken())->getJson($uri);
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    /**
     * @return list<string>
     */
    private function transitions(PaymentAuthorization|CampaignParticipation $model): array
    {
        return StatusTransition::query()
            ->where('subject_type', $model->getMorphClass())
            ->where('subject_id', $model->id)
            ->orderBy('id')
            ->pluck('to_status')
            ->all();
    }
}
