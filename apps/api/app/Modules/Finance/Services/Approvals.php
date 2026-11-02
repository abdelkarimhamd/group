<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\ApprovalStatus;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Identity\Models\AdminUser;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Opening requests for a second admin's approval (spec: an admin action
 * that moves money needs a second admin with the finance role). Only one
 * request of a type waits per subject: asking again returns the one that
 * is already waiting. Decisions approves or rejects them and carries
 * out what was approved.
 */
class Approvals
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Open (or find) the pending request. Call inside the transaction that
     * holds the subject's row lock, so two admins asking at once get the
     * same request.
     *
     * @param  array<string, mixed>  $payload
     */
    public function request(ApprovalType $type, Model $subject, array $payload, AdminUser $by): ApprovalRequest
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isEmpty()) {
            throw new LogicException('Open an approval request inside the transaction that locks its subject.');
        }

        $pending = $this->pending($type, $subject);

        if ($pending !== null) {
            return $pending;
        }

        $request = ApprovalRequest::create([
            'type' => $type,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => (object) $payload,
            'requested_by_admin_id' => $by->id,
            'status' => ApprovalStatus::Pending,
        ]);

        $this->audit->record(Actor::admin($by->id), 'approval.request', $request, after: [
            'type' => $type->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ] + $payload);

        return $request;
    }

    public function pending(ApprovalType $type, Model $subject): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('type', $type)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('status', ApprovalStatus::Pending)
            ->first();
    }
}
