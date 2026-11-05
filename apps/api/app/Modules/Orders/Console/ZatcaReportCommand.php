<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Enums\ZatcaStatus;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Services\ZatcaReporting;
use Illuminate\Console\Command;

/**
 * Every ten minutes: e-invoices still to sign or report are queued again,
 * and those close to ZATCA's 24-hour limit raise an alert. With
 * --resend=INV-..., a rejected invoice is sent once more (after the cause
 * on our side, or ZATCA's, was fixed; its signed XML cannot change).
 */
final class ZatcaReportCommand extends Command
{
    protected $signature = 'zatca:report {--resend= : The number of a rejected invoice to report again}';

    protected $description = 'Queue e-invoices still to sign or report to ZATCA, and alert on late ones';

    public function handle(ZatcaReporting $zatca): int
    {
        if (is_string($number = $this->option('resend')) && $number !== '') {
            $invoice = Invoice::query()->where('invoice_number', $number)->with('zatcaStamp')->first();

            if ($invoice?->zatcaStamp?->signed_at === null || $invoice->zatca_status !== ZatcaStatus::Rejected) {
                $this->error("{$number} is not a rejected e-invoice.");

                return self::FAILURE;
            }

            $zatca->report($invoice->zatcaStamp->id, resend: true);
            $this->info("{$number}: ".$invoice->refresh()->zatca_status->value.'.');

            return self::SUCCESS;
        }

        ['queued' => $queued, 'overdue' => $overdue, 'rejected' => $rejected] = $zatca->sweep();
        $this->info("E-invoices queued: {$queued}, overdue: {$overdue}, rejected: {$rejected}.");

        return $overdue > 0 ? self::FAILURE : self::SUCCESS;
    }
}
