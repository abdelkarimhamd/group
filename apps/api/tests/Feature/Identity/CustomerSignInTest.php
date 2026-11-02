<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Fakes\FakeSmsSender;
use Tests\TestCase;

class CustomerSignInTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = $this->fakeSms();
        $this->freezeSecond();
    }

    #[Test]
    public function a_code_is_texted_and_only_its_hash_is_stored(): void
    {
        $response = $this->requestCode('0512345678', ['Accept-Language' => 'ar'])
            ->assertStatus(202)
            ->assertJsonPath('data.expires_at', now()->addMinutes(5)->toIso8601ZuluString())
            ->assertJsonPath('data.resend_available_at', now()->addSeconds(60)->toIso8601ZuluString());

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('+966512345678', $this->sms->sent[0]['to']);
        $this->assertStringContainsString('لا تشاركه', $this->sms->sent[0]['message']);

        $code = $this->sms->lastCode();
        $challenge = OtpChallenge::where('uuid', $response->json('data.challenge_id'))->sole();
        $this->assertSame(OtpPurpose::CustomerLogin, $challenge->purpose);
        $this->assertSame('+966512345678', $challenge->phone_e164);
        $this->assertSame(64, strlen($challenge->code_hash));
        $this->assertStringNotContainsString($code, $challenge->code_hash);
        $this->assertSame('127.0.0.1', $challenge->ip);
    }

    #[Test]
    public function the_sms_follows_the_requested_language(): void
    {
        $this->requestCode('0512345678', ['Accept-Language' => 'en'])->assertStatus(202);

        $this->assertStringContainsString('Never share it', $this->sms->sent[0]['message']);
    }

    #[Test]
    public function arabic_digits_and_local_formats_are_accepted(): void
    {
        $this->requestCode('٠٥١٢٣٤٥٦٧٨')->assertStatus(202);

        $this->assertSame('+966512345678', $this->sms->sent[0]['to']);
    }

    #[Test]
    public function landlines_and_foreign_numbers_are_refused(): void
    {
        $this->requestCode('0112345678')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('phone');

        $this->requestCode('+971512345678')->assertStatus(422);
        $this->postJson('/api/v1/auth/otp', ['phone' => ['0512345678']])->assertStatus(422);

        $this->assertSame([], $this->sms->sent);
    }

    #[Test]
    public function a_number_gets_one_code_a_minute(): void
    {
        $this->requestCode('0512345678')->assertStatus(202);

        $this->requestCode('0512345678')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After', '60');

        // Another number is not affected.
        $this->requestCode('0598765432')->assertStatus(202);

        $this->travel(61)->seconds();
        $this->requestCode('0512345678')->assertStatus(202);
    }

    #[Test]
    public function a_number_gets_five_codes_an_hour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->requestCode('0512345678')->assertStatus(202);
            $this->travel(61)->seconds();
        }

        $this->requestCode('0512345678')->assertStatus(429);

        $this->travel(1)->hours();
        $this->requestCode('0512345678')->assertStatus(202);
    }

    #[Test]
    public function an_ip_address_gets_twenty_codes_an_hour(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->requestCode(sprintf('05000000%02d', $i))->assertStatus(202);
        }

        $this->requestCode('0599999999')->assertStatus(429);
    }

    #[Test]
    public function an_sms_outage_is_a_503(): void
    {
        $this->sms->failing = true;

        $this->requestCode('0512345678', ['Accept-Language' => 'en'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'sms_unavailable')
            ->assertJsonPath('message', 'We could not send the code right now. Please try again in a few minutes.');
    }

    #[Test]
    public function the_first_sign_in_creates_the_account(): void
    {
        $challengeId = $this->requestCode('0512345678', ['Accept-Language' => 'en'])->json('data.challenge_id');

        $response = $this->verify($challengeId, $this->sms->lastCode(), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.user.phone', '+966512345678')
            ->assertJsonPath('data.user.locale', 'en')
            ->assertJsonPath('data.user.privacy_consent', null)
            ->assertJsonPath('data.user.current_privacy_policy_version', '2026-11');

        $user = User::sole();
        $this->assertSame($user->uuid, $response->json('data.user.id'));
        $this->assertTrue($user->phone_verified_at->equalTo(now()));
        $this->assertTrue($user->last_login_at->equalTo(now()));

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame('iPhone Safari', $token->name);
        $this->assertTrue($token->expires_at->equalTo(now()->addDays(90)));

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->uuid);
    }

    #[Test]
    public function a_returning_customer_signs_in_to_the_same_account(): void
    {
        $user = User::factory()->create(['phone_e164' => '+966512345678', 'name' => 'نورة', 'locale' => 'ar']);
        $challengeId = $this->requestCode('0512345678')->json('data.challenge_id');

        $this->verify($challengeId, $this->sms->lastCode(), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false)
            ->assertJsonPath('data.user.id', $user->uuid)
            ->assertJsonPath('data.user.name', 'نورة')
            ->assertJsonPath('data.user.locale', 'ar');

        $this->assertSame(1, User::count());
    }

    #[Test]
    public function a_wrong_code_is_counted_and_the_fifth_spends_the_challenge(): void
    {
        $challengeId = $this->requestCode('0512345678')->json('data.challenge_id');
        $code = $this->sms->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $this->verify($challengeId, $wrong, ['Accept-Language' => 'en'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'otp_invalid')
                ->assertJsonPath('errors.code.0', 'That code is not right. Check the SMS and try again.');
        }

        $this->verify($challengeId, $wrong)->assertStatus(422)->assertJsonPath('code', 'otp_attempts_exhausted');
        $this->assertSame(5, OtpChallenge::sole()->attempts);

        // Even the right code no longer works.
        $this->verify($challengeId, $code)->assertStatus(422)->assertJsonPath('code', 'otp_attempts_exhausted');
        $this->assertSame(0, User::count());
    }

    #[Test]
    public function a_code_expires_after_five_minutes(): void
    {
        $challengeId = $this->requestCode('0512345678')->json('data.challenge_id');
        $code = $this->sms->lastCode();

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->verify($challengeId, $code)->assertStatus(422)->assertJsonPath('code', 'otp_expired');
    }

    #[Test]
    public function a_code_works_once(): void
    {
        $challengeId = $this->requestCode('0512345678')->json('data.challenge_id');
        $code = $this->sms->lastCode();

        $this->verify($challengeId, $code)->assertOk();
        $this->verify($challengeId, $code)->assertStatus(422)->assertJsonPath('code', 'otp_invalid');
    }

    #[Test]
    public function an_earlier_code_still_works_after_a_resend(): void
    {
        $first = $this->requestCode('0512345678')->json('data.challenge_id');
        $firstCode = $this->sms->lastCode();

        $this->travel(61)->seconds();
        $this->requestCode('0512345678')->assertStatus(202);

        $this->verify($first, $firstCode)->assertOk();
    }

    #[Test]
    public function an_admin_sign_in_code_cannot_sign_in_a_customer(): void
    {
        $admin = AdminUser::factory()->create(['phone_e164' => '+966512345678']);
        $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertStatus(202);
        $challenge = OtpChallenge::sole();

        $this->verify($challenge->uuid, $this->sms->lastCode())
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_invalid');

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function a_blocked_customer_cannot_sign_in(): void
    {
        $user = User::factory()->blocked()->create(['phone_e164' => '+966512345678']);
        $challengeId = $this->requestCode('0512345678')->json('data.challenge_id');

        $this->verify($challengeId, $this->sms->lastCode())
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_blocked');

        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function verify_input_is_validated(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => 'nope', 'code' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['challenge_id', 'code', 'device_name']);
    }

    #[Test]
    public function validation_messages_are_localized(): void
    {
        $this->postJson('/api/v1/auth/otp', [], ['Accept-Language' => 'ar'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يرجى مراجعة الحقول المحددة.')
            ->assertJsonPath('errors.phone.0', 'حقل رقم الجوال مطلوب.');
    }

    #[Test]
    public function verify_is_limited_per_ip(): void
    {
        config(['groupbuy.auth.otp.verify_per_ip_per_minute' => 3]);
        $challengeId = $this->requestCode('0512345678')->json('data.challenge_id');

        for ($i = 0; $i < 3; $i++) {
            $this->verify($challengeId, '000000');
        }

        $this->verify($challengeId, $this->sms->lastCode())->assertStatus(429);
    }

    #[Test]
    public function logout_revokes_only_the_current_token(): void
    {
        [$user, $phone] = $this->customerWithToken();
        [, $laptop] = $this->customerWithToken($user);

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->forgetAuthenticatedUsers();

        $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
        $this->forgetAuthenticatedUsers();
        $this->withToken($laptop)->getJson('/api/v1/me')->assertOk();
    }

    #[Test]
    public function a_customer_token_expires_after_ninety_days(): void
    {
        [, $token] = $this->customerWithToken();

        $this->travel(90)->days();
        $this->travel(1)->seconds();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function blocking_a_customer_stops_their_tokens_at_once(): void
    {
        [$user, $token] = $this->customerWithToken();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $user->forceFill(['status' => 'blocked'])->save();
        $this->forgetAuthenticatedUsers();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function customer_and_admin_tokens_only_open_their_own_routes(): void
    {
        [, $customerToken] = $this->customerWithToken();
        [, $adminToken] = $this->adminWithToken();

        $this->withToken($adminToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->forgetAuthenticatedUsers();
        $this->withToken($customerToken)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    #[Test]
    public function a_missing_token_is_a_401_in_the_requested_language(): void
    {
        $this->getJson('/api/v1/me', ['Accept-Language' => 'en'])
            ->assertUnauthorized()
            ->assertHeader('Content-Language', 'en')
            ->assertExactJson(['message' => 'Please sign in to continue.', 'code' => 'unauthenticated']);

        $this->getJson('/api/v1/me', ['Accept-Language' => 'ar'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'يرجى تسجيل الدخول للمتابعة.');
    }

    #[Test]
    public function rate_limit_errors_are_in_the_requested_language(): void
    {
        $this->requestCode('0512345678')->assertStatus(202);

        $this->requestCode('0512345678', ['Accept-Language' => 'en'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many attempts. Please wait and try again.');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function requestCode(string $phone, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/otp', ['phone' => $phone], $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function verify(string $challengeId, string $code, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
            'device_name' => 'iPhone Safari',
        ], $headers);
    }
}
