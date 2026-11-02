<?php

namespace App\Modules\Campaign\Services;

use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Support\Actor;
use App\Support\RequestContext;
use LogicException;

/**
 * Writes campaign history. Campaign status changes are recorded here (with
 * from and to statuses) rather than in status_transitions, as the spec's
 * data model sets out. Call it inside the transaction that makes the change,
 * after the campaign's counters are current.
 */
final class CampaignEvents
{
    public const CREATED = 'created';

    public const TIERS_CHANGED = 'tiers_changed';

    public const SUBMITTED = 'submitted';

    public const REJECTED = 'rejected';

    public const APPROVED = 'approved';

    public const PUBLISHED = 'published';

    public const JOINED = 'joined';

    /** A customer cancelled their join before the deal was confirmed (E11). */
    public const JOIN_CANCELLED = 'join_cancelled';

    public const DEAL_CONFIRMED = 'deal_confirmed';

    public const PRICE_CHANGED = 'price_changed';

    public const MAX_REACHED = 'max_reached';

    public const EXTENDED = 'extended';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    /** Closing step 4 opened: final quantity and price set, supplier to reconfirm. */
    public const AWAITING_SUPPLIER = 'awaiting_supplier';

    public const SUPPLIER_CONFIRMED = 'supplier_confirmed';

    public const SUPPLIER_REJECTED = 'supplier_rejected';

    /** Captures finished below the target or the supplier MOQ. */
    public const NEEDS_ADMIN = 'needs_admin';

    public const BELOW_TARGET_DECISION = 'below_target_decision';

    /** A cancellation that needs a finance approval was asked for. */
    public const CANCELLATION_REQUESTED = 'cancellation_requested';

    /** closing -> purchasing. */
    public const CAPTURES_COMPLETED = 'captures_completed';

    /** The supplier purchase order was shipped: purchasing -> fulfilling. */
    public const SUPPLIER_SHIPPED = 'supplier_shipped';

    /** closing -> failed. */
    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Campaign $campaign,
        string $type,
        Actor $actor,
        array $payload = [],
        ?CampaignStatus $from = null,
        ?CampaignStatus $to = null,
    ): CampaignEvent {
        if (app('db.transactions')->callbackApplicableTransactions()->isEmpty()) {
            throw new LogicException('Campaign events must be written inside the transaction that makes the change.');
        }

        return CampaignEvent::create([
            'campaign_id' => $campaign->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'committed_qty_after' => $campaign->committed_qty,
            'payload' => (object) $payload,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'request_id' => RequestContext::requestId(),
            'occurred_at' => now(),
        ]);
    }
}
