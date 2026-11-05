<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\InvoiceType;
use App\Modules\Orders\Jobs\RenderInvoicePdf;
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
 * The Arabic and English PDF is rendered on the queue after the commit
 * (RenderInvoicePdf). While ZATCA phase 2 is on, the invoice is signed and
 * reported to Fatoora after the commit (ZatcaReporting), and its PDF waits
 * for the signature, which its QR code carries.
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
        private readonly ZatcaReporting $zatca,
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

        if (! $this->zatca->open($invoice)) {
            RenderInvoicePdf::dispatch($invoice->id)->afterCommit();
        }

        return $invoice;
    }

    /**
     * A credit note against the order's tax invoice, for money returned.
     * A first refund of the whole invoice repeats its lines; any other
     * refund is one VAT-inclusive line. Never more than the invoice minus
     * the credit notes already issued, and never more VAT or net than is
     * left on it: the notes of an order never credit more VAT than the
     * invoice charged, and the last one takes exactly what is left. Call in
     * the transaction that records the refund.
     */
    public function issueCreditNote(CustomerOrder $order, int $amountHalalas, Actor $actor): Invoice
    {
        $original = Invoice::query()
            ->where('customer_order_id', $order->id)
            ->where('type', InvoiceType::SimplifiedTaxInvoice)
            ->firstOrFail();
        $notes = Invoice::query()
            ->where('customer_order_id', $order->id)
            ->where('type', InvoiceType::CreditNote);
        $credited = (int) (clone $notes)->sum('total_halalas');
        $creditedVat = (int) (clone $notes)->sum('vat_halalas');
        $amount = Money::sar($amountHalalas);

        if (! $amount->greaterThan(Money::zero()) || $amount->greaterThan(Money::sar($original->total_halalas - $credited))) {
            throw new LogicException("A credit note of {$amountHalalas} does not fit invoice {$original->invoice_number}.");
        }

        $rate = $original->vat_rate_bp;

        if ($credited === 0 && $amountHalalas === $original->total_halalas) {
            $lines = $original->lines;
            $net = Money::sar($original->net_halalas);
            $vat = Money::sar($original->vat_halalas);
        } else {
            // Split at the invoice's rate, then keep both parts within what is
            // left on the invoice. Rounding each note on its own could otherwise
            // credit a halala more VAT than was charged, and leave the last
            // note negative. For the last note both bounds meet: it takes
            // exactly the VAT and net left, so the notes add up to the invoice.
            $vatLeft = $original->vat_halalas - $creditedVat;
            $netLeft = ($original->total_halalas - $credited) - $vatLeft;
            $vatHalalas = max(0, $amountHalalas - $netLeft, min($amount->splitVatInclusive($rate)['vat']->minor, $vatLeft, $amountHalalas));
            $vat = Money::sar($vatHalalas);
            $net = $amount->minus($vat);

            $lines = [[
                'description_ar' => 'استرداد مبلغ من الطلب '.$order->order_number,
                'description_en' => 'Refund on order '.$order->order_number,
                'qty' => 1,
                'unit_price_halalas' => $amount->minor,
                'net_halalas' => $net->minor,
                'vat_halalas' => $vat->minor,
                'total_halalas' => $amount->minor,
                'vat_rate_bp' => $rate,
            ]];
        }

        $seller = self::seller();
        $issuedAt = CarbonImmutable::now();
        $total = $net->plus($vat);

        $note = Invoice::create([
            'customer_order_id' => $order->id,
            'type' => InvoiceType::CreditNote,
            'invoice_number' => $this->numbers->invoice(creditNote: true),
            'original_invoice_id' => $original->id,
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
        $this->history->record($note, null, 'issued', $actor);

        if (! $this->zatca->open($note)) {
            RenderInvoicePdf::dispatch($note->id)->afterCommit();
        }

        return $note;
    }

    /**
     * Why the configured seller of record cannot go on a tax invoice, or
     * null when it can. Invoices cannot be changed, so in production
     * nothing is captured while this answers (Captures checks it before
     * any money is taken; the invoice itself is issued after).
     */
    public static function sellerProblem(): ?string
    {
        $seller = self::seller();
        $unset = array_filter(
            self::PLACEHOLDERS,
            fn (string $placeholder, string $field) => trim($seller[$field]) === '' || $seller[$field] === $placeholder,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($unset !== [] || preg_match('/^3\d{13}3$/', $seller['vat_number']) !== 1) {
            return 'Set the seller of record for VAT invoices: GROUPBUY_SELLER_NAME_AR, GROUPBUY_SELLER_NAME_EN and GROUPBUY_SELLER_VAT_NUMBER (15 digits, starting and ending in 3).';
        }

        return null;
    }

    /**
     * The seller of record as an invoice keeps it: today's settings.
     *
     * @return array{legal_name_ar: string, legal_name_en: string, vat_number: string, cr_number: string|null, address_ar: string, address_en: string, street: string, building_number: string, district: string, city: string, postal_code: string}
     */
    public static function seller(): array
    {
        $seller = (array) config('groupbuy.seller');

        return [
            'legal_name_ar' => (string) $seller['legal_name_ar'],
            'legal_name_en' => (string) $seller['legal_name_en'],
            'vat_number' => (string) $seller['vat_number'],
            'cr_number' => $seller['cr_number'] === null ? null : (string) $seller['cr_number'],
            'address_ar' => (string) $seller['address_ar'],
            'address_en' => (string) $seller['address_en'],
            'street' => (string) ($seller['street'] ?? ''),
            'building_number' => (string) ($seller['building_number'] ?? ''),
            'district' => (string) ($seller['district'] ?? ''),
            'city' => (string) ($seller['city'] ?? ''),
            'postal_code' => (string) ($seller['postal_code'] ?? ''),
        ];
    }
}
