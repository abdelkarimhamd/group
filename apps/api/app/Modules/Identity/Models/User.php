<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\UserStatus;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A customer. Signs in with a Saudi mobile number and an SMS code; there is
 * no password. Exposed in the API by `uuid`, never by `id`.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $phone_e164
 * @property CarbonImmutable|null $phone_verified_at
 * @property string|null $name
 * @property string|null $email
 * @property string $locale
 * @property UserStatus $status
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $anonymized_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PrivacyConsent|null $latestPrivacyConsent
 */
#[Fillable(['phone_e164', 'name', 'email', 'locale'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'locale' => 'ar',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
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
        return $this->status === UserStatus::Active;
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * @return HasMany<PrivacyConsent, $this>
     */
    public function privacyConsents(): HasMany
    {
        return $this->hasMany(PrivacyConsent::class);
    }

    /**
     * @return HasOne<PrivacyConsent, $this>
     */
    public function latestPrivacyConsent(): HasOne
    {
        return $this->hasOne(PrivacyConsent::class)->latestOfMany();
    }

    public function hasAcceptedCurrentPrivacyPolicy(): bool
    {
        return $this->latestPrivacyConsent?->policy_version === config('groupbuy.account.privacy_policy_version');
    }

    /**
     * Customers have no password or "remember me" cookie.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
