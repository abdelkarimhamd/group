<?php

namespace Database\Factories;

use App\Modules\Identity\Models\Address;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recipient_name' => fake()->name(),
            'recipient_phone' => '+9665'.fake()->numerify('########'),
            'city' => 'الرياض',
            'district' => 'العليا',
            'street' => 'طريق الملك فهد',
            'building_no' => fake()->numerify('####'),
            'postal_code' => fake()->numerify('1####'),
            'additional_no' => null,
            'short_address' => null,
            'lat' => null,
            'lng' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
