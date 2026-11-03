<?php

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Sms\SmsDeliveryFailed;
use App\Modules\Notifications\Sms\SmsSender;
use App\Modules\Notifications\Sms\TaqnyatSmsSender;
use App\Modules\Notifications\Sms\UnifonicSmsSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The real SMS providers behind SmsSender (spec, E14 and sign-in codes):
 * what each one is sent, and which answers count as sent.
 */
class SmsProvidersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.sms.sender' => 'GroupBuy',
            'services.sms.unifonic.app_sid' => 'app-sid-1',
            'services.sms.unifonic.base_url' => 'https://el.cloud.unifonic.com',
            'services.sms.taqnyat.token' => 'taqnyat-token',
            'services.sms.taqnyat.base_url' => 'https://api.taqnyat.sa',
        ]);
    }

    #[Test]
    public function unifonic_gets_the_number_without_a_plus_and_our_reference(): void
    {
        Http::fake(['el.cloud.unifonic.com/rest/SMS/messages' => Http::response([
            'success' => true, 'message' => '', 'errorCode' => 'ER-00',
            'data' => ['MessageID' => 42000348806924, 'CorrelationID' => 'notification01a1a06a', 'Status' => 'Sent'],
        ])]);

        $id = $this->sender('unifonic')->send('+966512345678', 'رمز الدخول: 123456', 'notification:01a1a06a-5ce3-7182-a8d1-10b33c1cd088');

        $this->assertSame('42000348806924', $id);
        Http::assertSent(fn (Request $request) => $request->isForm()
            && $request['AppSid'] === 'app-sid-1'
            && $request['SenderID'] === 'GroupBuy'
            && $request['Recipient'] === '966512345678'
            && $request['Body'] === 'رمز الدخول: 123456'
            && $request['CorrelationID'] === '01a1a06a5ce37182a8d110b33c1cd088');

        // A short reference goes as it is, without its separators.
        $this->sender('unifonic')->send('+966512345678', 'Hello', 'otp:abc-123');
        Http::assertSent(fn (Request $request) => $request['CorrelationID'] === 'otpabc123');
    }

    #[Test]
    public function unifonic_counts_a_repeat_of_a_message_it_has_as_sent_and_anything_else_as_failed(): void
    {
        Http::fakeSequence('el.cloud.unifonic.com/*')
            ->push(['success' => false, 'message' => 'This message is duplicate', 'errorCode' => 'ER-09'], 409)
            ->push(['success' => false, 'message' => 'Dest num is too short', 'errorCode' => 'ER-04'], 400)
            ->push(['success' => false, 'message' => 'Authentication failed', 'errorCode' => '401'], 401)
            ->push('', 503)
            ->pushFailedConnection();

        $sender = $this->sender('unifonic');
        $this->assertNull($sender->send('+966512345678', 'Hello', 'notification:1'));

        foreach (['ER-04', 'Authentication failed', '503', 'could not be reached'] as $expected) {
            try {
                $sender->send('+966512345678', 'Hello', 'notification:1');
                $this->fail("Expected a failure mentioning {$expected}.");
            } catch (SmsDeliveryFailed $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    #[Test]
    public function taqnyat_gets_json_with_the_sender_and_returns_its_message_id(): void
    {
        Http::fake(['api.taqnyat.sa/v1/messages' => Http::sequence()
            ->push(['statusCode' => 201, 'messageId' => 5452899970, 'cost' => 0.026, 'currency' => 'SAR', 'accepted' => '[966512345678,]', 'rejected' => '[]'], 201)
            ->push(['statusCode' => 400, 'message' => 'Sender Name not active'], 400)
            ->whenEmpty(fn () => throw new ConnectionException('timed out'))]);

        $sender = $this->sender('taqnyat');
        $this->assertSame('5452899970', $sender->send('+966512345678', 'Your order shipped'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer taqnyat-token')
            && $request->data() === ['recipients' => [966512345678], 'body' => 'Your order shipped', 'sender' => 'GroupBuy']);

        $this->assertThrows(fn () => $sender->send('+966512345678', 'Hi'), SmsDeliveryFailed::class, 'Sender Name not active');
        $this->assertThrows(fn () => $sender->send('+966512345678', 'Hi'), SmsDeliveryFailed::class, 'could not be reached');
    }

    #[Test]
    public function each_provider_needs_its_credentials(): void
    {
        config(['services.sms.unifonic.app_sid' => '', 'services.sms.taqnyat.token' => '']);

        $this->assertThrows(fn () => $this->sender('unifonic'), LogicException::class, 'UNIFONIC_APP_SID');
        $this->assertThrows(fn () => $this->sender('taqnyat'), LogicException::class, 'TAQNYAT_TOKEN');
        $this->assertThrows(fn () => new TaqnyatSmsSender('token', '', 'https://api.taqnyat.sa', 10), LogicException::class, 'SMS_SENDER');
    }

    private function sender(string $driver): SmsSender
    {
        config(['services.sms.driver' => $driver]);
        $this->app->forgetInstance(SmsSender::class);
        $sender = app(SmsSender::class);
        $this->assertInstanceOf($driver === 'unifonic' ? UnifonicSmsSender::class : TaqnyatSmsSender::class, $sender);

        return $sender;
    }
}
