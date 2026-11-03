<?php

namespace App\Modules\Finance\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignCancellations;
use App\Modules\Finance\Enums\ApprovalStatus;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\Refunds;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A second admin with the finance role approves or rejects a request
 * (spec: an action that moves money needs a second admin). Never the
 * admin who asked. The subject is locked before the request, in the
 * subject's usual lock order; approving queues the action, which runs
 * after commit (a refund is sent, a cancellation carried out).
 *
 * Manual captures, voids and ledger adjustments have no screen that opens
 * them yet; a request of those types cannot be decided here.
 */
final class Decisions
{
    public function __construct(
        private readonly Refunds $refunds,
        private readonly CampaignCancellations $cancellations,
        private readonly AuditLogger $audit,
        private readonly StatusHistory $history,
    ) {}

    /**
     * @throws ApiException 403 same_admin, 409 invalid_transition
     */
    public function approve(string $uuid, AdminUser $by): ApprovalRequest
    {
        return $this->decide($uuid, $by, ApprovalStatus::Approved, null);
    }

    /**
     * @throws ApiException 403 same_admin, 409 invalid_transition
     */
    public function reject(string $uuid, string $reason, AdminUser $by): ApprovalRequest
    {
        return $this->decide($uuid, $by, ApprovalStatus::Rejected, $reason);
    }

    private function decide(string $uuid, AdminUser $by, ApprovalStatus $to, ?string $reason): ApprovalRequest
    {
        $found = ApprovalRequest::query()->where('uuid', $uuid)->firstOrFail();

        if (! in_array($found->type, [ApprovalType::ManualRefund, ApprovalType::CampaignCancellation], true)) {
            throw new ApiException(409, 'invalid_transition');
        }

        return DB::transaction(function () use ($found, $by, $to, $reason): ApprovalRequest {
            $subject = $found->type === ApprovalType::ManualRefund
                ? $this->refunds->lockForDecision($found->subject_id)
                : Campaign::query()->whereKey($found->subject_id)->lockForUpdate()->firstOrFail();
            $request = ApprovalRequest::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== ApprovalStatus::Pending) {
                throw new ApiException(409, 'invalid_transition');
            }

            if ($request->requested_by_admin_id === $by->id) {
                throw new ApiException(403, 'same_admin');
            }

            $actor = Actor::admin($by->id);
            $request->status = $to;
            $request->decided_by_admin_id = $by->id;
            $request->decided_at = CarbonImmutable::now();
            $request->reason = $reason;
            $request->save();
            $this->history->record($request, ApprovalStatus::Pending, $to, $actor, $reason === null ? null : mb_substr($reason, 0, 255));

            match (true) {
                $subject instanceof Refund && $to === ApprovalStatus::Approved => $this->refunds->approve($subject, $actor),
                $subject instanceof Refund => $this->refunds->reject($subject, (string) $reason, $actor),
                $subject instanceof Campaign && $to === ApprovalStatus::Approved => $this->cancellations->approve($subject, $request),
                default => null,
            };

            $this->audit->record($actor, $to === ApprovalStatus::Approved ? 'approval.approve' : 'approval.reject', $request,
                ['status' => ApprovalStatus::Pending->value],
                ['status' => $to->value, 'type' => $request->type->value, 'reason' => $reason],
            );

            return $request;
        });
    }
}
