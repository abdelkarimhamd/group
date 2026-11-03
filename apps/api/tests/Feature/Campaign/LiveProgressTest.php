<?php

namespace Tests\Feature\Campaign;

use App\Modules\Campaign\Events\CampaignProgressChanged;
use App\Modules\Campaign\Jobs\BroadcastProgress;
use App\Modules\Campaign\Models\Campaign;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;

/**
 * Campaign progress pushed over Reverb: `progress.updated` on the public
 * channel `campaigns.{campaign uuid}`, with the payload of
 * GET /campaigns/{id}/progress. Reverb speaks Pusher's HTTP API; these tests
 * stand in for it at the HTTP client, so the whole path through Laravel's
 * broadcaster and the Pusher SDK runs.
 */
class LiveProgressTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    private MockHandler $reverb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));

        $this->reverb = new MockHandler;
        $stack = HandlerStack::create($this->reverb);
        $stack->push(Middleware::history($this->sent));

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'app-key',
            'broadcasting.connections.reverb.secret' => 'app-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
            'broadcasting.connections.reverb.options.host' => 'ws.example.test',
            'broadcasting.connections.reverb.client_options.handler' => $stack,
        ]);
        $this->app->make(BroadcastManager::class)->purge('reverb');
    }

    #[Test]
    public function a_join_pushes_the_new_numbers_to_the_campaign_channel(): void
    {
        $campaign = Campaign::factory()->active()->withTiers()->create();
        [, $token, $address] = $this->customerReadyToJoin();
        $this->reverbAccepts(2);

        // Reserving units pushes once, the authorized hold again.
        $participation = $this->joinAs($token, $campaign, $address, ['quantity' => 3]);
        $this->assertTrue($this->authorizeHold($participation));

        $this->assertCount(2, $this->sent);
        $push = $this->pushed(1);
        $this->assertSame('POST', $this->sent[1]['request']->getMethod());
        $this->assertSame('/apps/1001/events', $this->sent[1]['request']->getUri()->getPath());
        $this->assertSame('progress.updated', $push['name']);
        $this->assertSame('campaigns.'.$campaign->uuid, $push['channel']);

        // The same payload as the endpoint, read fresh.
        Cache::flush();
        $this->assertSame(
            $this->getJson("/api/v1/campaigns/{$campaign->uuid}/progress")->assertOk()->json('data'),
            json_decode($push['data'], true),
        );
        $this->assertSame(3, json_decode($push['data'], true)['committed_qty']);

        // The reservation's push: nothing committed yet, three units off sale.
        $reserved = json_decode($this->pushed(0)['data'], true);
        $this->assertSame([0, $campaign->max_qty - 3], [$reserved['committed_qty'], $reserved['remaining_qty']]);
    }

    #[Test]
    public function campaigns_customers_cannot_see_are_never_pushed(): void
    {
        $draft = Campaign::factory()->withTiers()->create();
        $awaitingApproval = Campaign::factory()->withTiers()->create(['status' => 'pending_approval']);

        CampaignProgressChanged::dispatch($draft->id);
        CampaignProgressChanged::dispatch($awaitingApproval->id);

        $this->assertSame([], $this->sent);
    }

    #[Test]
    public function a_push_reverb_does_not_take_is_logged_and_dropped(): void
    {
        Log::spy();
        $campaign = Campaign::factory()->active()->withTiers()->create();
        $this->reverb->append(
            new Response(500, [], '{"error":"down"}'),
            new ConnectException('Connection refused', new Request('POST', 'https://ws.example.test/apps/1001/events')),
        );

        // Neither failure reaches whoever changed the campaign.
        CampaignProgressChanged::dispatch($campaign->id);
        CampaignProgressChanged::dispatch($campaign->id);

        $this->assertCount(2, $this->sent);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'campaigns.progress_push_failed' && $context['campaign_id'] === $campaign->uuid)
            ->twice();
    }

    #[Test]
    public function changes_made_while_a_push_waits_share_it(): void
    {
        Queue::fake([BroadcastProgress::class]);
        $campaign = Campaign::factory()->active()->withTiers()->create();
        $other = Campaign::factory()->active()->withTiers()->create();

        DB::transaction(function () use ($campaign, $other): void {
            CampaignProgressChanged::dispatch($campaign->id);
            CampaignProgressChanged::dispatch($campaign->id);
            CampaignProgressChanged::dispatch($other->id);
        });
        CampaignProgressChanged::dispatch($campaign->id);

        // One waiting push per campaign; it reads the numbers when it runs.
        Queue::assertPushedTimes(BroadcastProgress::class, 2);
        Queue::assertPushed(BroadcastProgress::class, fn (BroadcastProgress $job) => $job->campaignId === $campaign->id);
        Queue::assertPushed(BroadcastProgress::class, fn (BroadcastProgress $job) => $job->campaignId === $other->id);
    }

    #[Test]
    public function nothing_is_pushed_for_a_change_that_rolled_back(): void
    {
        $campaign = Campaign::factory()->active()->withTiers()->create();

        try {
            DB::transaction(function () use ($campaign): void {
                CampaignProgressChanged::dispatch($campaign->id);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->sent);
    }

    private function reverbAccepts(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->reverb->append(new Response(200, [], '{}'));
        }
    }

    /**
     * @return array{name: string, channel: string, data: string}
     */
    private function pushed(int $index): array
    {
        return json_decode((string) $this->sent[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
