<?php

namespace App\Modules\Campaign\Enums;

/**
 * Mirrors the CHECK constraint on campaigns.status. Only `active` accepts
 * joins. Transitions are in the spec's Campaign lifecycle and are made only
 * by App\Modules\Campaign\Services\CampaignLifecycle and the closing job.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Closing = 'closing';
    case Purchasing = 'purchasing';
    case Fulfilling = 'fulfilling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Statuses customers can see. Drafts and campaigns awaiting approval never are.
     *
     * @return list<self>
     */
    public static function visibleToCustomers(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => ! in_array($status, [self::Draft, self::PendingApproval], true)));
    }

    /**
     * Everything after `active`: the customer list's `ended` filter.
     *
     * @return list<self>
     */
    public static function ended(): array
    {
        return [self::Closing, self::Purchasing, self::Fulfilling, self::Completed, self::Failed, self::Cancelled];
    }

    /**
     * The terms (tiers, target, maximum) are locked from approval on.
     */
    public function termsLocked(): bool
    {
        return ! in_array($this, [self::Draft, self::PendingApproval], true);
    }
}
