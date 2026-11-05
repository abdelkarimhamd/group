<?php

namespace App\Modules\Finance\Services;

use App\Exceptions\ApiException;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Enums\LedgerAdjustmentStatus;
use App\Modules\Finance\Enums\LedgerTransactionType;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Models\LedgerAdjustment;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Identity\Models\AdminUser;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hand-made ledger postings (spec, E15: a manual adjustment needs a second
 * admin). An admin asks for balanced lines, or for a posting to be
 * reversed; nothing reaches the ledger until a second admin with the
 * finance role approves (Decisions), and then it is posted in that
 * decision's transaction. Mistakes in an adjustment are fixed by reversing
 * it, never by changing it.
 */
final class LedgerAdjustments
{
    public function __construct(
        private readonly Approvals $approvals,
        private readonly Ledger $ledger,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{account: string, direction: 'D'|'C', amount_halalas: int}>  $lines  balanced (checked by the request)
     */
    public function requestPosting(string $description, array $lines, AdminUser $by): LedgerAdjustment
    {
        self::assertBalanced($lines);

        return DB::transaction(function () use ($description, $lines, $by): LedgerAdjustment {
            $adjustment = LedgerAdjustment::create([
                'description' => $description,
                'lines' => $lines,
                'status' => LedgerAdjustmentStatus::Pending,
                'requested_by_admin_id' => $by->id,
            ]);
            $this->history->record($adjustment, null, LedgerAdjustmentStatus::Pending, Actor::admin($by->id));
            $this->approvals->request(ApprovalType::LedgerAdjustment, $adjustment, [
                'description' => $description,
                'lines' => $lines,
                'total_halalas' => self::debits($lines),
            ], $by);

            return $adjustment;
        });
    }

    /**
     * @throws ApiException 409 invalid_transition (a reversal), already_reversed
     */
    public function requestReversal(string $transactionUuid, string $description, AdminUser $by): LedgerAdjustment
    {
        return DB::transaction(function () use ($transactionUuid, $description, $by): LedgerAdjustment {
            // The ledger is append-only: the app's role may not UPDATE it, so
            // it cannot take row locks there either (FOR UPDATE needs the
            // privilege). Two requests for the same posting queue on an
            // advisory lock instead; ledger_adjustments_one_reversal backs it up.
            $original = LedgerTransaction::query()->where('uuid', $transactionUuid)->firstOrFail();
            DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['ledger_reversal:'.$original->id]);

            if ($original->type === LedgerTransactionType::Reversal) {
                throw new ApiException(409, 'invalid_transition');
            }

            $taken = LedgerTransaction::query()->where('reverses_transaction_id', $original->id)->exists()
                || LedgerAdjustment::query()
                    ->where('reverses_transaction_id', $original->id)
                    ->where('status', '<>', LedgerAdjustmentStatus::Rejected)
                    ->exists();

            if ($taken) {
                throw new ApiException(409, 'already_reversed');
            }

            $adjustment = LedgerAdjustment::create([
                'description' => $description,
                'reverses_transaction_id' => $original->id,
                'status' => LedgerAdjustmentStatus::Pending,
                'requested_by_admin_id' => $by->id,
            ]);
            $this->history->record($adjustment, null, LedgerAdjustmentStatus::Pending, Actor::admin($by->id));
            $this->approvals->request(ApprovalType::LedgerAdjustment, $adjustment, [
                'description' => $description,
                'reverses' => [
                    'id' => $original->uuid,
                    'type' => $original->type->value,
                    'reference' => $original->reference,
                    'description' => $original->description,
                ],
                'total_halalas' => (int) $original->entries()->where('direction', 'D')->sum('amount_halalas'),
            ], $by);

            return $adjustment;
        });
    }

    public function lockForDecision(int $adjustmentId): LedgerAdjustment
    {
        return LedgerAdjustment::query()->whereKey($adjustmentId)->lockForUpdate()->firstOrFail();
    }

    /**
     * The second admin approved: post it now, in the decision's transaction.
     * Call with the adjustment locked (lockForDecision).
     */
    public function approve(LedgerAdjustment $adjustment, ApprovalRequest $request, Actor $actor): LedgerTransaction
    {
        if ($adjustment->status !== LedgerAdjustmentStatus::Pending) {
            throw new ApiException(409, 'invalid_transition');
        }

        if ($adjustment->reverses_transaction_id === null) {
            $lines = array_map(fn (array $line) => [
                LedgerAccountCode::from($line['account']),
                $line['direction'],
                Money::sar((int) $line['amount_halalas']),
            ], $adjustment->lines ?? []);

            $posting = $this->ledger->post(LedgerTransactionType::Adjustment, 'adjustment:'.$adjustment->uuid, $adjustment->description, $lines, $actor,
                approvalRequestId: $request->id,
            );
        } else {
            // A plain read: the unique reverses_transaction_id on the ledger
            // allows one reversal, and the ledger cannot be row-locked.
            $original = LedgerTransaction::query()->whereKey($adjustment->reverses_transaction_id)->firstOrFail();
            $posting = $this->ledger->reverse($original, $adjustment->description, $actor, $request->id);
        }

        $adjustment->status = LedgerAdjustmentStatus::Posted;
        $adjustment->ledger_transaction_id = $posting->id;
        $adjustment->save();
        $this->history->record($adjustment, LedgerAdjustmentStatus::Pending, LedgerAdjustmentStatus::Posted, $actor);
        $this->audit->record($actor, 'ledger.post', $posting, null, [
            'type' => $posting->type->value,
            'reference' => $posting->reference,
            'adjustment' => $adjustment->uuid,
        ]);

        return $posting;
    }

    /**
     * The second admin said no; nothing is posted. Call with the adjustment locked.
     */
    public function reject(LedgerAdjustment $adjustment, string $reason, Actor $actor): void
    {
        if ($adjustment->status !== LedgerAdjustmentStatus::Pending) {
            throw new ApiException(409, 'invalid_transition');
        }

        $adjustment->status = LedgerAdjustmentStatus::Rejected;
        $adjustment->save();
        $this->history->record($adjustment, LedgerAdjustmentStatus::Pending, LedgerAdjustmentStatus::Rejected, $actor, mb_substr($reason, 0, 255));
    }

    /**
     * @param  list<array{account: string, direction: 'D'|'C', amount_halalas: int}>  $lines
     */
    public static function balanced(array $lines): bool
    {
        $debits = 0;
        $credits = 0;

        foreach ($lines as $line) {
            if ($line['direction'] === 'D') {
                $debits += $line['amount_halalas'];
            } else {
                $credits += $line['amount_halalas'];
            }
        }

        return $debits === $credits && $debits > 0;
    }

    /**
     * @param  list<array{account: string, direction: 'D'|'C', amount_halalas: int}>  $lines
     */
    private static function debits(array $lines): int
    {
        return array_sum(array_map(fn (array $line) => $line['direction'] === 'D' ? $line['amount_halalas'] : 0, $lines));
    }

    /**
     * @param  list<array{account: string, direction: 'D'|'C', amount_halalas: int}>  $lines
     */
    private static function assertBalanced(array $lines): void
    {
        if (! self::balanced($lines)) {
            throw new LogicException('Ledger adjustment lines must balance.');
        }
    }
}
