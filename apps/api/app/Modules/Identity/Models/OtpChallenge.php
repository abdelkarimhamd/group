<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\OtpPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One sign-in code sent by SMS. Holds an HMAC of the code, never the code.
 * Rows are pruned after config('groupbuy.auth.otp.prune_after_days').
 *
 * @property int $id
 * @property string $uuid
 * @property OtpPurpose $purpose
 * @property string $phone_e164
 * @property int|null $admin_user_id
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $consumed_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class OtpChallenge extends Model
{
    use MassPrunable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('groupbuy.auth.otp.prune_after_days')));
    }
}
