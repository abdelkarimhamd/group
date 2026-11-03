<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationStatus;
use App\Modules\Notifications\Enums\NotificationTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one customer on one channel, and what became of it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property NotificationChannel $channel
 * @property NotificationTemplate $template_key
 * @property string $locale
 * @property array<string, mixed> $payload
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $dedupe_key
 * @property NotificationStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property string|null $last_error
 * @property string|null $provider_message_id
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 */
class Notification extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'template_key' => NotificationTemplate::class,
            'payload' => 'array',
            'status' => NotificationStatus::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
