<?php

namespace Tests\Feature\Payments;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Jobs\ProcessMoyasarWebhook;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\Confirmations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * Moyasar's webhooks: every event is stored with its secret redacted, only
 * verified events are processed, each once, and processing fetches the
 * payment from Moyasar instead of trusting the body.
 */
class MoyasarWebhooksTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    private const SECRET = 'whsec_test_token';

    private CampaignParticipation $participation;

    private PaymentAuthorization $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        config(['services.moyasar.webhook_secret' => self::SECRET]);

        $campaign = Campaign::factory()->active()->create();
        [, $token, $address] = $this->customerReadyToJoin();
        $this->participation = $this->joinAs($token, $campaign, $address);
        $this->payment = $this->participation->latestPayment;
    }

    #[Test]
    public function a_verified_event_confirms_the_hold(): void
    {
        $this->webhook(['data' => ['id' => 'fake_authorized_'.$this->payment->uuid]])->assertOk();

        $this->assertSame('authorized', $this->participation->refresh()->status->value);

        $event = PaymentWebhookEvent::sole();
        $this->assertTrue($event->signature_valid);
        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->processing_error);
        $this->assertSame(['evt_1', 'payment_authorized', 'fake_authorized_'.$this->payment->uuid], [$event->provider_event_id, $event->event_type, $event->provider_payment_id]);
        $this->assertSame('[redacted]', $event->payload['secret_token']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($event->payload, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function the_event_body_is_never_trusted(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment, status: 'initiated');

        // The body claims a hold; Moyasar says the payment is still processing.
        $this->webhook(['data' => ['id' => 'pay_1', 'status' => 'authorized', 'amount' => 12000]])->assertOk();

        $this->assertSame('pending_payment', $this->participation->refresh()->status->value);
        $this->assertSame(['pending_authorization', 'initiated'], [$this->payment->refresh()->status->value, $this->payment->provider_status]);
        $this->assertSame(['pay_1'], $gateway->fetches);
    }

    #[Test]
    public function a_resent_event_is_processed_once(): void
    {
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment);

        $this->webhook(['data' => ['id' => 'pay_1']])->assertOk();
        $this->webhook(['data' => ['id' => 'pay_1']])->assertOk();

        $this->assertSame(1, PaymentWebhookEvent::count());
        $this->assertSame(['pay_1'], $gateway->fetches);
        $this->assertSame('authorized', $this->participation->refresh()->status->value);
    }

    #[Test]
    public function an_event_that_fails_verification_is_kept_and_never_processed(): void
    {
        $this->webhook(['secret_token' => 'guessed', 'data' => ['id' => 'fake_authorized_'.$this->payment->uuid]])->assertUnauthorized();
        $this->webhook(['id' => 'evt_2', 'secret_token' => null, 'data' => ['id' => 'fake_authorized_'.$this->payment->uuid]])->assertUnauthorized();

        $this->assertSame('pending_payment', $this->participation->refresh()->status->value);
        $events = PaymentWebhookEvent::query()->orderBy('id')->get();
        $this->assertSame([false, false], $events->pluck('signature_valid')->all());
        $this->assertSame([null, null], $events->pluck('processed_at')->all());
        $this->assertSame('[redacted]', $events[0]->payload['secret_token']);
    }

    #[Test]
    public function a_forged_event_cannot_take_a_real_events_id(): void
    {
        $this->webhook(['secret_token' => 'forged', 'data' => ['id' => 'fake_declined_'.$this->payment->uuid]])->assertUnauthorized();
        $this->webhook(['data' => ['id' => 'fake_authorized_'.$this->payment->uuid]])->assertOk();

        $this->assertSame('authorized', $this->participation->refresh()->status->value);
        $this->assertSame([false, true], PaymentWebhookEvent::query()->orderBy('id')->pluck('signature_valid')->all());
    }

    #[Test]
    public function nothing_is_verified_until_the_secret_is_configured(): void
    {
        config(['services.moyasar.webhook_secret' => '']);

        $this->webhook(['secret_token' => '', 'data' => ['id' => 'fake_authorized_'.$this->payment->uuid]])->assertUnauthorized();

        $this->assertSame('pending_payment', $this->participation->refresh()->status->value);
    }

    #[Test]
    public function events_that_name_no_payment_of_ours_are_recorded_as_such(): void
    {
        // A payment made outside this app: its metadata names no payment of ours.
        $gateway = $this->scripted();
        $gateway->report('pay_elsewhere', (new PaymentAuthorization)->forceFill(['uuid' => (string) Str::uuid(), 'authorized_amount_halalas' => 5000]));

        $this->webhook(['id' => 'evt_a', 'data' => ['id' => 'pay_moyasar_does_not_know']])->assertOk();
        $this->webhook(['id' => 'evt_b', 'data' => ['id' => 'pay_elsewhere']])->assertOk();
        $this->webhook(['id' => 'evt_c', 'data' => []])->assertOk();

        $this->assertSame(
            ['evt_a' => 'provider_payment_mismatch', 'evt_b' => 'unknown_payment', 'evt_c' => 'no_payment_id'],
            PaymentWebhookEvent::query()->orderBy('id')->pluck('processing_error', 'provider_event_id')->all(),
        );
        $this->assertSame(0, PaymentWebhookEvent::query()->whereNull('processed_at')->count());
    }

    #[Test]
    public function an_event_without_an_id_is_told_apart_by_its_body(): void
    {
        $body = ['type' => 'payment_authorized', 'secret_token' => self::SECRET, 'data' => ['id' => 'fake_authorized_'.$this->payment->uuid]];

        $this->postJson('/api/v1/webhooks/moyasar', $body)->assertOk();
        $this->postJson('/api/v1/webhooks/moyasar', $body)->assertOk();

        $this->assertStringStartsWith('body:', PaymentWebhookEvent::sole()->provider_event_id);
    }

    #[Test]
    public function a_body_that_is_not_a_json_object_is_refused(): void
    {
        $this->call('POST', '/api/v1/webhooks/moyasar', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[1, 2]')->assertUnauthorized();
        $this->call('POST', '/api/v1/webhooks/moyasar', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertUnauthorized();

        $this->assertSame(0, PaymentWebhookEvent::count());
    }

    #[Test]
    public function an_unreachable_provider_is_retried_and_finally_recorded(): void
    {
        Queue::fake();
        $gateway = $this->scripted();
        $gateway->report('pay_1', $this->payment);
        $gateway->down = true;

        $this->webhook(['data' => ['id' => 'pay_1']])->assertOk();
        Queue::assertPushed(ProcessMoyasarWebhook::class, 1);
        $event = PaymentWebhookEvent::sole();
        $job = new ProcessMoyasarWebhook($event->id);

        try {
            $job->handle(app(Confirmations::class));
            $this->fail('An outage must throw so the job is retried.');
        } catch (GatewayException) {
        }

        $this->assertNull($event->refresh()->processed_at);

        // Moyasar is back for the next try.
        $gateway->down = false;
        $job->handle(app(Confirmations::class));

        $this->assertNotNull($event->refresh()->processed_at);
        $this->assertSame('authorized', $this->participation->refresh()->status->value);
    }

    #[Test]
    public function an_event_whose_retries_ran_out_says_so(): void
    {
        Queue::fake();
        $this->webhook(['data' => ['id' => 'pay_1']])->assertOk();
        $event = PaymentWebhookEvent::sole();

        (new ProcessMoyasarWebhook($event->id))->failed(new GatewayException('Moyasar is unreachable.'));

        $this->assertSame('failed: Moyasar is unreachable.', $event->refresh()->processing_error);
        $this->assertNotNull($event->processed_at);
    }

    /**
     * Posts a Moyasar event: a verified `payment_authorized` event unless
     * the overrides say otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function webhook(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/webhooks/moyasar', array_replace([
            'id' => 'evt_1',
            'type' => 'payment_authorized',
            'created_at' => '2026-11-02T09:00:00.000Z',
            'secret_token' => self::SECRET,
            'account_name' => null,
            'live' => false,
            'data' => [],
        ], $overrides));
    }

    private function scripted(): ScriptedMoyasarGateway
    {
        $gateway = new ScriptedMoyasarGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }
}
