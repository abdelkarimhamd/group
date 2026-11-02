<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\InvoiceType;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Support\ZatcaQr;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;

/**
 * VAT invoices (spec, Orders and invoices: invoices). The company is the
 * seller of record, so every order gets a simplified tax invoice when it is
 * made. Lines are VAT-inclusive; each line's VAT is split with the spec's
 * rounding rule and the invoice totals are the sums of its lines.
 *
 * Rendering the Arabic and English PDF and the ZATCA XML, and reporting to
 * Fatoora, come with the integrations; until then the files are empty and
 * zatca_status stays pending.
 */
final class Invoices
{
    /**
     * The defaults in config/groupbuy.php, which mark invoices made before
     * the real registration exists. 300000000000003 has the shape of a
     * real VAT number, so nothing else would flag it.
     */
    private const PLACEHOLDERS = [
        'legal_name_ar' => 'اسم البائع غير محدد',
        'legal_name_en' => 'Seller name not set',
        'vat_number' => '300000000000003',
    ];

    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly StatusHistory $history,
    ) {}

    /**
     * Issue the order's tax invoice. Call in the transaction that creates the order.
     *
     * @param  list<array{description_ar: string, description_en: string, qty: int, unit_price_halalas: int}>  $items
     */
    public function issueTaxInvoice(CustomerOrder $order, array $items, Actor $actor): Invoice
    {
        $rate = $order->vat_rate_bp;
        $lines = [];
        $net = Money::zero();
        $vat = Money::zero();

        foreach ($items as $item) {
            $total = Money::sar($item['unit_price_halalas'])->times($item['qty']);
            $split = $total->splitVatInclusive($rate);
            $lines[] = $item + [
                'net_halalas' => $split['net']->minor,
                'vat_halalas' => $split['vat']->minor,
                'total_halalas' => $total->minor,
                'vat_rate_bp' => $rate,
            ];
            $net = $net->plus($split['net']);
            $vat = $vat->plus($split['vat']);
        }

        $total = $net->plus($vat);
        $seller = self::seller();
        $issuedAt = CarbonImmutable::now();

        $invoice = Invoice::create([
            'customer_order_id' => $order->id,
            'type' => InvoiceType::SimplifiedTaxInvoice,
            'invoice_number' => $this->numbers->invoice(),
            'issued_at' => $issuedAt,
            'seller_snapshot' => $seller,
            'lines' => $lines,
            'net_halalas' => $net->minor,
            'vat_halalas' => $vat->minor,
            'total_halalas' => $total->minor,
            'vat_rate_bp' => $rate,
            'zatca_uuid' => (string) Str::uuid(),
            'qr_payload' => ZatcaQr::encode($seller['legal_name_ar'], $seller['vat_number'], $issuedAt, $total, $vat),
        ]);
        $this->history->record($invoice, null, 'issued', $actor);

        return $invoice;
    }

    /**
     * Production never issues a tax invoice with the placeholder seller:
     * invoices cannot be changed, so each one would need a credit note.
     * Checked when the app boots, because an invoice is issued after the
     * money is taken and must not fail then.
     *
     * @throws LogicException
     */
    public static function assertSellerConfigured(): void
    {
        $seller = self::seller();
        $unset = array_keys(array_filter(
            self::PLACEHOLDERS,
            fn (string $placeholder, string $field) => trim($seller[$field]) === '' || $seller[$field] === $placeholder,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($unset !== [] || preg_match('/^3\d{13}3$/', $seller['vat_number']) !== 1) {
            throw new LogicException('Set the seller of record for VAT invoices (GROUPBUY_SELLER_NAME_AR, GROUPBUY_SELLER_NAME_EN and GROUPBUY_SELLER_VAT_NUMBER, a 15-digit number starting and ending in 3) before running in production.');
        }
    }

    /**
     * @return array{legal_name_ar: string, legal_name_en: string, vat_number: string, cr_number: string|null, address_ar: string, address_en: string}
     */
    private static function seller(): array
    {
        $seller = (array) config('groupbuy.seller');

        return [
            'legal_name_ar' => (string) $seller['legal_name_ar'],
            'legal_name_en' => (string) $seller['legal_name_en'],
            'vat_number' => (string) $seller['vat_number'],
            'cr_number' => $seller['cr_number'] === null ? null : (string) $seller['cr_number'],
            'address_ar' => (string) $seller['address_ar'],
            'address_en' => (string) $seller['address_en'],
        ];
    }
}
