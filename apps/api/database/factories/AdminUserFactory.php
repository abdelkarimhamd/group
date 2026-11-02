<?php

namespace Database\Factories;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Enums\AdminStatus;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Permissions\AdminPermissions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    /**
     * Hashing is slow; every factory admin shares one hash of "password".
     */
    protected static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$passwordHash ??= bcrypt('password'),
            'name' => fake()->name(),
            'phone_e164' => '+9665'.fake()->unique()->numerify('########'),
            'status' => AdminStatus::Active,
        ];
    }

    public function blocked(): static
    {
        return $this->state(['status' => AdminStatus::Blocked]);
    }

    public function withRole(AdminRole ...$roles): static
    {
        return $this->afterCreating(function (AdminUser $admin) use ($roles): void {
            AdminPermissions::sync();
            $admin->assignRole(array_map(fn (AdminRole $role) => $role->value, $roles));
        });
    }
}
