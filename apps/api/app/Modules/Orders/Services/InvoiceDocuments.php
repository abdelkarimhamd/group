<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Documents\InvoicePdf;
use App\Modules\Orders\Jobs\RenderInvoicePdf;
use App\Modules\Orders\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The stored files of invoices and credit notes. The PDF is rendered on
 * the queue after the invoice is issued (never inside the transaction that
 * takes the money) and written to the private documents disk under a path
 * fixed by its number, so a second run writes the same file to the same
 * place. Only then does the row point at it; the invoice trigger allows
 * that column, and nothing else, to change.
 */
final class InvoiceDocuments
{
    public function __construct(private readonly InvoicePdf $pdf) {}

    /**
     * Render and store the invoice's PDF unless it already has one.
     * Returns the stored path.
     */
    public function storePdf(Invoice $invoice): string
    {
        if ($invoice->pdf_s3_key !== null) {
            return $invoice->pdf_s3_key;
        }

        $path = self::pdfPath($invoice);

        if (! Storage::disk(self::disk())->put($path, $this->pdf->render($invoice))) {
            throw new RuntimeException("Could not store the PDF of {$invoice->invoice_number}.");
        }

        // Another run may have stored it meanwhile: same path, same file.
        Invoice::query()->whereKey($invoice->id)->whereNull('pdf_s3_key')->update(['pdf_s3_key' => $path]);
        $invoice->pdf_s3_key = $path;
        Log::info('invoices.pdf_stored', ['invoice' => $invoice->invoice_number]);

        return $path;
    }

    /**
     * A short-lived signed link to the PDF, or null until it is stored.
     */
    public function pdfUrl(Invoice $invoice): ?string
    {
        if ($invoice->pdf_s3_key === null) {
            return null;
        }

        return Storage::disk(self::disk())->temporaryUrl(
            $invoice->pdf_s3_key,
            now()->addMinutes((int) config('groupbuy.media.attachment_url_minutes')),
        );
    }

    /**
     * Queue the PDF of every invoice still without one this long after it
     * was issued: its job ran out of tries or was lost. Returns how many.
     */
    public function requeueMissing(?CarbonImmutable $now = null): int
    {
        $before = ($now ?? CarbonImmutable::now())->subMinutes((int) config('groupbuy.invoices.pdf_requeue_after_minutes'));
        $queued = 0;

        Invoice::query()
            ->whereNull('pdf_s3_key')
            ->where('created_at', '<=', $before)
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($invoices) use (&$queued): void {
                foreach ($invoices as $invoice) {
                    RenderInvoicePdf::dispatch($invoice->id);
                    $queued++;
                }
            });

        return $queued;
    }

    /**
     * invoices/2026/INV-2026-000123.pdf
     */
    public static function pdfPath(Invoice $invoice): string
    {
        return sprintf('invoices/%s/%s.pdf', $invoice->issued_at->format('Y'), $invoice->invoice_number);
    }

    private static function disk(): string
    {
        return (string) config('groupbuy.media.documents_disk');
    }
}
