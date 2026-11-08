<?php

namespace Tests\Feature\Identity;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Identity\Models\Address;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\DeviceToken;
use App\Modules\Notifications\Services\DeviceTokens;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\Fakes\FakeSmsSender;
use Tests\TestCase;

/**
 * A customer deletes their account from the app or the website (PDPL
 * erasure; both app stores require it): personal data goes, the records the
 * law keeps stay, and nothing open is left without a customer.
 */
class AccountDeletionTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->sms = $this->fakeSms();
    }

    #[Test]
    public function deleting_the_account_erases_the_customer_and_signs_out_every_device(): void
    {
        $user = User::factory()->create(['phone_e164' => '+966512345678', 'name' => 'نورة', 'email' => 'noura@example.com']);
        [, $phoneToken] = $this->customerWithToken($user);
        [, $tabletToken] = $this->customerWithToken($user);
        Address::factory()->for($user)->default()->create();
        app(DeviceTokens::class)->register($user, null, 'ios', 'ExponentPushToken[aaa]');
        $this->postJson('/api/v1/auth/otp', ['phone' => '0512345678'])->assertStatus(202);

        $this->withToken($phoneToken)->deleteJson('/api/v1/me')->assertNoContent();

        $user->refresh();
        $this->assertSame('anonymized', $user->status->value);
        $this->assertNotNull($user->anonymized_at);
        $this->assertSame([null, null, null, null], [$user->phone_e164, $user->phone_verified_at, $user->name, $user->email]);
        $this->assertSame(0, Address::query()->count());
        $this->assertSame(0, DeviceToken::query()->count());
        $this->assertSame(0, OtpChallenge::query()->count());
        $this->assertSame(0, $user->tokens()->count());

        $transition = StatusTransition::query()->where('subject_type', 'user')->where('subject_id', $user->id)->sole();
        $this->assertSame(['active', 'anonymized', 'customer_request', 'user', $user->id], [$transition->from_status, $transition->to_status, $transition->reason, $transition->actor_type, $transition->actor_id]);
        $this->assertTrue(AuditLog::query()->where('action', 'customer.erase')->where('subject_id', $user->id)->exists());

        // Every device is signed out.
        foreach ([$phoneToken, $tabletToken] as $token) {
            $this->forgetAuthenticatedUsers();
            $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        }

        // The number is free: signing in with it starts a new, empty account.
        $this->forgetAuthenticatedUsers();
        $this->travel(5)->minutes();
        $challenge = $this->withoutToken()->postJson('/api/v1/auth/otp', ['phone' => '0512345678'])->assertStatus(202)->json('data.challenge_id');
        $session = $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $this->sms->lastCode(), 'device_name' => 'iPhone'])->assertOk();
        $this->assertNotSame($user->uuid, $session->json('data.user.id'));
        $this->assertNull($session->json('data.user.name'));
    }

    #[Test]
    public function an_open_join_or_hold_keeps_the_account_and_changes_nothing(): void
    {
        [$join, $token] = $this->paidJoin($this->closableCampaign(), 1);
        $user = $join->user()->sole();

        $this->withToken($token)->deleteJson('/api/v1/me', [], ['Accept-Language' => 'en'])
            ->assertConflict()
            ->assertJsonPath('code', 'account_deletion_blocked')
            ->assertJsonPath('blocked_by', ['joins', 'payments'])
            ->assertJsonPath('message', "Your account can't be deleted yet: a join, an order, a payment or a refund is still open. Try again once it has finished.");

        $user->refresh();
        $this->assertSame('active', $user->status->value);
        $this->assertNotNull($user->phone_e164);
        $this->assertSame(1, Address::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function an_order_must_be_settled_before_the_account_can_go(): void
    {
        $campaign = $this->closableCampaign();
        [$join, $token] = $this->paidJoin($campaign, 3);
        $this->captureAll($campaign);
        $order = CustomerOrder::query()->where('participation_id', $join->id)->sole();

        $this->forgetAuthenticatedUsers();
        $this->withToken($token)->deleteJson('/api/v1/me')
            ->assertConflict()
            ->assertJsonPath('blocked_by', ['orders']);

        // The return window ended: the order is completed.
        $order->forceFill(['status' => OrderStatus::Completed])->save();
        $this->forgetAuthenticatedUsers();
        $this->withToken($token)->deleteJson('/api/v1/me')->assertNoContent();

        // The order and its invoice stay, as tax law requires, without the account's phone.
        $this->forgetAuthenticatedUsers();
        $this->assertSame(1, CustomerOrder::query()->whereKey($order->id)->count());
        [, $adminToken] = $this->adminWithToken();
        $this->withToken($adminToken)->getJson("/api/v1/admin/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.customer.phone', null)
            ->assertJsonPath('data.customer.name', null);
    }

    #[Test]
    public function deleting_needs_a_signed_in_customer(): void
    {
        $this->deleteJson('/api/v1/me')->assertUnauthorized();
    }
}
