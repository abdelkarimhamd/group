<?php

namespace Tests\Feature\Payments;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\MoyasarGateway;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\ProviderPaymentNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;

/**
 * The real Moyasar adapter (spec, Payments: authorize, then capture or
 * void): what it sends, and how it sorts Moyasar's answers into a yes, a
 * no, or an unknown outcome the caller checks before trying again.
 */
class MoyasarGatewayTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    private const BASE = 'https://api.moyasar.com/v1';

    private const ID = '760878ec-d1d3-5f72-9056-191683f55872';

    private const OURS = '01a1a06a-5ce3-7182-a8d1-10b33c1cd088';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.moyasar.driver' => 'moyasar',
            'services.moyasar.secret_key' => 'sk_test_secret',
            'services.moyasar.publishable_key' => 'pk_test_public',
            'services.moyasar.base_url' => self::BASE,
        ]);
        $this->app->forgetInstance(PaymentGateway::class);
    }

    #[Test]
    public function a_payment_is_read_with_its_amounts_card_and_our_payment_id(): void
    {
        Http::fake([self::BASE.'/payments/'.self::ID => Http::response($this->payment('authorized'))]);

        $payment = $this->gateway()->fetch(self::ID);

        $this->assertSame(
            [self::ID, 'authorized', 36000, 'SAR', 'creditcard', 'mada', '1010', 0, 0, self::OURS],
            [$payment->id, $payment->status, $payment->amountHalalas, $payment->currency, $payment->sourceType, $payment->scheme, $payment->cardLast4, $payment->capturedHalalas, $payment->refundedHalalas, $payment->paymentId],
        );
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_secret:')));
    }

    #[Test]
    public function fetching_tells_a_missing_payment_from_an_outage(): void
    {
        Http::fake([
            self::BASE.'/payments/missing' => Http::response(['type' => 'record_not_found', 'message' => 'Object not found'], 404),
            self::BASE.'/payments/down' => Http::response('', 503),
            self::BASE.'/payments/key' => Http::response(['type' => 'authentication_error', 'message' => 'Invalid authorization credentials'], 401),
            self::BASE.'/payments/slow' => fn () => throw new ConnectionException('Operation timed out'),
            self::BASE.'/payments/garbled' => Http::response('<html>proxy error</html>', 200),
        ]);

        $this->assertThrows(fn () => $this->gateway()->fetch('missing'), ProviderPaymentNotFound::class);

        foreach (['down', 'key', 'slow', 'garbled'] as $id) {
            try {
                $this->gateway()->fetch($id);
                $this->fail("Fetching [{$id}] should be an unknown outcome.");
            } catch (GatewayException $e) {
                $this->assertNotInstanceOf(ProviderPaymentNotFound::class, $e, $id);
            }
        }
    }

    #[Test]
    public function capture_void_and_refund_send_the_amount_and_the_idempotency_key(): void
    {
        Http::fake([
            self::BASE.'/payments/'.self::ID.'/capture' => Http::response($this->payment('captured', captured: 30000)),
            self::BASE.'/payments/'.self::ID.'/refund' => Http::response($this->payment('refunded', captured: 30000, refunded: 5000)),
            self::BASE.'/payments/'.self::ID.'/void' => Http::response($this->payment('voided')),
        ]);

        $capture = $this->gateway()->capture(self::ID, 30000, 'capture:abc');
        $refund = $this->gateway()->refund(self::ID, 5000, 'refund:def');
        $void = $this->gateway()->void(self::ID, 'void:ghi');

        $this->assertSame([true, true, true], [$capture->succeeded, $refund->succeeded, $void->succeeded]);
        $this->assertSame([self::ID, 30000], [$capture->providerReference, $capture->payload['captured']]);

        $sent = Http::recorded()->map(fn (array $pair) => [$pair[0]->method(), $pair[0]->url(), $pair[0]->data(), $pair[0]->header('Idempotency-Key')[0] ?? null])->all();
        $this->assertSame([
            ['POST', self::BASE.'/payments/'.self::ID.'/capture', ['amount' => 30000], 'capture:abc'],
            ['POST', self::BASE.'/payments/'.self::ID.'/refund', ['amount' => 5000], 'refund:def'],
            ['POST', self::BASE.'/payments/'.self::ID.'/void', [], 'void:ghi'],
        ], $sent);
    }

    #[Test]
    public function a_refusal_about_the_payment_is_a_definite_no(): void
    {
        Http::fake([
            self::BASE.'/payments/'.self::ID.'/capture' => Http::response(['type' => 'invalid_request_error', 'message' => 'Validation Failed', 'errors' => ['amount' => ['must be less than or equal to 36000']]], 400),
            self::BASE.'/payments/'.self::ID.'/void' => Http::response(['type' => 'invalid_request_error', 'message' => 'Payment cannot be voided'], 400),
            self::BASE.'/payments/missing/refund' => Http::response(['type' => 'record_not_found', 'message' => 'Object not found'], 404),
        ]);

        $capture = $this->gateway()->capture(self::ID, 99999, 'capture:abc');
        $void = $this->gateway()->void(self::ID, 'void:abc');
        $refund = $this->gateway()->refund('missing', 100, 'refund:abc');

        $this->assertSame([false, 'invalid_request_error'], [$capture->succeeded, $capture->errorCode]);
        $this->assertStringContainsString('must be less than or equal to 36000', (string) $capture->errorMessage);
        $this->assertSame([false, 'Payment cannot be voided'], [$void->succeeded, $void->errorMessage]);
        $this->assertSame([false, 'record_not_found'], [$refund->succeeded, $refund->errorCode]);
    }

    #[Test]
    public function an_outage_our_own_set_up_or_a_strange_answer_is_an_unknown_outcome(): void
    {
        $answers = [
            'timeout' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            'server' => Http::response(['type' => 'api_error', 'message' => 'Internal error'], 500),
            'busy' => Http::response(['type' => 'rate_limit_error', 'message' => 'Too many requests'], 429),
            'key' => Http::response(['type' => 'authentication_error', 'message' => 'Invalid authorization credentials'], 401),
            'ip' => Http::response(['type' => 'api_error', 'message' => 'User not authorized'], 403),
            'inactive' => Http::response(['type' => 'account_inactive_error', 'message' => 'Account not activated'], 405),
            // A 200 that did not capture: ask again before deciding.
            'unchanged' => Http::response($this->payment('authorized')),
        ];
        Http::fake(collect($answers)->mapWithKeys(fn ($answer, string $id) => [self::BASE."/payments/{$id}/capture" => $answer])->all());

        foreach (array_keys($answers) as $id) {
            $this->assertThrows(fn () => $this->gateway()->capture($id, 100, "capture:{$id}"), GatewayException::class);
        }
    }

    #[Test]
    public function the_keys_must_be_a_live_pair_in_production_and_a_test_pair_anywhere_else(): void
    {
        config(['services.moyasar.secret_key' => '']);
        $this->assertThrows(fn () => app(PaymentGateway::class), LogicException::class, 'MOYASAR_SECRET_KEY');

        $refused = [
            [true, 'sk_test_secret', 'pk_test_public'],
            // The form would make payments the live secret key cannot see.
            [true, 'sk_live_secret', 'pk_test_fake'],
            [true, 'sk_test_secret', 'pk_live_public'],
            // Live keys outside production would move real money from staging.
            [false, 'sk_live_secret', 'pk_live_public'],
            [false, 'sk_test_secret', 'pk_live_public'],
        ];

        foreach ($refused as [$production, $secret, $publishable]) {
            $this->assertThrows(fn () => new MoyasarGateway($secret, $publishable, self::BASE, 20, 5, $production), LogicException::class, 'MOYASAR_PUBLISHABLE_KEY');
        }

        $this->assertInstanceOf(MoyasarGateway::class, new MoyasarGateway('sk_live_secret', 'pk_live_public', self::BASE, 20, 5, production: true));
        $this->assertInstanceOf(MoyasarGateway::class, new MoyasarGateway('sk_test_secret', 'pk_test_public', self::BASE, 20, 5, production: false));
    }

    #[Test]
    public function a_mastercard_hold_is_accepted_as_mastercard(): void
    {
        $campaign = Campaign::factory()->active()->create();
        [, $token, $address] = $this->customerReadyToJoin();
        $payment = $this->joinAs($token, $campaign, $address)->latestPayment;
        $remote = $this->payment('authorized');
        $remote['amount'] = $payment->authorized_amount_halalas;
        $remote['metadata']['payment_id'] = $payment->uuid;
        // Moyasar calls the network "master".
        $remote['source']['company'] = 'master';
        Http::fake([self::BASE.'/payments/'.self::ID => Http::response($remote)]);

        $this->withToken($token)->postJson("/api/v1/payments/{$payment->uuid}/sync", ['provider_payment_id' => self::ID])
            ->assertOk()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.payment.status', 'authorized')
            ->assertJsonPath('data.payment.scheme', 'mastercard');
    }

    private function gateway(): PaymentGateway
    {
        $gateway = app(PaymentGateway::class);
        $this->assertInstanceOf(MoyasarGateway::class, $gateway);

        return $gateway;
    }

    /**
     * A Moyasar payment object, as Fetch Payment returns it.
     *
     * @return array<string, mixed>
     */
    private function payment(string $status, int $captured = 0, int $refunded = 0): array
    {
        return [
            'id' => self::ID,
            'status' => $status,
            'amount' => 36000,
            'fee' => 0,
            'currency' => 'SAR',
            'refunded' => $refunded,
            'captured' => $captured,
            'description' => 'Group buy join',
            'amount_format' => '360.00 SAR',
            'created_at' => '2026-11-02T09:00:00.000Z',
            'updated_at' => '2026-11-02T09:00:05.000Z',
            'metadata' => ['payment_id' => self::OURS],
            'source' => [
                'type' => 'creditcard',
                'company' => 'mada',
                'name' => 'Saleh Ali',
                'number' => '420132******1010',
                'message' => 'APPROVED',
                'reference_number' => '125478454231',
            ],
        ];
    }
}
