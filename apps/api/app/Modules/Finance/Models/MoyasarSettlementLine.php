<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AppendOnlyModel;
use App\Modules\Payments\Models\PaymentAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a Moyasar settlement: a capture, refund, fee or charge.
 *
 * @property int $id
 * @property int $moyasar_settlement_id
 * @property string $type payment, refund, void, fee, chargeback, … (Moyasar's open list)
 * @property string|null $provider_payment_id
 * @property int|null $payment_authorization_id null when we have no such payment
 * @property int $amount_halalas net of the fee; negative for money going back
 * @property int $fee_halalas VAT included
 * @property int $fee_vat_halalas
 * @property int|null $payment_amount_halalas
 * @property CarbonImmutable|null $transacted_at
 * @property array<string, mixed> $raw
 * @property-read PaymentAuthorization|null $payment
 */
class MoyasarSettlementLine extends Model
{
    use AppendOnlyModel;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_halalas' => 'integer',
            'fee_halalas' => 'integer',
            'fee_vat_halalas' => 'integer',
            'payment_amount_halalas' => 'integer',
            'transacted_at' => 'immutable_datetime',
            'raw' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PaymentAuthorization, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'payment_authorization_id');
    }
}
