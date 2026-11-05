<?php

namespace App\Modules\Orders\Documents;

use App\Modules\Orders\Enums\InvoiceType;
use App\Modules\Orders\Models\Invoice;
use App\Support\Money\Money;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The printable invoice or credit note, in Arabic and English side by side
 * (spec, E11: "VAT invoice per order ... in Arabic and English"), with the
 * ZATCA QR code. Everything printed comes from the issued row: the seller
 * snapshot, the lines and the totals, never from today's settings, so a
 * file rendered again later shows the same invoice.
 */
final class InvoicePdf
{
    /**
     * The PDF's bytes.
     */
    public function render(Invoice $invoice): string
    {
        $tempDir = storage_path('framework/cache/mpdf');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'default_font' => 'dejavusans',
            // Arabic runs get an Arabic font and are shaped and ordered
            // right to left inside an otherwise bilingual page.
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle($invoice->invoice_number);
        $mpdf->SetAuthor((string) ($invoice->seller_snapshot['legal_name_en'] ?? ''));
        $mpdf->SetCreator((string) config('app.name'));
        $mpdf->WriteHTML(view('documents.invoice', $this->data($invoice))->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * What the template prints, already formatted.
     *
     * @return array<string, mixed>
     */
    public function data(Invoice $invoice): array
    {
        $invoice->loadMissing('order', 'original');
        $seller = $invoice->seller_snapshot;
        $creditNote = $invoice->type === InvoiceType::CreditNote;

        return [
            'creditNote' => $creditNote,
            'title' => $creditNote
                ? ['ar' => 'إشعار دائن', 'en' => 'Credit note']
                : ['ar' => 'فاتورة ضريبية مبسطة', 'en' => 'Simplified tax invoice'],
            'number' => $invoice->invoice_number,
            'issuedAt' => $invoice->issued_at->setTimezone((string) config('groupbuy.display_timezone'))->format('Y-m-d H:i'),
            'orderNumber' => $invoice->order->order_number,
            'originalNumber' => $invoice->original?->invoice_number,
            'seller' => [
                'name_ar' => (string) ($seller['legal_name_ar'] ?? ''),
                'name_en' => (string) ($seller['legal_name_en'] ?? ''),
                'vat_number' => (string) ($seller['vat_number'] ?? ''),
                'cr_number' => $seller['cr_number'] ?? null,
                'address_ar' => (string) ($seller['address_ar'] ?? ''),
                'address_en' => (string) ($seller['address_en'] ?? ''),
            ],
            'lines' => array_map(fn (array $line): array => [
                'description_ar' => (string) $line['description_ar'],
                'description_en' => (string) $line['description_en'],
                'qty' => (int) $line['qty'],
                'unit_price' => self::amount((int) $line['unit_price_halalas']),
                'net' => self::amount((int) $line['net_halalas']),
                'vat_rate' => self::rate((int) $line['vat_rate_bp']),
                'vat' => self::amount((int) $line['vat_halalas']),
                'total' => self::amount((int) $line['total_halalas']),
            ], $invoice->lines),
            'vatRate' => self::rate($invoice->vat_rate_bp),
            'net' => self::amount($invoice->net_halalas),
            'vat' => self::amount($invoice->vat_halalas),
            'total' => self::amount($invoice->total_halalas),
            'qr' => $invoice->qr_payload,
        ];
    }

    /**
     * "1,234.50": Western digits, as on the website, and never a float.
     */
    public static function amount(int $halalas): string
    {
        [$whole, $fraction] = explode('.', Money::sar($halalas)->decimal());

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }

    /**
     * "15%" from 1500 basis points; "12.5%" from 1250.
     */
    public static function rate(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = rtrim(sprintf('%02d', $basisPoints % 100), '0');

        return $whole.($fraction === '' ? '' : '.'.$fraction).'%';
    }
}
