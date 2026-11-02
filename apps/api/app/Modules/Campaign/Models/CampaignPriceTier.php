<?php

namespace App\Modules\Campaign\Models;

use App\Models\Concerns\AppendOnlyModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One tier of one version of a campaign's prices. Rows are never changed:
 * each save inserts a full new version (the table is append-only).
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $version
 * @property int $min_qty
 * @property int $max_qty
 * @property int $unit_price_halalas VAT-inclusive.
 * @property string $currency
 * @property int $created_by_admin_id
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 */
class CampaignPriceTier extends Model
{
    use AppendOnlyModel;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'min_qty' => 'integer',
            'max_qty' => 'integer',
            'unit_price_halalas' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
