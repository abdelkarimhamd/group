<?php

namespace App\Modules\Supplier\Console;

use App\Modules\Supplier\Enums\QuotationStatus;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marks draft and accepted quotations as expired once their validity date has
 * passed in Riyadh. Runs daily just after midnight Riyadh time.
 */
final class ExpireQuotationsCommand extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Expire supplier quotations past their validity date';

    public function handle(StatusHistory $history): int
    {
        $today = CarbonImmutable::now((string) config('groupbuy.display_timezone'))->toDateString();
        $open = [QuotationStatus::Draft, QuotationStatus::Accepted];
        $expired = 0;

        SupplierQuotation::query()
            ->whereIn('status', $open)
            ->where('valid_until', '<', $today)
            ->chunkById(200, function (Collection $chunk) use ($history, $open, $today, &$expired): void {
                foreach ($chunk as $candidate) {
                    $expired += DB::transaction(function () use ($candidate, $history, $open, $today): int {
                        $quotation = SupplierQuotation::query()
                            ->whereKey($candidate->id)
                            ->whereIn('status', $open)
                            ->where('valid_until', '<', $today)
                            ->lockForUpdate()
                            ->first();

                        if ($quotation === null) {
                            return 0;
                        }

                        $from = $quotation->status;
                        $quotation->status = QuotationStatus::Expired;
                        $quotation->save();
                        $history->record($quotation, $from, QuotationStatus::Expired, Actor::system(), 'valid_until passed');

                        return 1;
                    });
                }
            });

        $this->info("Expired {$expired} quotation(s).");

        return self::SUCCESS;
    }
}
