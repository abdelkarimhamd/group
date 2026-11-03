<?php

namespace App\Modules\Finance\Console;

use App\Modules\Campaign\Jobs\ExecuteCampaignCancellation;
use App\Modules\Finance\Enums\ApprovalStatus;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Jobs\ExecuteRefund;
use App\Modules\Payments\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Every five minutes: approved refunds left approved or processing, and
 * approved campaign cancellations not yet carried out, are queued again
 * once nothing changed on them for a while (their job ran out of tries
 * while Moyasar was down, or was lost). The database, not the queue,
 * remembers what still has to happen. A copy is dropped while the first
 * job still waits (both jobs are unique), and running one twice is safe:
 * a refund asks Moyasar before sending again, and a cancellation does
 * nothing once carried out.
 */
final class RequeueApprovedCommand extends Command
{
    protected $signature = 'finance:requeue-approved';

    protected $description = 'Queue again approved refunds and campaign cancellations that did not finish';

    public function handle(): int
    {
        $before = CarbonImmutable::now()->subMinutes((int) config('groupbuy.finance.requeue_after_minutes'));

        $refunds = Refund::query()
            ->whereIn('status', [RefundStatus::Approved, RefundStatus::Processing])
            ->where('updated_at', '<', $before)
            ->orderBy('id')
            ->pluck('id');
        $refunds->each(fn (int $id) => ExecuteRefund::dispatch($id));

        $cancellations = ApprovalRequest::query()
            ->where('type', ApprovalType::CampaignCancellation)
            ->where('status', ApprovalStatus::Approved)
            ->whereNull('executed_at')
            ->where('updated_at', '<', $before)
            ->orderBy('id')
            ->pluck('id');
        $cancellations->each(fn (int $id) => ExecuteCampaignCancellation::dispatch($id));

        $this->info("Queued {$refunds->count()} refunds and {$cancellations->count()} campaign cancellations again.");

        return self::SUCCESS;
    }
}
