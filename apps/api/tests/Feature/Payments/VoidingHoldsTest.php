<?php

namespace Tests\Feature\Payments;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Payments\Enums\RejectionReason;
use App\Modules\Payments\Gateway\FakeMoyasarGateway;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\GatewayResult;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\ProviderPayment;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\Holds;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;

/**
 * Voids follow the spec's payment hold steps: the attempt row is written and
 * committed before the call, every call carries the key void:{payment uuid},
 * and an unknown outcome is checked with the provider before trying again.
 */
class VoidingHoldsTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    private PaymentAuthorization $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $campaign = Campaign::factory()->active()->create();
        [, $token, $address] = $this->customerReadyToJoin();
        $participation = $this->joinAs($token, $campaign, $address);
        $this->authorizeHold($participation);
        $this->payment = $participation->latestPayment;
    }

    #[Test]
    public function an_unknown_outcome_is_checked_with_the_provider_before_trying_again(): void
    {
        $gateway = $this->gateway(voids: [new GatewayException('Timed out')], fetchStatus: 'authorized');

        try {
            app(Holds::class)->void($this->payment, Actor::system());
            $this->fail('An unknown outcome must throw so the job retries.');
        } catch (GatewayException) {
        }

        $attempt = PaymentTransaction::sole();
        $this->assertSame(['pending', 1, 'void:'.$this->payment->uuid], [$attempt->status->value, $attempt->attempts, $attempt->idempotency_key]);
        $this->assertSame('authorized', $this->payment->refresh()->status->value);

        // The retry finds the hold still authorized, so it voids with the same key.
        $gateway->voids = [GatewayResult::succeeded('ref_1', ['status' => 'voided'])];
        app(Holds::class)->void($this->payment, Actor::system());

        $this->assertSame(['void:'.$this->payment->uuid, 'void:'.$this->payment->uuid], $gateway->keys);
        $this->assertSame(['succeeded', 2, 'ref_1'], [$attempt->refresh()->status->value, $attempt->attempts, $attempt->provider_reference]);
        $this->assertSame('voided', $this->payment->refresh()->status->value);
        $this->assertNotNull($this->payment->voided_at);
    }

    #[Test]
    public function a_void_that_went_through_is_not_sent_twice(): void
    {
        $gateway = $this->gateway(voids: [new GatewayException('Connection reset')], fetchStatus: 'voided');

        try {
            app(Holds::class)->void($this->payment, Actor::system());
        } catch (GatewayException) {
        }

        app(Holds::class)->void($this->payment, Actor::system());

        $this->assertCount(1, $gateway->keys);
        $this->assertSame('voided', $this->payment->refresh()->status->value);
        $this->assertSame(['status' => 'voided', 'resolved_by' => 'fetch'], PaymentTransaction::sole()->response_payload);

        // Nothing more to do once voided.
        app(Holds::class)->void($this->payment, Actor::system());
        $this->assertCount(1, $gateway->keys);
    }

    #[Test]
    public function a_refused_void_is_recorded_and_left_for_reconciliation(): void
    {
        $this->gateway(voids: [GatewayResult::refused('already_captured', 'Payment was captured')], fetchStatus: 'captured');

        app(Holds::class)->void($this->payment, Actor::system());

        $attempt = PaymentTransaction::sole();
        $this->assertSame(['failed', 'already_captured'], [$attempt->status->value, $attempt->error_code]);
        $this->assertSame('authorized', $this->payment->refresh()->status->value);
    }

    #[Test]
    public function the_provider_is_never_called_inside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        DB::transaction(fn () => app(Holds::class)->void($this->payment, Actor::system()));
    }

    #[Test]
    public function a_hold_is_released_only_inside_the_transaction_that_ends_its_join(): void
    {
        $this->expectException(LogicException::class);

        app(Holds::class)->release($this->payment, RejectionReason::CampaignClosed, Actor::system());
    }

    #[Test]
    public function the_fake_gateway_refuses_to_run_in_production(): void
    {
        $this->expectException(LogicException::class);

        new FakeMoyasarGateway(production: true);
    }

    #[Test]
    public function the_fake_gateway_reads_the_outcome_from_the_payment_id(): void
    {
        $fake = new FakeMoyasarGateway(production: false);

        $held = $fake->fetch('fake_authorized_'.$this->payment->uuid);
        $this->assertSame(['authorized', 12000, $this->payment->uuid, 'mada', '0000'], [$held->status, $held->amountHalalas, $held->paymentId, $held->scheme, $held->cardLast4]);
        $this->assertSame('failed', $fake->fetch('fake_declined_'.$this->payment->uuid)->status);

        $this->expectException(GatewayException::class);
        $fake->fetch('pay_real_looking_id');
    }

    /**
     * A gateway that answers voids from a script and records every key sent.
     *
     * @param  list<GatewayResult|GatewayException>  $voids
     */
    private function gateway(array $voids, string $fetchStatus): object
    {
        $gateway = new class($voids, $fetchStatus) implements PaymentGateway
        {
            /** @var list<string> */
            public array $keys = [];

            /**
             * @param  list<GatewayResult|GatewayException>  $voids
             */
            public function __construct(public array $voids, public string $fetchStatus) {}

            public function fetch(string $providerPaymentId): ProviderPayment
            {
                return new ProviderPayment($providerPaymentId, $this->fetchStatus, 12000, 'SAR', 'creditcard', 'mada', '0000', 0, null, ['status' => $this->fetchStatus]);
            }

            public function void(string $providerPaymentId, string $idempotencyKey): GatewayResult
            {
                $this->keys[] = $idempotencyKey;
                $next = array_shift($this->voids) ?? throw new LogicException('Unexpected void.');

                return $next instanceof GatewayException ? throw $next : $next;
            }

            public function capture(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
            {
                throw new LogicException('Unexpected capture.');
            }

            public function refund(string $providerPaymentId, int $amountHalalas, string $idempotencyKey): GatewayResult
            {
                throw new LogicException('Unexpected refund.');
            }
        };

        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }
}
