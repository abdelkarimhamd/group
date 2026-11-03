<?php

namespace Tests\Feature\Notifications;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Campaign\Services\CampaignNotices;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Notifications\Models\DeviceToken;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Services\Deliveries;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Payments\Gateway\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\Fakes\FakePushSender;
use Tests\Fakes\FakeSmsSender;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * Customer messages (spec, E14): AR/EN texts for joined, deal confirmed,
 * extended, failed and hold released, charged, shipped and delivered, sent
 * on the queue with retries, every send logged.
 */
final class NotificationsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private FakeSmsSender $sms;

    private FakePushSender $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->sms = $this->fakeSms();
        $this->push = new FakePushSender;
        $this->app->instance(PushSender::class, $this->push);
    }

    #[Test]
    public function customers_hear_about_their_joins_the_deal_and_the_charge_in_their_own_language(): void
    {
        $campaign = $this->closableCampaign(['title_ar' => 'سماعات لاسلكية', 'title_en' => 'Wireless headphones']);
        [$first, $token] = $this->paidJoin($campaign, 1);
        $arabic = $first->user()->sole();
        $this->paidJoin($campaign, 1, $token, $first->address()->sole());
        [$english, $englishToken, $address] = $this->customerReadyToJoin();
        $english->forceFill(['locale' => 'en'])->save();
        [$third] = $this->paidJoin($campaign, 1, $englishToken, $address);

        $deadline = $campaign->deadline_at->setTimezone('Asia/Riyadh');
        $this->assertSame([
            'انضممت إلى سماعات لاسلكية بعدد 1. حجزنا 120.00 ريال على بطاقتك. إذا تمت الصفقة بعد '.$deadline->locale('ar')->translatedFormat('j F, H:i').' تدفع السعر النهائي وقد يكون أقل، وإلا يُلغى الحجز.',
            'انضممت إلى سماعات لاسلكية بعدد 1. حجزنا 120.00 ريال على بطاقتك. إذا تمت الصفقة بعد '.$deadline->locale('ar')->translatedFormat('j F, H:i').' تدفع السعر النهائي وقد يكون أقل، وإلا يُلغى الحجز.',
            // Two joins, one message about the deal.
            'وصلت حملة سماعات لاسلكية إلى هدفها وتأكدت الصفقة. قد ينخفض السعر حتى '.$deadline->locale('ar')->translatedFormat('j F, H:i').'، وبعدها نخصم السعر النهائي.',
        ], $this->sms->to($arabic->phone_e164));
        $this->assertSame([
            'You joined Wireless headphones for 1. We placed a hold of 120.00 SAR on your card. If the deal goes ahead after '.$deadline->locale('en')->translatedFormat('j F, H:i').' you pay the final price, which may be lower; if not, the hold is released.',
            'Wireless headphones reached its target, so the deal is on. The price can still drop until '.$deadline->locale('en')->translatedFormat('j F, H:i').'; then you are charged the final price.',
        ], $this->sms->to($english->phone_e164));

        $this->captureAll($campaign);
        $order = CustomerOrder::query()->where('participation_id', $third->id)->sole();
        $this->assertSame(
            "We charged 120.00 SAR for 1 of Wireless headphones at the final price. Your order number is {$order->order_number}. The rest of your hold, if any, is released.",
            $this->sms->to($english->phone_e164)[2],
        );
        $this->assertCount(5, $this->sms->to($arabic->phone_e164));

        // Every message is recorded with what became of it.
        $this->assertSame(['charged' => 3, 'deal_confirmed' => 2, 'joined' => 3], Notification::query()->orderBy('template_key')->get()->countBy(fn (Notification $n) => $n->template_key->value)->all());
        $charged = Notification::query()->where('subject_type', 'customer_order')->where('subject_id', $order->id)->sole();
        $this->assertSame(['sms', 'en', 'sent', 1, "charged:customer_order:{$order->id}:sms"], [$charged->channel->value, $charged->locale, $charged->status->value, $charged->attempts, $charged->dedupe_key]);
        $this->assertSame(['ar' => 'سماعات لاسلكية', 'en' => 'Wireless headphones'], $charged->payload['campaign']);
        $this->assertNotNull($charged->sent_at);
        $this->assertMatchesRegularExpression('/^fake-sms-\d+$/', (string) $charged->provider_message_id);
        $this->assertSame(0, Notification::query()->where('status', '!=', 'sent')->count());

        // Telling a customer again about the same thing sends nothing new.
        DB::transaction(fn () => app(CampaignNotices::class)->joined($campaign->refresh(), $first));
        $this->assertSame(8, Notification::count());
        $this->assertCount(8, $this->sms->sent);
    }

    #[Test]
    public function a_join_that_fills_the_campaign_is_told_it_closes_now_and_not_about_the_deadline(): void
    {
        $campaign = $this->closableCampaign(['title_en' => 'Desk lamp']);
        [$first] = $this->paidJoin($campaign, 2);
        $early = $first->user()->sole();
        $early->forceFill(['locale' => 'en'])->save();
        [$last, $token, $address] = $this->customerReadyToJoin();
        $last->forceFill(['locale' => 'en'])->save();
        $this->paidJoin($campaign, 8, $token, $address);

        $this->assertSame('closing', $campaign->refresh()->status->value);
        $this->assertSame([
            'You joined Desk lamp for 8. We placed a hold of 960.00 SAR on your card. The campaign is closing now: if the deal goes ahead you pay the final price, which may be lower; if not, the hold is released.',
            'Desk lamp reached its target and is closing now, so the deal is on. You will be charged the final price shortly.',
        ], $this->sms->to($last->phone_e164));
        $this->assertSame(
            'Desk lamp reached its target and is closing now, so the deal is on. You will be charged the final price shortly.',
            $this->sms->to($early->phone_e164)[1],
        );

        // Kept under the usual keys, so the plain wording is never sent as well.
        $this->assertSame(
            ['deal_confirmed_closing' => ['deal_confirmed'], 'joined' => ['joined'], 'joined_closing' => ['joined']],
            Notification::query()->where('channel', 'sms')->orderBy('id')->get()
                ->groupBy(fn (Notification $n) => $n->template_key->value)
                ->map(fn ($messages) => $messages->map(fn (Notification $n) => explode(':', $n->dedupe_key)[0])->unique()->values()->all())
                ->sortKeys()->all(),
        );
    }

    #[Test]
    public function a_released_hold_is_explained_to_each_paid_join(): void
    {
        // Target missed at the deadline.
        $missed = $this->closableCampaign();
        [$short] = $this->paidJoin($missed, 2);
        $this->travelTo($missed->deadline_at);
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame('failed', $missed->refresh()->status->value);
        $this->assertSame(
            'لم تتم صفقة خلاط كهربائي - شراء جماعي. ألغينا الحجز البالغ 240.00 ريال ولم نخصم أي مبلغ. قد يستغرق ظهور ذلك لدى بنكك بضعة أيام.',
            $this->lastSmsTo($short),
        );

        // Cancelled by an admin.
        $cancelled = $this->closableCampaign();
        [$paid] = $this->paidJoin($cancelled, 1);
        [$admin] = $this->adminWithToken(AdminRole::CampaignManager);
        app(CampaignLifecycle::class)->cancel($cancelled->uuid, 'Supplier withdrew the product', $admin);
        $this->assertSame('أُلغيت حملة خلاط كهربائي - شراء جماعي. ألغينا الحجز البالغ 120.00 ريال ولم نخصم أي مبلغ. قد يستغرق ظهور ذلك لدى بنكك بضعة أيام.', $this->lastSmsTo($paid));

        // Cancelled by the customer.
        $open = $this->closableCampaign();
        [$mine, $token] = $this->paidJoin($open, 1);
        $this->withToken($token)->postJson("/api/v1/me/participations/{$mine->uuid}/cancel")->assertOk();
        $this->forgetAuthenticatedUsers();
        $this->assertSame('ألغيت انضمامك إلى خلاط كهربائي - شراء جماعي. ألغينا الحجز البالغ 120.00 ريال ولم نخصم أي مبلغ.', $this->lastSmsTo($mine));

        // Extended: everyone with a paid join hears the new deadline once.
        $extended = $this->closableCampaign(['min_target_qty' => 5]);
        [$waiting] = $this->paidJoin($extended, 1);
        $newDeadline = $extended->deadline_at->addDays(2);
        app(CampaignLifecycle::class)->extend($extended->uuid, $newDeadline, 'Slow first week', $admin);
        $this->assertSame('مُددت حملة خلاط كهربائي - شراء جماعي حتى '.$newDeadline->setTimezone('Asia/Riyadh')->locale('ar')->translatedFormat('j F, H:i').'. يبقى الحجز على بطاقتك كما هو.', $this->lastSmsTo($waiting));

        $this->assertSame(
            ['campaign_cancelled', 'campaign_failed', 'extended', 'join_cancelled'],
            Notification::query()->where('template_key', '!=', 'joined')->orderBy('template_key')->pluck('template_key')->map->value->all(),
        );
    }

    #[Test]
    public function a_declined_capture_is_explained_to_the_customer(): void
    {
        $gateway = new ScriptedMoyasarGateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $campaign = $this->closableCampaign();
        [$join] = $this->paidJoin($campaign, 3);
        $payment = $join->latestPayment()->firstOrFail();
        $gateway->report($payment->provider_payment_id, $payment);
        $gateway->captureOutcomes[$payment->provider_payment_id] = ['declined'];

        $this->captureAll($campaign);

        $this->assertSame('capture_failed', $join->refresh()->status->value);
        $this->assertSame('تعذر الخصم من بطاقتك في خلاط كهربائي - شراء جماعي، لذلك لم يُنشأ طلب. ألغينا الحجز البالغ 360.00 ريال.', $this->lastSmsTo($join));
        // The campaign then failed with nothing captured; the customer is not told twice.
        $this->assertSame('failed', $campaign->refresh()->status->value);
        $this->assertSame(['joined', 'deal_confirmed', 'capture_failed'], Notification::query()->orderBy('id')->pluck('template_key')->map->value->all());
    }

    #[Test]
    public function a_failed_send_is_retried_on_a_schedule_then_given_up(): void
    {
        $this->sms->failing = true;
        [$join] = $this->paidJoin($this->closableCampaign(['min_target_qty' => 5]), 1);
        $message = Notification::query()->sole();
        $this->assertSame(['queued', 1, 'Provider unavailable (fake).'], [$message->status->value, $message->attempts, $message->last_error]);
        $this->assertEquals(now()->addMinute(), $message->next_attempt_at);

        // Not before its wait is over.
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->assertSame(1, $message->refresh()->attempts);

        $this->travel(1)->minutes();
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->assertSame(2, $message->refresh()->attempts);
        $this->assertEquals(now()->addMinutes(5), $message->next_attempt_at);

        $this->sms->failing = false;
        $this->travel(5)->minutes();
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->assertSame(['sent', 3, null, null], [$message->refresh()->status->value, $message->attempts, $message->last_error, $message->next_attempt_at]);
        $this->assertCount(1, $this->sms->to($join->user()->sole()->phone_e164));

        // After the last try the message is marked failed.
        config(['groupbuy.notifications.max_attempts' => 2]);
        $this->sms->failing = true;
        [$other] = $this->paidJoin($this->closableCampaign(['min_target_qty' => 5]), 1);
        $this->travel(1)->minutes();
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $failed = Notification::query()->where('subject_id', $other->id)->sole();
        $this->assertSame(['failed', 2, 'Provider unavailable (fake).', null, null], [$failed->status->value, $failed->attempts, $failed->last_error, $failed->next_attempt_at, $failed->sent_at]);
        $this->travel(1)->hours();
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->assertSame(2, $failed->refresh()->attempts);
    }

    #[Test]
    public function a_message_whose_job_was_lost_is_sent_again(): void
    {
        Bus::fake([SendNotification::class]);
        $user = User::factory()->create();
        $campaign = $this->closableCampaign();

        DB::transaction(fn () => $this->assertSame(1, app(Notifier::class)->send($user, NotificationTemplate::Extended, $campaign, ['campaign' => ['ar' => 'أ', 'en' => 'A'], 'deadline_at' => $campaign->deadline_at->toIso8601ZuluString()], 'extended:test')));
        Bus::assertDispatchedTimes(SendNotification::class, 1);
        $message = Notification::query()->sole();

        $this->travel(9)->minutes();
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        Bus::assertDispatchedTimes(SendNotification::class, 1);

        $this->travel(1)->minutes();
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        Bus::assertDispatchedTimes(SendNotification::class, 2);

        app(Deliveries::class)->deliver($message->id);
        $this->assertSame('sent', $message->refresh()->status->value);
        $this->assertSame(['مُددت حملة أ حتى '.$campaign->deadline_at->setTimezone('Asia/Riyadh')->locale('ar')->translatedFormat('j F, H:i').'. يبقى الحجز على بطاقتك كما هو.'], $this->sms->to($user->phone_e164));
    }

    #[Test]
    public function the_mobile_app_registers_its_push_token_and_gets_every_message_by_push_too(): void
    {
        [$user, $token, $address] = $this->customerReadyToJoin();
        $this->register($token, ['platform' => 'ios', 'token' => 'fcm-token-1'])->assertNoContent();
        $this->register($token, ['platform' => 'ios', 'token' => 'fcm-token-1'])->assertNoContent();
        $this->assertSame(1, DeviceToken::count());

        $this->paidJoin($this->closableCampaign(['min_target_qty' => 5]), 1, $token, $address);

        $this->assertSame(['push', 'sms'], Notification::query()->orderBy('channel')->pluck('channel')->map->value->all());
        $this->assertCount(1, $this->push->sent);
        $push = $this->push->sent[0];
        $this->assertSame(['fcm-token-1'], $push['tokens']);
        $this->assertSame('تم انضمامك', $push['title']);
        $this->assertSame($this->sms->sent[0]['message'], $push['body']);
        $this->assertSame(['notification_id' => Notification::query()->where('channel', 'push')->value('uuid'), 'template' => 'joined'], $push['data']);

        // Another account signing in on the same device takes the token over.
        [$other, $otherToken] = $this->customerWithToken();
        $this->register($otherToken, ['platform' => 'ios', 'token' => 'fcm-token-1'])->assertNoContent();
        $this->assertSame($other->id, DeviceToken::query()->sole()->user_id);

        // Only the ten most recently seen devices are kept.
        foreach (range(2, 12) as $n) {
            $this->travel(1)->seconds();
            $this->register($token, ['platform' => 'android', 'token' => "fcm-token-{$n}"])->assertNoContent();
        }

        $this->assertSame(array_map(fn (int $n) => "fcm-token-{$n}", range(12, 3)), DeviceToken::query()->where('user_id', $user->id)->orderByDesc('last_seen_at')->pluck('fcm_token')->all());

        $this->register($token, ['platform' => 'blackberry', 'token' => ''])->assertUnprocessable()->assertJsonValidationErrors(['platform', 'token']);
        $this->withoutToken()->postJson('/api/v1/me/device-tokens', ['platform' => 'ios', 'token' => 'x'])->assertUnauthorized();

        // Signing out of the app removes its push token: the device stops getting this account's messages.
        $this->forgetAuthenticatedUsers();
        $this->withToken($otherToken)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->forgetAuthenticatedUsers();
        $this->assertFalse(DeviceToken::query()->where('user_id', $other->id)->exists());
        $this->assertSame(10, DeviceToken::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_message_whose_tries_never_report_back_stops_at_the_attempt_limit(): void
    {
        Bus::fake([SendNotification::class]);
        $user = User::factory()->create(['phone_e164' => '+966512345678']);
        app(Notifier::class)->send($user, NotificationTemplate::Shipped, $user, ['order' => 'ORD-1', 'courier' => 'SMSA', 'tracking' => 'T-1'], 'shipped:limit');
        $message = Notification::query()->sole();

        // Every try so far crashed mid-send, so none recorded a result.
        $message->forceFill(['attempts' => config('groupbuy.notifications.max_attempts'), 'next_attempt_at' => now()->subMinute()])->save();

        app(Deliveries::class)->deliver($message->id);
        $message->refresh();
        $this->assertSame(['failed', 'no_result_after_max_attempts', null], [$message->status->value, $message->last_error, $message->next_attempt_at]);
        $this->assertSame([], $this->sms->to('+966512345678'));
        // Nothing is due any more: only the first dispatch, when it was queued.
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        Bus::assertDispatchedTimes(SendNotification::class, 1);
    }

    #[Test]
    public function a_customer_with_no_phone_and_no_app_gets_nothing_queued(): void
    {
        $user = User::factory()->create(['phone_e164' => null]);

        $this->assertSame(0, app(Notifier::class)->send($user, NotificationTemplate::Shipped, $user, ['order' => 'ORD-1'], 'shipped:test'));
        $this->assertSame(0, Notification::count());
    }

    private function lastSmsTo(CampaignParticipation $participation): string
    {
        $texts = $this->sms->to($participation->user()->sole()->phone_e164);
        $this->assertNotEmpty($texts);

        return (string) end($texts);
    }

    /**
     * @param  array<string, string>  $body
     */
    private function register(string $token, array $body): TestResponse
    {
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->postJson('/api/v1/me/device-tokens', $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
