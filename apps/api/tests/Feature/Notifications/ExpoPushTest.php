<?php

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\DeviceToken;
use App\Modules\Notifications\Push\ExpoPushSender;
use App\Modules\Notifications\Push\PushDeliveryFailed;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Services\DeviceTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Push messages through Expo's push service (spec, E14): what is sent,
 * and what happens to devices Expo no longer knows.
 */
class ExpoPushTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://exp.host/--/api/v2/push/send';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.push.driver' => 'expo', 'services.push.expo.url' => self::URL, 'services.push.expo.access_token' => '']);
        $this->app->forgetInstance(PushSender::class);
    }

    #[Test]
    public function each_device_gets_the_message_and_the_first_ticket_is_returned(): void
    {
        Http::fake([self::URL => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'XXXX-1'],
            ['status' => 'ok', 'id' => 'XXXX-2'],
        ]])]);

        $id = $this->sender()->send(['ExponentPushToken[aaa]', 'ExponentPushToken[bbb]'], 'تم الشحن', 'طلبك في الطريق', ['notification_id' => 'n-1', 'template' => 'shipped']);

        $this->assertSame('XXXX-1', $id);
        Http::assertSent(function (Request $request): bool {
            $messages = json_decode($request->body(), true);

            return $request->method() === 'POST' && ! $request->hasHeader('Authorization')
                && array_column($messages, 'to') === ['ExponentPushToken[aaa]', 'ExponentPushToken[bbb]']
                && $messages[0]['title'] === 'تم الشحن' && $messages[0]['body'] === 'طلبك في الطريق'
                && $messages[0]['data'] === ['notification_id' => 'n-1', 'template' => 'shipped']
                && $messages[0]['sound'] === 'default';
        });
    }

    #[Test]
    public function a_device_expo_no_longer_knows_is_removed(): void
    {
        $user = User::factory()->create();
        app(DeviceTokens::class)->register($user, null, 'ios', 'ExponentPushToken[gone]');
        app(DeviceTokens::class)->register($user, null, 'android', 'ExponentPushToken[here]');
        Http::fake([self::URL => Http::response(['data' => [
            ['status' => 'error', 'message' => '"ExponentPushToken[gone]" is not a registered push notification recipient', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'ok', 'id' => 'XXXX-3'],
        ]])]);

        $this->assertSame('XXXX-3', $this->sender()->send(['ExponentPushToken[gone]', 'ExponentPushToken[here]'], 'Hi', 'There'));
        $this->assertSame(['ExponentPushToken[here]'], DeviceToken::query()->pluck('fcm_token')->all());
    }

    #[Test]
    public function nothing_delivered_is_a_failure_to_retry(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['data' => [['status' => 'error', 'message' => 'Rate exceeded', 'details' => ['error' => 'MessageRateExceeded']]]])
            ->push(['errors' => [['code' => 'TOO_MANY_REQUESTS', 'message' => 'Slow down']]], 429)
            ->whenEmpty(fn () => throw new ConnectionException('timed out'))]);

        $sender = $this->sender();
        $this->assertThrows(fn () => $sender->send(['ExponentPushToken[aaa]'], 'Hi', 'There'), PushDeliveryFailed::class, 'MessageRateExceeded');
        $this->assertThrows(fn () => $sender->send(['ExponentPushToken[aaa]'], 'Hi', 'There'), PushDeliveryFailed::class, 'TOO_MANY_REQUESTS');
        $this->assertThrows(fn () => $sender->send(['ExponentPushToken[aaa]'], 'Hi', 'There'), PushDeliveryFailed::class, 'could not be reached');

        // A native Firebase or APNs token cannot go through Expo.
        $this->assertThrows(fn () => $sender->send(['fcm-native-token'], 'Hi', 'There'), PushDeliveryFailed::class, 'Expo push token');
    }

    #[Test]
    public function the_access_token_is_sent_when_enhanced_security_is_on(): void
    {
        config(['services.push.expo.access_token' => 'expo-secret']);
        $this->app->forgetInstance(PushSender::class);
        Http::fake([self::URL => Http::response(['data' => [['status' => 'ok', 'id' => 'XXXX-4']]])]);

        $this->sender()->send(['ExpoPushToken[aaa]'], 'Hi', 'There');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer expo-secret'));
    }

    private function sender(): PushSender
    {
        $sender = app(PushSender::class);
        $this->assertInstanceOf(ExpoPushSender::class, $sender);

        return $sender;
    }
}
