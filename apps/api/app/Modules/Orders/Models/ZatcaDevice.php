<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Zatca\Certificate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One of our EGS units (e-invoice generation solutions) registered with
 * ZATCA: its key, its CSIDs and its invoice chain. The key and the
 * secrets are encrypted with APP_KEY.
 *
 * @property int $id
 * @property string $uuid
 * @property string $environment developer-portal, simulation or core
 * @property string $status onboarding, active or retired
 * @property string $egs_serial
 * @property string $common_name
 * @property string $private_key PEM
 * @property string $csr PEM
 * @property string|null $compliance_request_id
 * @property string|null $compliance_certificate base64 text
 * @property string|null $compliance_secret
 * @property string|null $certificate base64 text of the production CSID
 * @property string|null $secret
 * @property CarbonImmutable|null $certificate_expires_at
 * @property int $icv the counter of the last document signed
 * @property string $last_hash the hash of the last document signed
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ZatcaDevice extends Model
{
    use HasUuids;

    public const ONBOARDING = 'onboarding';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $guarded = ['id', 'uuid'];

    /** @var list<string> */
    protected $hidden = ['private_key', 'compliance_secret', 'secret'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'compliance_secret' => 'encrypted',
            'secret' => 'encrypted',
            'icv' => 'integer',
            'certificate_expires_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
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
     * The unit that signs now, in the configured environment.
     *
     * @param  Builder<ZatcaDevice>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::ACTIVE)->where('environment', (string) config('groupbuy.zatca.environment'));
    }

    /**
     * The production CSID, which signs invoices and reports them.
     */
    public function productionCertificate(): Certificate
    {
        return Certificate::fromBase64($this->certificate ?? throw new LogicException("EGS unit {$this->uuid} has no production certificate."));
    }

    /**
     * The compliance CSID, for the compliance checks while onboarding.
     */
    public function complianceCertificate(): Certificate
    {
        return Certificate::fromBase64($this->compliance_certificate ?? throw new LogicException("EGS unit {$this->uuid} has no compliance certificate."));
    }
}
