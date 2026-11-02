<?php

namespace App\Modules\Payments\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook as received. The payload is never edited; only processed_at and
 * processing_error change, once.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string|null $event_type
 * @property string|null $provider_payment_id
 * @property bool $signature_valid
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $processing_error
 */
class PaymentWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
