<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\InvoiceType;
use App\Modules\Orders\Enums\ZatcaStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A VAT invoice or credit note. Issued once: a database trigger refuses any
 * change to its number, parties, lines or amounts, and any delete. Only the
 * ZATCA status and the stored files change later.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_order_id
 * @property InvoiceType $type
 * @property string $invoice_number
 * @property int|null $original_invoice_id
 * @property CarbonImmutable $issued_at
 * @property array<string, mixed> $seller_snapshot
 * @property list<array<string, mixed>> $lines
 * @property int $net_halalas
 * @property int $vat_halalas
 * @property int $total_halalas
 * @property int $vat_rate_bp
 * @property string $currency
 * @property string $zatca_uuid
 * @property ZatcaStatus $zatca_status
 * @property string $qr_payload ZATCA QR code contents (base64 TLV).
 * @property string|null $xml_s3_key
 * @property string|null $pdf_s3_key
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CustomerOrder $order
 * @property-read Invoice|null $original The invoice a credit note is against.
 */
class Invoice extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'zatca_status' => 'pending',
        'currency' => 'SAR',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'zatca_status' => ZatcaStatus::class,
            'issued_at' => 'immutable_datetime',
            'seller_snapshot' => 'array',
            'lines' => 'array',
            'net_halalas' => 'integer',
            'vat_halalas' => 'integer',
            'total_halalas' => 'integer',
            'vat_rate_bp' => 'integer',
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
     * @return BelongsTo<CustomerOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    /**
     * The invoice a credit note is against.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }
}
