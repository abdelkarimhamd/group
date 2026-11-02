<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\AdminStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A member of staff using the admin portal. Signs in with email and password,
 * then an SMS code sent to phone_e164. Roles and permissions use the `admin`
 * guard.
 *
 * @property int $id
 * @property string $uuid
 * @property string $email
 * @property string $password
 * @property string $name
 * @property string $phone_e164
 * @property AdminStatus $status
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['email', 'password', 'name', 'phone_e164'])]
#[Hidden(['password'])]
#[UseFactory(AdminUserFactory::class)]
class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids;

    public const GUARD = 'admin';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => AdminStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The guard that owns this model's roles and permissions.
     */
    public function guardName(): string
    {
        return self::GUARD;
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isActive(): bool
    {
        return $this->status === AdminStatus::Active;
    }

    /**
     * Emails are stored lower-case (a CHECK constraint enforces it), so lookups
     * never depend on how the admin typed theirs.
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
