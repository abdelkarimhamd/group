<?php

namespace Tests\Feature\Campaign;

use App\Models\StatusTransition;
use App\Modules\Campaign\Events\CampaignClosingStarted;
use App\Modules\Campaign\Events\CampaignDealConfirmed;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Campaign\Services\Joins;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Support\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;

/**
 * What happens when a hold is authorized: units move from reserved to
 * committed, the deal is confirmed at the target, the price steps down at
 * tier boundaries, and the campaign closes at the maximum.
 */
class CountingJoinsTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
    }

    #[Test]
    public function an_authorized_hold_counts_its_units(): void
    {
        $campaign = Campaign::factory()->active()->create();
        [, $token, $address] = $this->customerReadyToJoin();
        $participation = $this->joinAs($token, $campaign, $address, ['quantity' => 3]);

        $this->assertTrue($this->authorizeHold($participation));

        $campaign->refresh();
        $this->assertSame([3, 0], [$campaign->committed_qty, $campaign->reserved_qty]);
        $this->assertSame('authorized', $participation->refresh()->status->value);
        $this->assertSame('2026-11-02T09:00:00Z', $participation->authorized_at->toIso8601ZuluString());
        $this->assertNull($campaign->deal_confirmed_at);

        $joined = CampaignEvent::where('event_type', 'joined')->sole();
        $this->assertSame(3, $joined->committed_qty_after);
        $this->assertSame($participation->uuid, $joined->payload['participation_id']);

        // The same hold reported again changes nothing, and is not voided.
        $this->assertTrue($this->authorizeHold($participation));
        $this->assertSame(3, $campaign->refresh()->committed_qty);
        $this->assertSame('authorized', $participation->latestPayment->status->value);
        $this->assertSame(0, PaymentTransaction::count());

        // Counting a join that already counts is a no-op too.
        $this->assertTrue(DB::transaction(fn () => app(Joins::class)->commitAuthorized($participation, Actor::system())));
        $this->assertSame(1, CampaignEvent::where('event_type', 'joined')->count());
    }

    #[Test]
    public function reaching_a_new_tier_is_recorded_even_at_the_same_price(): void
    {
        $campaign = Campaign::factory()->active()->withTiers([
            ['min_qty' => 1, 'max_qty' => 2, 'unit_price_halalas' => 12000],
            ['min_qty' => 3, 'max_qty' => 5, 'unit_price_halalas' => 12000],
        ])->create(['min_target_qty' => 5, 'max_qty' => 5]);
        [, $token, $address] = $this->customerReadyToJoin();

        $this->authorizeHold($this->joinAs($token, $campaign, $address, ['quantity' => 1]));
        $this->assertSame(0, CampaignEvent::where('event_type', 'price_changed')->count());

        // The second unit fills the first tier, so the next buyer is in the second.
        $this->authorizeHold($this->joinAs($token, $campaign, $address, ['quantity' => 1]));

        $tier = CampaignEvent::where('event_type', 'price_changed')->sole();
        $this->assertSame([12000, 12000, 3, 2], [$tier->payload['from_unit_price_halalas'], $tier->payload['to_unit_price_halalas'], $tier->payload['tier']['min_qty'], $tier->committed_qty_after]);
    }

    #[Test]
    public function reaching_the_target_confirms_the_deal_once_and_the_price_steps_down(): void
    {
        Event::fake([CampaignDealConfirmed::class]);
        $campaign = Campaign::factory()->active()->create();
        [, $token, $address] = $this->customerReadyToJoin();

        $this->authorizeHold($this->joinAs($token, $campaign, $address, ['quantity' => 49]));
        $this->assertNull($campaign->refresh()->deal_confirmed_at);

        $this->authorizeHold($this->joinAs($token, $campaign, $address, ['quantity' => 1]));
        $this->assertSame('2026-11-02T09:00:00Z', $campaign->refresh()->deal_confirmed_at->toIso8601ZuluString());

        $this->authorizeHold($this->joinAs($token, $campaign, $address, ['quantity' => 1, 'shown_unit_price_halalas' => 10500]));

        $this->assertSame(1, CampaignEvent::where('event_type', 'deal_confirmed')->count());
        Event::assertDispatchedTimes(CampaignDealConfirmed::class, 1);

        $price = CampaignEvent::where('event_type', 'price_changed')->sole();
        $this->assertSame([12000, 10500, 50], [$price->payload['from_unit_price_halalas'], $price->payload['to_unit_price_halalas'], $price->committed_qty_after]);

        $this->getJson("/api/v1/campaigns/{$campaign->uuid}/progress")
            ->assertOk()
            ->assertJsonPath('data.committed_qty', 51)
            ->assertJsonPath('data.units_to_target', 0)
            ->assertJsonPath('data.current_unit_price.amount_minor', 10500)
            ->assertJsonPath('data.deal_confirmed_at', '2026-11-02T09:00:00Z');
    }

    #[Test]
    public function reaching_the_maximum_starts_closing_and_late_payments_still_count(): void
    {
        Event::fake([CampaignClosingStarted::class]);
        $campaign = Campaign::factory()->active()->withTiers([['min_qty' => 1, 'max_qty' => 5, 'unit_price_halalas' => 12000]])->create(['min_target_qty' => 3, 'max_qty' => 5]);
        [, $token, $address] = $this->customerReadyToJoin();

        $late = $this->joinAs($token, $campaign, $address, ['quantity' => 1]);
        $this->authorizeHold($this->joinAs($token, $campaign, $address, ['quantity' => 4]));
        $this->assertSame('active', $campaign->refresh()->status->value);

        // The fifth unit was reserved before the maximum was reached; its payment completes now.
        $this->assertTrue($this->authorizeHold($late));

        $campaign->refresh();
        $this->assertSame(['closing', 'max_reached', 'settling_joins', 5, 0], [
            $campaign->status->value, $campaign->close_reason->value, $campaign->closing_step->value, $campaign->committed_qty, $campaign->reserved_qty,
        ]);
        $this->assertSame(['max_reached', 'closed'], $campaign->events()->whereIn('event_type', ['max_reached', 'closed'])->orderBy('id')->pluck('event_type')->all());
        Event::assertDispatchedTimes(CampaignClosingStarted::class, 1);
    }

    #[Test]
    public function a_payment_that_arrives_while_closing_settles_its_joins_still_counts(): void
    {
        $campaign = Campaign::factory()->active()->create();
        [, $token, $address] = $this->customerReadyToJoin();
        $this->travelTo($campaign->deadline_at->subMinutes(5));
        $participation = $this->joinAs($token, $campaign, $address, ['quantity' => 2]);

        $this->travelTo($campaign->deadline_at);
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame('settling_joins', $campaign->refresh()->closing_step->value);

        $this->assertTrue($this->authorizeHold($participation));
        $this->assertSame([2, 0], [$campaign->refresh()->committed_qty, $campaign->reserved_qty]);
    }

    #[Test]
    public function a_late_payment_that_fills_the_campaign_while_settling_does_not_close_it_again(): void
    {
        Event::fake([CampaignClosingStarted::class]);
        $campaign = Campaign::factory()->active()->withTiers([['min_qty' => 1, 'max_qty' => 3, 'unit_price_halalas' => 12000]])->create(['min_target_qty' => 1, 'max_qty' => 3]);
        [, $token, $address] = $this->customerReadyToJoin();
        $this->authorizeHold($this->joinAs($token, $campaign, $address, ['quantity' => 2]));
        $this->travelTo($campaign->deadline_at->subMinutes(5));
        $late = $this->joinAs($token, $campaign, $address, ['quantity' => 1]);

        $this->travelTo($campaign->deadline_at);
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->travel(3)->minutes();
        $this->assertTrue($this->authorizeHold($late));

        $campaign->refresh();
        $this->assertSame(['closing', 'deadline', 'settling_joins', 3, 0], [
            $campaign->status->value, $campaign->close_reason->value, $campaign->closing_step->value, $campaign->committed_qty, $campaign->reserved_qty,
        ]);
        $this->assertTrue($campaign->closed_at->equalTo($campaign->deadline_at));
        $this->assertSame(['closed'], $campaign->events()->whereIn('event_type', ['max_reached', 'closed'])->pluck('event_type')->all());
        Event::assertDispatchedTimes(CampaignClosingStarted::class, 1);
    }

    #[Test]
    public function only_an_active_campaign_starts_closing(): void
    {
        $campaign = Campaign::factory()->active()->create();
        $campaign->forceFill(['status' => 'closing', 'closing_step' => 'settling_joins', 'close_reason' => 'deadline', 'closed_at' => now()])->save();

        $this->expectException(LogicException::class);

        DB::transaction(fn () => app(CampaignLifecycle::class)->enterClosing($campaign, Actor::system()));
    }

    #[Test]
    public function a_payment_that_arrives_after_its_reservation_expired_is_voided(): void
    {
        $campaign = Campaign::factory()->active()->create();
        [, $token, $address] = $this->customerReadyToJoin();
        $participation = $this->joinAs($token, $campaign, $address, ['quantity' => 2]);

        $this->travel(16)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();

        $released = $participation->latestPayment;
        $this->assertFalse($this->authorizeHold($participation));

        $this->assertSame([0, 0], [$campaign->refresh()->committed_qty, $campaign->reserved_qty]);

        // The released payment stays failed; the late hold has a row of its own and is voided.
        $this->assertSame(['failed', 'reservation_expired'], [$released->refresh()->status->value, $released->rejection_reason->value]);
        $payment = $participation->refresh()->latestPayment;
        $this->assertNotSame($released->id, $payment->id);
        $this->assertSame(['voided', 'reservation_expired'], [$payment->status->value, $payment->rejection_reason->value]);
        $this->assertSame('void:'.$payment->uuid, PaymentTransaction::sole()->idempotency_key);
        $this->assertSame(
            [[null, 'pending_authorization'], ['pending_authorization', 'failed'], [null, 'pending_authorization'], ['pending_authorization', 'authorized'], ['authorized', 'voided']],
            StatusTransition::query()->where('subject_type', $payment->getMorphClass())->orderBy('id')->get()->map(fn ($t) => [$t->from_status, $t->to_status])->all(),
        );
    }

    #[Test]
    public function a_payment_that_arrives_after_closing_moved_on_is_voided_and_frees_its_units(): void
    {
        $campaign = Campaign::factory()->active()->create();
        [, $token, $address] = $this->customerReadyToJoin();
        $participation = $this->joinAs($token, $campaign, $address);
        $campaign->forceFill(['status' => 'closing', 'closing_step' => 'awaiting_supplier', 'close_reason' => 'deadline', 'closed_at' => now()])->save();

        $this->assertFalse($this->authorizeHold($participation));

        $this->assertSame([0, 0], [$campaign->refresh()->committed_qty, $campaign->reserved_qty]);
        $this->assertSame(['released', 'campaign_closed'], [$participation->refresh()->status->value, $participation->cancel_reason]);
        $this->assertSame(['voided', 'campaign_closed'], [$participation->latestPayment->status->value, $participation->latestPayment->rejection_reason->value]);
    }
}
