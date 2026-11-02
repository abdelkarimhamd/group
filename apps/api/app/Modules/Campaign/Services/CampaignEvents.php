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

    public const DEAL_CONFIRMED = 'deal_confirmed';

    public const PRICE_CHANGED = 'price_changed';

    public const MAX_REACHED = 'max_reached';

    public const EXTENDED = 'extended';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

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
