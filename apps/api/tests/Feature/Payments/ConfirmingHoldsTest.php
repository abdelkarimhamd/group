<?php

namespace Tests\Feature\Payments;

use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\Joins;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\Address;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Confirmations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * The customer's return from the payment form (spec, Payment holds:
 * "Joining: authorization"). The hold is fetched from the provider and
 * accepted only when it is exactly what the join asked for.
 */
class ConfirmingHoldsTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    private Campaign $campaign;

    private string $token;

    private Address $address;

    private CampaignParticipation $participation;

    private PaymentAuthorization $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = Campaign::factory()->active()->create();
        [, $this->token, $this->address] = $this->customerReadyToJoin();
        $this->participation = $this->joinAs($this->token, $this->campaign, $this->address);
        $this->payment = $this->participation->latestPayment;
    }

    #[Test]
    public function a_hold_the_provider_reports_counts_the_join(): void
    {
        $this->sync($this->payment, 'fake_authorized_'.$this->payment->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $this->participation->uuid)
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.payment.id', $this->payment->uuid)
            ->assertJsonPath('data.payment.status', 'authorized')
            ->assertJsonPath('data.payment.scheme', 'mada')
            ->assertJsonPath('data.payment.card_last4', '0000')
            ->assertJsonPath('data.payment.rejection_reason', null)
            ->assertJsonPath('data.payment.checkout', null);

        $this->campaign->refresh();
        $this->assertSame([1, 0], [$this->campaign->committed_qty, $this->campaign->reserved_qty]);

        $this->payment->refresh();
        $this->assertSame(['fake_authorized_'.$this->payment->uuid, 'authorized', 'creditcard'], [$this->payment->provider_payment_id, $this->payment->provider_status, $this->payment->source_type]);
        $this->assertNotNull($this->payment->authorized_at);
        $this->assertNotNull($this->payment->last_synced_at);
        $this->assertSame(['pending_authorization', 'authorized'], $this->transitions($this->payment));
        $this->assertSame(['pending_payment', 'authorized'], $this->transitions($this->participation));
    }

    #[Test]
    public function checking_again_changes_nothing(): void
    {
        $id = 'fake_authorized_'.$this->payment->uuid;
        $this->sync($this->payment, $id)->assertOk();
        $transitions = StatusTransition::count();

        $this->sync($this->payment, $id)->assertOk()->assertJsonPath('data.status', 'authorized');

        $this->assertSame($transitions, StatusTransition::count());
        $this->assertSame(1, $this->campaign->refresh()->committed_qty);
        $this->assertSame(1, PaymentAuthorization::count());
    }

    #[Test]
    public function a_declined_card_keeps_the_reservation_for_another_card(): void
    {
        $this->sync($this->payment, 'fake_declined_'.$this->payment->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.payment.rejection_reason', 'declined');
        $this->assertSame([0, 1], [$this->campaign->refresh()->committed_qty, $this->campaign->reserved_qty]);

        $retry = $this->retry()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_authorization')
            ->assertJsonPath('data.amount.amount_minor', 12000)
            ->assertJsonPath('data.rejection_reason', null)
            ->json('data');
        $this->assertNotSame($this->payment->uuid, $retry['id']);
        $this->assertNotNull($retry['checkout']);

        $second = PaymentAuthorization::query()->where('uuid', $retry['id'])->sole();
        $this->assertSame('retry', StatusTransition::query()->where('subject_type', $second->getMorphClass())->where('subject_id', $second->id)->value('reason'));

        $this->sync($second, 'fake_authorized_'.$second->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.payment.id', $second->uuid);
        $this->assertSame('failed', $this->payment->refresh()->status->value);
        $this->assertSame([1, 0], [$this->campaign->refresh()->committed_qty, $this->campaign->reserved_qty]);
    }

    #[Test]
    public function another_card_waits_until_the_last_one_fails(): void
    {
        $this->retry()->assertConflict()->assertJsonPath('code', 'payment_in_progress');

        $this->sync($this->payment, 'fake_authorized_'.$this->payment->uuid)->assertOk();

        // Once the join counts there is nothing left to pay.
        $this->retry()->assertConflict()->assertJsonPath('code', 'already_paid');
        $this->assertSame(1, PaymentAuthorization::count());
    }

    #[Test]
    public function another_card_cannot_be_tried_once_the_reservation_runs_out(): void
    {
        $this->sync($this->payment, 'fake_declined_'.$this->payment->uuid)->assertOk();
        $this->travel(16)->minutes();

        $this->retry()->assertConflict()->assertJsonPath('code', 'reservation_expired');
    }

    #[Test]
    public function a_payment_is_checked_only_by_its_owner(): void
    {
        [, $stranger] = $this->customerReadyToJoin();

        $this->withToken($stranger)
            ->postJson("/api/v1/payments/{$this->payment->uuid}/sync", ['provider_payment_id' => 'fake_authorized_'.$this->payment->uuid])
            ->assertNotFound();
        $this->forgetAuthenticatedUsers();
        $this->withToken($stranger)->postJson("/api/v1/me/participations/{$this->participation->uuid}/payments")->assertNotFound();

        $this->assertSame('pending_authorization', $this->payment->refresh()->status->value);
    }

    #[Test]
    public function a_provider_payment_made_for_another_payment_is_refused(): void
    {
        [, $token, $address] = $this->customerReadyToJoin();
        $other = $this->joinAs($token, $this->campaign, $address)->latestPayment;

        $this->sync($this->payment, 'fake_authorized_'.$other->uuid)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'provider_payment_mismatch')
            ->assertJsonValidationErrors('provider_payment_id');
        $this->sync($this->payment, 'pay_unknown_to_moyasar')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'provider_payment_mismatch');

        $this->assertSame([null, null], [$this->payment->refresh()->provider_payment_id, $other->refresh()->provider_payment_id]);
    }

    #[Test]
    public function the_provider_payment_id_is_validated(): void
    {
        $this->sync($this->payment, '')->assertUnprocessable()->assertJsonValidationErrors('provider_payment_id');
        $this->sync($this->payment, 'pay_1; drop table')->assertUnprocessable()->assertJsonValidationErrors('provider_payment_id');
        $this->sync($this->payment, str_repeat('a', 65))->assertUnprocessable()->assertJsonValidationErrors('provider_payment_id');
    }

    #[Test]
    public function an_unreachable_provider_is_reported_and_nothing_changes(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment);
        $gateway->down = true;

        $this->sync($this->payment, 'pay_1')->assertStatus(503)->assertJsonPath('code', 'payment_provider_unavailable');

        $this->assertSame(['pending_authorization', null], [$this->payment->refresh()->status->value, $this->payment->provider_payment_id]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedHolds(): array
    {
        return [
            'a different amount' => [['amount' => 11999], 'amount_mismatch'],
            'another currency' => [['currency' => 'USD'], 'currency_mismatch'],
            'STC Pay' => [['source' => 'stcpay', 'scheme' => null], 'method_not_allowed'],
            'an Amex card' => [['scheme' => 'amex'], 'method_not_allowed'],
            'charged at once' => [['status' => 'paid'], 'not_authorize_only'],
        ];
    }

    /**
     * @param  array<string, mixed>  $hold
     */
    #[Test]
    #[DataProvider('refusedHolds')]
    public function a_hold_that_is_not_what_the_join_asked_for_is_voided(array $hold, string $reason): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, ...$hold);

        $this->sync($this->payment, 'pay_1')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment.status', 'voided')
            ->assertJsonPath('data.payment.rejection_reason', $reason);

        $this->assertSame(['pay_1'], $gateway->voids);
        $this->assertSame([0, 1], [$this->campaign->refresh()->committed_qty, $this->campaign->reserved_qty]);
        $this->assertSame([], $this->heldVoidedMessages());

        // The customer can try another card while the reservation lasts.
        $retry = $this->retry()->assertCreated()->json('data.id');
        $this->forgetAuthenticatedUsers();
        $second = PaymentAuthorization::query()->where('uuid', $retry)->sole();
        $gateway->report('pay_2', $second);
        $this->sync($second, 'pay_2')->assertOk()->assertJsonPath('data.status', 'authorized');
    }

    #[Test]
    public function a_hold_still_processing_changes_nothing_yet(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, status: 'initiated');

        $this->sync($this->payment, 'pay_1')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment.status', 'pending_authorization');

        $this->payment->refresh();
        $this->assertSame(['pay_1', 'initiated'], [$this->payment->provider_payment_id, $this->payment->provider_status]);
        $this->assertSame(['pending_authorization'], $this->transitions($this->payment));
        $this->assertSame([], $gateway->voids);
    }

    #[Test]
    public function a_hold_that_arrives_after_the_reservation_ran_out_is_voided(): void
    {
        $this->travel(16)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame('reservation_expired', $this->participation->refresh()->status->value);

        $late = $this->sync($this->payment, 'fake_authorized_'.$this->payment->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'reservation_expired')
            ->assertJsonPath('data.payment.status', 'voided')
            ->assertJsonPath('data.payment.rejection_reason', 'reservation_expired')
            ->json('data.payment.id');

        $this->campaign->refresh();
        $this->assertSame([0, 0], [$this->campaign->committed_qty, $this->campaign->reserved_qty]);

        // Statuses only move forward: the released payment stays failed and
        // the late hold is tracked, and voided, on a row of its own.
        $this->assertNotSame($this->payment->uuid, $late);
        $this->assertSame(['pending_authorization', 'failed'], $this->transitions($this->payment));
        $row = PaymentAuthorization::query()->where('uuid', $late)->sole();
        $this->assertSame('fake_authorized_'.$this->payment->uuid, $row->provider_payment_id);
        $this->assertSame(['pending_authorization', 'authorized', 'voided'], $this->transitions($row));

        // The customer is told the hold was released, once.
        $this->assertSame([$row->id], $this->heldVoidedMessages());
        $this->sync($this->payment, 'fake_authorized_'.$this->payment->uuid)->assertOk();
        $this->assertSame([$row->id], $this->heldVoidedMessages());
    }

    #[Test]
    public function the_expiry_check_counts_a_hold_that_went_through(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, status: 'initiated');
        $this->sync($this->payment, 'pay_1')->assertOk();

        // The customer never came back, but the hold went through.
        $gateway->report('pay_1', $this->payment);
        $this->travel(16)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();

        $this->assertSame('authorized', $this->participation->refresh()->status->value);
        $this->assertSame([1, 0], [$this->campaign->refresh()->committed_qty, $this->campaign->reserved_qty]);
        $this->assertSame(['pay_1', 'pay_1'], $gateway->fetches);
        $this->assertSame([], $gateway->voids);
    }

    #[Test]
    public function a_reservation_ending_after_a_decline_ends_as_payment_failed(): void
    {
        $this->sync($this->payment, 'fake_declined_'.$this->payment->uuid)->assertOk();
        $this->travel(16)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();

        $this->assertSame('payment_failed', $this->participation->refresh()->status->value);
        $this->assertSame(['failed', 'declined'], [$this->payment->refresh()->status->value, $this->payment->rejection_reason->value]);
        $this->assertSame(0, $this->campaign->refresh()->reserved_qty);
    }

    #[Test]
    public function a_second_hold_from_the_same_form_is_voided_and_never_replaces_the_first(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment);
        $this->sync($this->payment, 'pay_1')->assertOk();

        $gateway->report('pay_2', $this->payment);
        $this->sync($this->payment, 'pay_2')
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.payment.id', $this->payment->uuid)
            ->assertJsonPath('data.payment.status', 'authorized');

        $duplicate = PaymentAuthorization::query()->where('provider_payment_id', 'pay_2')->sole();
        $this->assertSame(['voided', 'duplicate_hold'], [$duplicate->status->value, $duplicate->rejection_reason->value]);
        $this->assertSame(['pay_2'], $gateway->voids);
        $this->assertSame(['authorized', null], [$this->payment->refresh()->status->value, $this->payment->rejection_reason]);
        $this->assertSame(1, $this->campaign->refresh()->committed_qty);

        // Seeing it again does not make a third row.
        $this->sync($this->payment, 'pay_2')->assertOk();
        $this->assertSame(2, PaymentAuthorization::count());
    }

    #[Test]
    public function a_hold_from_the_form_after_a_decline_counts_on_a_row_of_its_own(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, status: 'failed');
        $this->sync($this->payment, 'pay_1')->assertOk()->assertJsonPath('data.payment.status', 'failed');

        $gateway->report('pay_2', $this->payment);
        $second = $this->sync($this->payment, 'pay_2')
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.payment.status', 'authorized')
            ->assertJsonPath('data.payment.rejection_reason', null)
            ->json('data.payment.id');

        // The declined payment stays declined; the new hold has its own row.
        $this->assertNotSame($this->payment->uuid, $second);
        $this->assertSame(['failed', 'pay_1'], [$this->payment->refresh()->status->value, $this->payment->provider_payment_id]);
        $this->assertSame('pay_2', PaymentAuthorization::query()->where('uuid', $second)->value('provider_payment_id'));
        $this->assertSame(1, $this->campaign->refresh()->committed_qty);
    }

    #[Test]
    public function a_waiting_payment_follows_the_newest_payment_made_through_its_form(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, status: 'initiated');
        $this->sync($this->payment, 'pay_1')->assertOk();

        $gateway->report('pay_2', $this->payment);
        $this->sync($this->payment, 'pay_2')->assertOk()->assertJsonPath('data.status', 'authorized');

        $this->assertSame(['pay_2', 'authorized'], [$this->payment->refresh()->provider_payment_id, $this->payment->status->value]);
        $this->assertSame(1, PaymentAuthorization::count());

        // The abandoned first attempt goes through after all: it is voided.
        $gateway->report('pay_1', $this->payment);
        $this->sync($this->payment, 'pay_1')->assertOk()->assertJsonPath('data.payment.id', $this->payment->uuid);
        $this->assertSame(['voided', 'duplicate_hold'], [
            PaymentAuthorization::query()->where('provider_payment_id', 'pay_1')->sole()->status->value,
            PaymentAuthorization::query()->where('provider_payment_id', 'pay_1')->sole()->rejection_reason->value,
        ]);
        $this->assertSame(['pay_1'], $gateway->voids);
    }

    #[Test]
    public function a_failed_payment_after_the_hold_changes_nothing(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment);
        $this->sync($this->payment, 'pay_1')->assertOk();

        $gateway->report('pay_2', $this->payment, status: 'failed');
        $this->sync($this->payment, 'pay_2')->assertOk()->assertJsonPath('data.status', 'authorized');

        $this->assertSame(['authorized', 'pay_1'], [$this->payment->refresh()->status->value, $this->payment->provider_payment_id]);
        $this->assertSame(1, PaymentAuthorization::count());
    }

    #[Test]
    public function a_hold_for_a_campaign_past_settling_its_joins_is_voided(): void
    {
        $this->campaign->forceFill(['status' => 'closing', 'closing_step' => 'awaiting_supplier'])->save();

        $this->sync($this->payment, 'fake_authorized_'.$this->payment->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.payment.status', 'voided')
            ->assertJsonPath('data.payment.rejection_reason', 'campaign_closed');

        $this->campaign->refresh();
        $this->assertSame([0, 0], [$this->campaign->committed_qty, $this->campaign->reserved_qty]);
        $this->assertSame([$this->payment->id], $this->heldVoidedMessages());
    }

    #[Test]
    public function a_hold_while_the_campaign_settles_its_joins_still_counts(): void
    {
        $this->campaign->forceFill(['status' => 'closing', 'closing_step' => 'settling_joins', 'close_reason' => 'deadline', 'min_target_qty' => 1])->save();

        $this->sync($this->payment, 'fake_authorized_'.$this->payment->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized');

        // It was the last join in flight, so closing moved on and counted it.
        $this->campaign->refresh();
        $this->assertSame([1, 1, 'awaiting_supplier'], [$this->campaign->committed_qty, $this->campaign->final_qty, $this->campaign->closing_step->value]);
    }

    #[Test]
    public function a_hold_that_lands_after_its_known_payment_was_released_is_voided(): void
    {
        // The customer came back while 3-D Secure was still running.
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, status: 'initiated');
        $this->sync($this->payment, 'pay_1')->assertOk();

        // The reservation runs out while Moyasar still says initiated.
        $this->travel(16)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame(['failed', 'pay_1'], [$this->payment->refresh()->status->value, $this->payment->provider_payment_id]);

        // Then the hold goes through, and the webhook (or a later check) sees it.
        $gateway->report('pay_1', $this->payment);
        $this->sync($this->payment, 'pay_1')->assertOk()->assertJsonPath('data.status', 'reservation_expired');

        $this->payment->refresh();
        $this->assertSame(['voided', 'voided'], [$this->payment->status->value, $this->payment->provider_status]);
        $this->assertSame(['pending_authorization', 'failed', 'voided'], $this->transitions($this->payment));
        $this->assertSame(['pay_1'], $gateway->voids);
        $this->assertSame(1, PaymentAuthorization::count());

        $this->assertSame([$this->payment->id], $this->heldVoidedMessages());

        // Seen again, nothing more happens.
        $this->sync($this->payment, 'pay_1')->assertOk();
        $this->assertSame(['pay_1'], $gateway->voids);
        $this->assertSame([$this->payment->id], $this->heldVoidedMessages());
    }

    #[Test]
    public function a_hold_that_lands_after_the_campaign_was_cancelled_is_voided(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, status: 'initiated');
        $this->sync($this->payment, 'pay_1')->assertOk();

        [, $admin] = $this->adminWithToken(AdminRole::CampaignManager);
        $this->withToken($admin)->postJson("/api/v1/admin/campaigns/{$this->campaign->uuid}/cancel", ['reason' => 'Supplier stopped making it'])->assertOk();
        $this->forgetAuthenticatedUsers();

        $gateway->report('pay_1', $this->payment);
        $this->sync($this->payment, 'pay_1')->assertOk()->assertJsonPath('data.status', 'released');

        $this->assertSame('voided', $this->payment->refresh()->status->value);
        $this->assertSame(['pay_1'], $gateway->voids);
        $this->assertSame([$this->payment->id], $this->heldVoidedMessages());
    }

    #[Test]
    public function a_refused_second_hold_for_a_counted_join_is_a_duplicate_and_never_replaces_the_first(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment);
        $this->sync($this->payment, 'pay_1')->assertOk();

        // The form was sent again, for the wrong amount this time.
        $gateway->report('pay_2', $this->payment, amount: 99);
        $this->sync($this->payment, 'pay_2')
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.payment.id', $this->payment->uuid)
            ->assertJsonPath('data.payment.status', 'authorized');

        $second = PaymentAuthorization::query()->where('provider_payment_id', 'pay_2')->sole();
        $this->assertSame(['voided', 'duplicate_hold'], [$second->status->value, $second->rejection_reason->value]);
        $this->assertSame($this->payment->id, $this->participation->refresh()->latestPayment->id);
    }

    #[Test]
    public function what_the_provider_held_is_kept_with_the_hold(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, amount: 9999, scheme: 'visa');
        $this->sync($this->payment, 'pay_1')->assertOk();

        $change = StatusTransition::query()
            ->where('subject_type', 'payment_authorization')
            ->where('subject_id', $this->payment->id)
            ->where('to_status', 'authorized')
            ->sole();
        $this->assertSame('amount_mismatch', $change->reason);
        // Key order is not kept in jsonb.
        $this->assertEquals(
            ['id' => 'pay_1', 'status' => 'authorized', 'amount_halalas' => 9999, 'currency' => 'SAR', 'source_type' => 'creditcard', 'scheme' => 'visa'],
            $change->metadata['provider'],
        );
    }

    #[Test]
    public function every_reservation_that_expires_was_checked_with_the_provider_first(): void
    {
        $gateway = $this->scripted();
        // An earlier join with no payment started, then one whose hold went through unseen.
        [, $token, $address] = $this->customerReadyToJoin();
        $second = $this->joinAs($token, $this->campaign, $address);
        $gateway->report('pay_2', $second->latestPayment, status: 'initiated');
        $this->sync($second->latestPayment, 'pay_2', $token)->assertOk();
        $gateway->report('pay_2', $second->latestPayment);

        $now = CarbonImmutable::now()->addMinutes(16);
        $this->travelTo($now);
        app(Confirmations::class)->checkExpiringReservations($now, 2);
        app(Joins::class)->expireReservations($now, 2);

        $this->assertSame('authorized', $second->refresh()->status->value);
        $this->assertSame('reservation_expired', $this->participation->refresh()->status->value);
    }

    /**
     * The payments a "hold released" message was sent for, one per payment
     * whatever the number of channels.
     *
     * @return list<int>
     */
    private function heldVoidedMessages(): array
    {
        return Notification::query()->where('template_key', 'hold_voided')->where('subject_type', 'payment_authorization')
            ->orderBy('subject_id')->distinct()->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
    }

    private function sync(PaymentAuthorization $payment, string $providerPaymentId, ?string $token = null): TestResponse
    {
        $response = $this->withToken($token ?? $this->token)->postJson("/api/v1/payments/{$payment->uuid}/sync", ['provider_payment_id' => $providerPaymentId]);
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    private function retry(): TestResponse
    {
        $response = $this->withToken($this->token)->postJson("/api/v1/me/participations/{$this->participation->uuid}/payments");
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    private function scripted(): ScriptedMoyasarGateway
    {
        $gateway = new ScriptedMoyasarGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    /**
     * The statuses a model went through, in order.
     *
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
