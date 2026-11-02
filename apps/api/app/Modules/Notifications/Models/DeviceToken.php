<?php

namespace App\Modules\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Firebase push target on one of the customer's devices.
 *
 * @property int $id
 * @property int $user_id
 * @property string $platform ios, android or web
 * @property string $fcm_token
 * @property string $token_hash SHA-256 of the token, unique
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class DeviceToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['fcm_token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
