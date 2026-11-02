<?php

namespace App\Modules\Supplier\Models;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Supplier\Enums\SupplierStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Alibaba seller. Only approved suppliers can back a campaign.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $alibaba_store_url
 * @property string $country
 * @property string|null $contact_name
 * @property string|null $contact_channel
 * @property SupplierStatus $status
 * @property int|null $quality_rating
 * @property string|null $review_notes
 * @property int|null $reviewed_by_admin_id
 * @property CarbonImmutable|null $reviewed_at
 * @property int $created_by_admin_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AdminUser|null $reviewer
 */
#[Fillable(['name', 'alibaba_store_url', 'country', 'contact_name', 'contact_channel', 'quality_rating', 'review_notes'])]
#[UseFactory(SupplierFactory::class)]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'prospect',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierStatus::class,
            'quality_rating' => 'integer',
            'reviewed_at' => 'immutable_datetime',
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

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_id');
    }
}
