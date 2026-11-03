<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * What Reverb sends to the website and app: `progress.updated` on the public
 * channel `campaigns.{campaign uuid}`, with the `CampaignProgress` payload of
 * GET /campaigns/{id}/progress. Sent by App\Modules\Campaign\Jobs\BroadcastProgress,
 * already on a queue, so it goes out at once.
 */
final class ProgressUpdated implements ShouldBroadcastNow
{
    /**
     * @param  array<string, mixed>  $progress  App\Modules\Campaign\Services\Progress::payload()
     */
    public function __construct(
        public readonly string $campaignUuid,
        public readonly array $progress,
    ) {}

    public static function channelName(string $campaignUuid): string
    {
        return 'campaigns.'.$campaignUuid;
    }

    public function broadcastOn(): Channel
    {
        return new Channel(self::channelName($this->campaignUuid));
    }

    public function broadcastAs(): string
    {
        return 'progress.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->progress;
    }
}
