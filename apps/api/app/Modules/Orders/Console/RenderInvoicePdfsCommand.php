<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Services\InvoiceDocuments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Invoices and credit notes still without their PDF some minutes after
 * they were issued (a job that ran out of tries or was lost) are queued
 * again, so the database, not the queue, decides what is still owed.
 * Invoices past the requeue window are only counted and logged: a render
 * that failed for a week will not succeed on its own.
 */
final class RenderInvoicePdfsCommand extends Command
{
    protected $signature = 'invoices:render-pdfs
        {--all : Also queue invoices older than invoices.pdf_requeue_within_days}';

    protected $description = 'Queue the PDF of every invoice and credit note that still has none';

    public function handle(InvoiceDocuments $documents): int
    {
        $all = (bool) $this->option('all');
        $this->info('Queued '.$documents->requeueMissing(all: $all).' invoice PDFs.');

        if (! $all && ($stale = $documents->countStale()) > 0) {
            $this->warn("{$stale} older invoices still have no PDF. Fix what stops them rendering, then run invoices:render-pdfs --all.");
            Log::warning('invoices.pdf_missing', ['count' => $stale]);
        }

        return self::SUCCESS;
    }
}
