<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\PrivacyConsent;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->user, $this->token] = $this->customerWithToken(
            User::factory()->create(['phone_e164' => '+966512345678', 'name' => 'نورة', 'email' => null]),
        );
    }

    #[Test]
    public function a_customer_sees_their_profile(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $this->user->uuid,
                'phone' => '+966512345678',
                'name' => 'نورة',
                'email' => null,
                'locale' => 'ar',
                'privacy_consent' => null,
                'current_privacy_policy_version' => '2026-11',
                'created_at' => $this->user->created_at->toIso8601ZuluString(),
            ]]);
    }

    #[Test]
    public function a_customer_changes_name_email_and_language(): void
    {
        $this->withToken($this->token)
            ->patchJson('/api/v1/me', ['name' => 'Noura', 'email' => 'noura@example.com', 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Noura')
            ->assertJsonPath('data.email', 'noura@example.com')
            ->assertJsonPath('data.locale', 'en');

        $this->withToken($this->token)->patchJson('/api/v1/me', ['name' => null])
            ->assertOk()
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.email', 'noura@example.com');

        $this->assertNull($this->user->fresh()->name);
    }

    #[Test]
    public function the_phone_number_cannot_be_changed_here(): void
    {
        $this->withToken($this->token)->patchJson('/api/v1/me', ['phone' => '+966598765432', 'name' => 'x'])->assertOk();

        $this->assertSame('+966512345678', $this->user->fresh()->phone_e164);
    }

    #[Test]
    public function a_profile_update_needs_a_valid_field(): void
    {
        $this->withToken($this->token)->patchJson('/api/v1/me', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->withToken($this->token)->patchJson('/api/v1/me', ['locale' => 'fr', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['locale', 'email']);
    }

    #[Test]
    public function consent_to_the_current_privacy_policy_is_recorded_once(): void
    {
        $this->freezeSecond();

        $this->withToken($this->token)
            ->postJson('/api/v1/me/privacy-consent', ['policy_version' => '2026-11'], ['X-Request-Id' => 'consent-req-1'])
            ->assertOk()
            ->assertJsonPath('data.privacy_consent.policy_version', '2026-11')
            ->assertJsonPath('data.privacy_consent.accepted_at', now()->toIso8601ZuluString());

        $consent = PrivacyConsent::sole();
        $this->assertSame($this->user->id, $consent->user_id);
        $this->assertSame('127.0.0.1', $consent->ip);
        $this->assertSame('consent-req-1', $consent->request_id);

        $this->withToken($this->token)->postJson('/api/v1/me/privacy-consent', ['policy_version' => '2026-11'])->assertOk();
        $this->assertSame(1, PrivacyConsent::count());
    }

    #[Test]
    public function a_new_policy_version_asks_for_consent_again(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/me/privacy-consent', ['policy_version' => '2026-11'])->assertOk();

        config(['groupbuy.account.privacy_policy_version' => '2027-03']);

        $this->withToken($this->token)->getJson('/api/v1/me')
            ->assertJsonPath('data.privacy_consent.policy_version', '2026-11')
            ->assertJsonPath('data.current_privacy_policy_version', '2027-03');

        $this->withToken($this->token)->postJson('/api/v1/me/privacy-consent', ['policy_version' => '2027-03'])
            ->assertOk()
            ->assertJsonPath('data.privacy_consent.policy_version', '2027-03');

        $this->assertSame(2, PrivacyConsent::count());
    }

    #[Test]
    public function consent_to_an_old_policy_version_is_refused(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/me/privacy-consent', ['policy_version' => '2025-01'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'privacy_policy_outdated')
            ->assertJsonValidationErrors('policy_version');

        $this->assertSame(0, PrivacyConsent::count());
    }

    #[Test]
    public function consent_records_cannot_be_changed_or_deleted(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/me/privacy-consent', ['policy_version' => '2026-11'])->assertOk();

        foreach (["UPDATE privacy_consents SET policy_version = 'forged'", 'DELETE FROM privacy_consents'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("The database allowed: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }
}
