<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Documents\InvoicePdf;
use App\Modules\Orders\Jobs\RenderInvoicePdf;
use App\Modules\Orders\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
     * was issued: its job ran out of tries or was lost. Only invoices from
     * the last `invoices.pdf_requeue_within_days`, unless `$all`: an older
     * one keeps failing and waits for its cause to be fixed. A copy of a
     * job still holding its unique lock is dropped. Returns how many were
     * asked for.
     */
    public function requeueMissing(?CarbonImmutable $now = null, bool $all = false): int
    {
        $queued = 0;

        $this->missing($now)
            ->when(! $all, fn ($query) => $query->where('created_at', '>', self::windowStart($now)))
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
     * Invoices older than the requeue window that still have no PDF.
     */
    public function countStale(?CarbonImmutable $now = null): int
    {
        return $this->missing($now)->where('created_at', '<=', self::windowStart($now))->count();
    }

    /**
     * invoices/2026/INV-2026-000123.pdf: the folder is the year in the
     * number (Riyadh time), not the UTC year of issue.
     */
    public static function pdfPath(Invoice $invoice): string
    {
        $year = preg_match('/^[A-Z]+-(\d{4})-/', $invoice->invoice_number, $m) === 1
            ? $m[1]
            : $invoice->issued_at->setTimezone((string) config('groupbuy.display_timezone'))->format('Y');

        return sprintf('invoices/%s/%s.pdf', $year, $invoice->invoice_number);
    }

    /**
     * @return Builder<Invoice>
     */
    private function missing(?CarbonImmutable $now): Builder
    {
        $before = ($now ?? CarbonImmutable::now())->subMinutes((int) config('groupbuy.invoices.pdf_requeue_after_minutes'));

        return Invoice::query()->whereNull('pdf_s3_key')->where('created_at', '<=', $before);
    }

    private static function windowStart(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subDays((int) config('groupbuy.invoices.pdf_requeue_within_days'));
    }

    private static function disk(): string
    {
        return (string) config('groupbuy.media.documents_disk');
    }
}
