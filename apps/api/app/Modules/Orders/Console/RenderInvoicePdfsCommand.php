<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Services\InvoiceDocuments;
use Illuminate\Console\Command;

/**
 * Invoices and credit notes still without their PDF some minutes after
 * they were issued (a job that ran out of tries or was lost) are queued
 * again, so the database, not the queue, decides what is still owed.
 */
final class RenderInvoicePdfsCommand extends Command
{
    protected $signature = 'invoices:render-pdfs';

    protected $description = 'Queue the PDF of every invoice and credit note that still has none';

    public function handle(InvoiceDocuments $documents): int
    {
        $this->info('Queued '.$documents->requeueMissing().' invoice PDFs.');

        return self::SUCCESS;
    }
}
