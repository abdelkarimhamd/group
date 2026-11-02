<?php

namespace Tests\Feature\Identity;

use App\Models\AuditLog;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\OtpChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Fakes\FakeSmsSender;
use Tests\TestCase;

class AdminSignInTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private FakeSmsSender $sms;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = $this->fakeSms();
        $this->freezeSecond();
        $this->admin = AdminUser::factory()->withRole(AdminRole::Finance)->create([
            'email' => 'sara@example.com',
            'phone_e164' => '+966512345678',
        ]);
    }

    #[Test]
    public function the_right_password_texts_a_code_to_the_admins_phone(): void
    {
        $response = $this->login('Sara@Example.com ', 'password')->assertStatus(202);

        $this->assertSame('+966512345678', $this->sms->sent[0]['to']);

        $challenge = OtpChallenge::where('uuid', $response->json('data.challenge_id'))->sole();
        $this->assertSame(OtpPurpose::AdminLogin, $challenge->purpose);
        $this->assertSame($this->admin->id, $challenge->admin_user_id);

        // No token until the code is checked.
        $this->assertSame(0, PersonalAccessToken::count());
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_email_look_the_same(): void
    {
        $wrongPassword = $this->login('sara@example.com', 'not-the-password', ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')
            ->json();

        $unknownEmail = $this->login('nobody@example.com', 'password', ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->json();

        $this->assertSame($wrongPassword, $unknownEmail);
        $this->assertSame([], $this->sms->sent);
    }

    #[Test]
    public function a_blocked_admin_learns_it_only_with_the_right_password(): void
    {
        $this->admin->forceFill(['status' => 'blocked'])->save();

        $this->login('sara@example.com', 'wrong-password')->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->login('sara@example.com', 'password')->assertStatus(422)->assertJsonPath('code', 'account_blocked');

        $this->assertSame([], $this->sms->sent);
    }

    #[Test]
    public function password_attempts_are_limited_per_email_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('sara@example.com', 'guess-'.$i)->assertStatus(422);
        }

        $this->login('sara@example.com', 'password')->assertStatus(429)->assertHeader('Retry-After');

        $this->travel(15)->minutes();
        $this->login('sara@example.com', 'password')->assertStatus(202);
    }

    #[Test]
    public function password_attempts_are_limited_per_email_from_any_ip(): void
    {
        $perEmail = (int) config('groupbuy.auth.admin_login.per_email');

        for ($i = 0; $i < $perEmail; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])
                ->login('sara@example.com', 'guess-'.$i)
                ->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->login('sara@example.com', 'password')
            ->assertStatus(429);
    }

    #[Test]
    public function codes_to_an_admin_are_limited_whatever_the_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->login('sara@example.com', 'password')->assertStatus(202);

        // A second code inside the resend wait is refused, even from another IP.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->login('sara@example.com', 'password')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');

        $perHour = (int) config('groupbuy.auth.otp.per_phone_per_hour');

        for ($i = 1; $i < $perHour; $i++) {
            $this->travel((int) config('groupbuy.auth.otp.resend_seconds'))->seconds();
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.'.$i])->login('sara@example.com', 'password')->assertStatus(202);
        }

        $this->travel((int) config('groupbuy.auth.otp.resend_seconds'))->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])->login('sara@example.com', 'password')->assertStatus(429);
        $this->assertCount($perHour, $this->sms->sent);
    }

    #[Test]
    public function an_sms_outage_is_a_503(): void
    {
        $this->sms->failing = true;

        $this->login('sara@example.com', 'password')->assertStatus(503)->assertJsonPath('code', 'sms_unavailable');
    }

    #[Test]
    public function the_code_signs_the_admin_in_with_roles_and_permissions(): void
    {
        $challengeId = $this->login('sara@example.com', 'password')->json('data.challenge_id');

        $response = $this->verify($challengeId, $this->sms->lastCode())
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.admin.id', $this->admin->uuid)
            ->assertJsonPath('data.admin.email', 'sara@example.com')
            ->assertJsonPath('data.admin.roles', ['finance']);

        $permissions = $response->json('data.admin.permissions');
        $this->assertContains('approvals.decide', $permissions);
        $this->assertContains('campaigns.approve', $permissions);
        $this->assertNotContains('catalog.edit', $permissions);

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertTrue($token->tokenable->is($this->admin));
        $this->assertTrue($token->expires_at->equalTo(now()->addHours(12)));
        $this->assertTrue($this->admin->fresh()->last_login_at->equalTo(now()));

        $audit = AuditLog::where('action', 'admin.login')->sole();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame('admin_user', $audit->subject_type);
        $this->assertSame($token->id, $audit->after['token_id']);
    }

    #[Test]
    public function a_customer_code_cannot_finish_an_admin_sign_in(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => '+966512345678'])->assertStatus(202);
        $customerChallenge = OtpChallenge::sole();

        $this->verify($customerChallenge->uuid, $this->sms->lastCode())
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_invalid');
    }

    #[Test]
    public function wrong_codes_spend_the_challenge(): void
    {
        $challengeId = $this->login('sara@example.com', 'password')->json('data.challenge_id');
        $code = $this->sms->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->verify($challengeId, $wrong)->assertJsonPath('code', 'otp_invalid');
        }

        $this->verify($challengeId, $wrong)->assertJsonPath('code', 'otp_attempts_exhausted');
        $this->verify($challengeId, $code)->assertStatus(422)->assertJsonPath('code', 'otp_attempts_exhausted');
    }

    #[Test]
    public function an_admin_blocked_between_the_two_steps_is_refused(): void
    {
        $challengeId = $this->login('sara@example.com', 'password')->json('data.challenge_id');
        $this->admin->forceFill(['status' => 'blocked'])->save();

        $this->verify($challengeId, $this->sms->lastCode())
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_blocked');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    #[Test]
    public function an_outdated_password_hash_is_upgraded_at_sign_in(): void
    {
        $this->admin->forceFill(['password' => Hash::make('password', ['rounds' => 4])])->save();
        $old = $this->admin->fresh()->password;

        // The hasher reads its cost when first built, so rebuild it after the change.
        config(['hashing.bcrypt.rounds' => 5]);
        Hash::forgetDrivers();
        $this->assertTrue(Hash::needsRehash($old));

        $this->login('sara@example.com', 'password')->assertStatus(202);

        $hash = $this->admin->fresh()->password;
        $this->assertNotSame($old, $hash);
        $this->assertSame(5, password_get_info($hash)['options']['cost']);
        $this->assertTrue(Hash::check('password', $hash));
    }

    #[Test]
    public function the_admin_sees_their_own_profile(): void
    {
        [$admin, $token] = $this->adminWithToken(AdminRole::Support, AdminRole::Operations);

        $this->withToken($token)->getJson('/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->uuid)
            ->assertJsonPath('data.roles', ['operations', 'support'])
            ->assertJsonPath('data.permissions', fn (array $permissions) => in_array('fulfilment.edit', $permissions, true)
                && in_array('refunds.request', $permissions, true)
                && ! in_array('approvals.decide', $permissions, true));
    }

    #[Test]
    public function logout_revokes_the_token_and_is_audited(): void
    {
        [$admin, $token] = $this->adminWithToken();

        $this->withToken($token)->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        $this->forgetAuthenticatedUsers();

        $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame($admin->id, AuditLog::where('action', 'admin.logout')->sole()->actor_id);
    }

    #[Test]
    public function an_admin_token_expires_after_twelve_hours(): void
    {
        [, $token] = $this->adminWithToken();

        $this->travel(12)->hours();
        $this->travel(1)->seconds();

        $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    #[Test]
    public function blocking_an_admin_stops_their_tokens_at_once(): void
    {
        [$admin, $token] = $this->adminWithToken();
        $admin->forceFill(['status' => 'blocked'])->save();

        $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function login(string $email, string $password, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/login', ['email' => $email, 'password' => $password], $headers);
    }

    private function verify(string $challengeId, string $code): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/verify', ['challenge_id' => $challengeId, 'code' => $code]);
    }
}
