<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\Address;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AddressesTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->user, $this->token] = $this->customerWithToken();
    }

    #[Test]
    public function the_first_address_becomes_the_default(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/me/addresses', $this->input())
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.recipient_phone', '+966512345678')
            ->assertJsonPath('data.lat', 24.7136)
            ->assertJsonPath('data.lng', 46.6753);

        $this->assertSame($this->user->id, Address::where('uuid', $response->json('data.id'))->sole()->user_id);
    }

    #[Test]
    public function a_new_default_replaces_the_old_one(): void
    {
        $first = $this->create();
        $second = $this->create(['is_default' => true]);
        $third = $this->create();

        $this->assertFalse($third['is_default']);

        $this->withToken($this->token)->getJson('/api/v1/me/addresses')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $second['id'])
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.id', $third['id'])
            ->assertJsonPath('data.2.id', $first['id'])
            ->assertJsonPath('data.2.is_default', false);
    }

    #[Test]
    public function an_update_without_is_default_keeps_the_default(): void
    {
        $address = $this->create();

        $this->withToken($this->token)
            ->patchJson("/api/v1/me/addresses/{$address['id']}", $this->input(['street' => 'شارع التحلية']))
            ->assertOk()
            ->assertJsonPath('data.street', 'شارع التحلية')
            ->assertJsonPath('data.is_default', true);
    }

    #[Test]
    public function an_update_can_move_the_default(): void
    {
        $first = $this->create();
        $second = $this->create();

        $this->withToken($this->token)
            ->patchJson("/api/v1/me/addresses/{$second['id']}", $this->input(['is_default' => true]))
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse(Address::where('uuid', $first['id'])->sole()->is_default);
        $this->assertSame(1, Address::where('is_default', true)->count());
    }

    #[Test]
    public function deleting_the_default_promotes_the_newest_address(): void
    {
        $first = $this->create();
        $second = $this->create();
        $third = $this->create();

        $this->withToken($this->token)->deleteJson("/api/v1/me/addresses/{$first['id']}")->assertNoContent();

        $this->assertNull(Address::where('uuid', $first['id'])->first());
        $this->assertTrue(Address::where('uuid', $third['id'])->sole()->is_default);
        $this->assertFalse(Address::where('uuid', $second['id'])->sole()->is_default);
    }

    #[Test]
    public function another_customers_address_is_not_found(): void
    {
        $theirs = Address::factory()->default()->create();

        $this->withToken($this->token)->getJson('/api/v1/me/addresses')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->token)
            ->patchJson("/api/v1/me/addresses/{$theirs->uuid}", $this->input())
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        $this->withToken($this->token)->deleteJson("/api/v1/me/addresses/{$theirs->uuid}")->assertNotFound();

        $this->assertNotNull($theirs->fresh());
    }

    #[Test]
    public function local_formats_and_arabic_digits_are_normalized(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/me/addresses', $this->input([
            'recipient_phone' => '٠٥٩٨٧٦٥٤٣٢',
            'building_no' => '٢٤٠١',
            'postal_code' => '١٢٢١١',
            'short_address' => 'rrrd 2929',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.recipient_phone', '+966598765432')
            ->assertJsonPath('data.building_no', '2401')
            ->assertJsonPath('data.postal_code', '12211')
            ->assertJsonPath('data.short_address', 'RRRD2929');
    }

    #[Test]
    public function national_address_fields_are_validated(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/me/addresses', $this->input([
            'recipient_phone' => '0112345678',
            'building_no' => '12',
            'postal_code' => 'ABCDE',
            'lat' => 24.7,
            'lng' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_phone', 'building_no', 'postal_code', 'lng']);

        $this->withToken($this->token)->postJson('/api/v1/me/addresses', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_name', 'city', 'district', 'street']);
    }

    #[Test]
    public function the_number_of_addresses_is_capped(): void
    {
        config(['groupbuy.account.max_addresses' => 2]);
        $this->create();
        $this->create();

        $this->withToken($this->token)->postJson('/api/v1/me/addresses', $this->input())
            ->assertStatus(422)
            ->assertJsonPath('code', 'address_limit_reached');
    }

    #[Test]
    public function a_malformed_address_id_is_not_found(): void
    {
        $this->withToken($this->token)->deleteJson('/api/v1/me/addresses/not-a-uuid')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function create(array $overrides = []): array
    {
        return $this->withToken($this->token)
            ->postJson('/api/v1/me/addresses', $this->input($overrides))
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'recipient_name' => 'نورة العتيبي',
            'recipient_phone' => '+966512345678',
            'city' => 'الرياض',
            'district' => 'العليا',
            'street' => 'طريق الملك فهد',
            'building_no' => '2401',
            'postal_code' => '12211',
            'additional_no' => '3344',
            'short_address' => 'RRRD2929',
            'lat' => 24.7136,
            'lng' => 46.6753,
        ], $overrides);
    }
}
