<?php

namespace App\Modules\Identity\Models;

use App\Models\Concerns\AppendOnlyModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer's acceptance of one privacy policy version (PDPL evidence).
 * Append-only: never updated or deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property string $policy_version
 * @property CarbonImmutable $accepted_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 */
class PrivacyConsent extends Model
{
    use AppendOnlyModel;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
