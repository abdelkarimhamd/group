<?php

namespace Database\Factories;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone_e164' => '+9665'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
            'name' => fake()->name(),
            'email' => null,
            'locale' => 'ar',
            'status' => UserStatus::Active,
        ];
    }

    public function blocked(): static
    {
        return $this->state(['status' => UserStatus::Blocked]);
    }
}
