<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The customer's push targets. A token belongs to whoever signed in on the
 * device last: registering it again refreshes it, from another account
 * moves it. Only the most recently seen devices are kept.
 */
final class DeviceTokens
{
    public function register(User $user, string $platform, string $token): void
    {
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($user, $platform, $token, $now): void {
            DB::statement(
                'INSERT INTO device_tokens (user_id, platform, fcm_token, token_hash, last_seen_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (token_hash) DO UPDATE
                 SET user_id = EXCLUDED.user_id, platform = EXCLUDED.platform, last_seen_at = EXCLUDED.last_seen_at, updated_at = EXCLUDED.updated_at',
                [$user->id, $platform, $token, hash('sha256', $token), $now, $now, $now],
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
