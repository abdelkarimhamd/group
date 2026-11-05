<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Services\InvoiceDocuments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Renders and stores one invoice's or credit note's PDF. Queued after the
 * commit that issued it; invoices:render-pdfs queues it again if it is
 * still missing later, so a lost job only delays the file.
 */
class RenderInvoicePdf implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /**
     * Longer than every try and its backoff (about 22 minutes), so a copy
     * is dropped while the first may still run; a lost job is queued again
     * by invoices:render-pdfs once this has passed.
     */
    public int $uniqueFor = 1800;

    public function __construct(public readonly int $invoiceId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    public function handle(InvoiceDocuments $documents): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if ($invoice !== null) {
            $documents->storePdf($invoice);
        }
    }
}
