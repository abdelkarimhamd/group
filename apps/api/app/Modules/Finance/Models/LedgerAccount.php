<?php

namespace App\Modules\Finance\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class LedgerAccount extends Model
{
    protected $guarded = ['id'];
}
