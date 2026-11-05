<?php

namespace App\Modules\Orders\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice's e-invoice (ZATCA phase 2): opened with the invoice, signed
 * after the commit by the active EGS unit, then reported to Fatoora. The
 * invoice's zatca_status says where reporting stands. Once signed, its
 * place in the chain and its XML never change (a trigger refuses it).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int|null $zatca_device_id
 * @property int|null $icv
 * @property string|null $previous_hash
 * @property string|null $invoice_hash
 * @property string|null $signed_xml
 * @property string|null $qr the phase 2 QR code (base64 TLV, tags 1 to 9)
 * @property CarbonImmutable|null $signed_at
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $reported_at
 * @property int|null $response_status
 * @property array<string, mixed>|null $response
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Invoice $invoice
 * @property-read ZatcaDevice|null $device
 */
class ZatcaInvoiceStamp extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'icv' => 'integer',
            'signed_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'reported_at' => 'immutable_datetime',
            'response_status' => 'integer',
            'response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<ZatcaDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ZatcaDevice::class, 'zatca_device_id');
    }
}
