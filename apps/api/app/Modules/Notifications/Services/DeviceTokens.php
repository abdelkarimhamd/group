<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The customer's push targets. A token belongs to the sign-in on the
 * device that registered it last: registering it again refreshes it, from
 * another account moves it, and signing out removes it (foreign key to the
 * access token). Only the most recently seen devices are kept.
 */
final class DeviceTokens
{
    public function register(User $user, ?int $accessTokenId, string $platform, string $token): void
    {
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($user, $accessTokenId, $platform, $token, $now): void {
            DB::statement(
                'INSERT INTO device_tokens (user_id, personal_access_token_id, platform, fcm_token, token_hash, last_seen_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (token_hash) DO UPDATE
                 SET user_id = EXCLUDED.user_id, personal_access_token_id = EXCLUDED.personal_access_token_id, platform = EXCLUDED.platform,
                     last_seen_at = EXCLUDED.last_seen_at, updated_at = EXCLUDED.updated_at',
                [$user->id, $accessTokenId, $platform, $token, hash('sha256', $token), $now, $now, $now],
            );

            DB::delete(
                'DELETE FROM device_tokens WHERE user_id = ? AND id NOT IN (
                    SELECT id FROM device_tokens WHERE user_id = ? ORDER BY last_seen_at DESC, id DESC LIMIT ?
                 )',
                [$user->id, $user->id, (int) config('groupbuy.notifications.max_devices_per_user')],
            );
        });
    }
}
