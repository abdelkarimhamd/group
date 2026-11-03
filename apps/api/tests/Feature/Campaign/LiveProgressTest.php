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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
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
        // Clients tell the later payload by its version, not its whole-second time.
        $this->assertSame($reserved['updated_at'], json_decode($push['data'], true)['updated_at']);
        $this->assertGreaterThan($reserved['version'], json_decode($push['data'], true)['version']);
    }

    #[Test]
    public function postgresql_bumps_the_version_on_every_update_whatever_the_code_sends(): void
    {
        $campaign = Campaign::factory()->active()->withTiers()->create();
        $start = Campaign::query()->whereKey($campaign->id)->value('version');

        DB::table('campaigns')->where('id', $campaign->id)->update(['reserved_qty' => DB::raw('reserved_qty + 1')]);
        $campaign->refresh()->forceFill(['title_en' => 'Renamed'])->save();
        DB::table('campaigns')->where('id', $campaign->id)->update(['version' => 0]);

        $this->assertSame($start + 3, Campaign::query()->whereKey($campaign->id)->value('version'));
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
            // Something in front of Reverb answering with its own page.
            new Response(200, ['Content-Type' => 'text/html'], '<html>maintenance</html>'),
        );

        // No failure reaches whoever changed the campaign.
        CampaignProgressChanged::dispatch($campaign->id);
        CampaignProgressChanged::dispatch($campaign->id);
        CampaignProgressChanged::dispatch($campaign->id);

        $this->assertCount(3, $this->sent);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'campaigns.progress_push_failed' && $context['campaign_id'] === $campaign->uuid)
            ->times(3);
    }

    #[Test]
    public function a_read_made_after_a_push_answers_at_least_the_pushed_numbers(): void
    {
        $campaign = Campaign::factory()->active()->withTiers()->create();
        $url = "/api/v1/campaigns/{$campaign->uuid}/progress";
        $cached = $this->getJson($url)->assertOk()->json('data');

        // A change the endpoint's cache has not seen yet.
        DB::table('campaigns')->where('id', $campaign->id)->update(['reserved_qty' => DB::raw('reserved_qty + 1')]);
        $this->assertSame($cached, $this->getJson($url)->json('data'));

        // A client that subscribed just after this push reads to catch up.
        $this->reverbAccepts(1);
        CampaignProgressChanged::dispatch($campaign->id);

        $pushed = json_decode($this->pushed(0)['data'], true);
        $this->assertGreaterThan($cached['version'], $pushed['version']);
        $this->assertSame($pushed, $this->getJson($url)->json('data'));
    }

    #[Test]
    public function a_push_that_waited_long_in_a_busy_queue_still_goes_out(): void
    {
        $campaign = Campaign::factory()->active()->withTiers()->create();
        BroadcastProgress::dispatch($campaign->id)->onConnection('database');
        $this->reverbAccepts(1);

        $this->travel(10)->minutes();
        $this->work();

        $this->assertCount(1, $this->sent);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function a_push_waits_for_the_campaigns_previous_run_however_many_tries_that_takes(): void
    {
        $campaign = Campaign::factory()->active()->withTiers()->create();
        // Another run for this campaign is under way.
        $overlap = Cache::lock((new WithoutOverlapping((string) $campaign->id))->getLockKey(new BroadcastProgress($campaign->id)), 15);
        $this->assertTrue($overlap->get());
        BroadcastProgress::dispatch($campaign->id)->onConnection('database');

        // The worker allows one try; each wait is a try.
        foreach (range(1, 3) as $wait) {
            $this->work();
            $this->travel(2)->seconds();
        }
        $this->assertSame([], $this->sent);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $overlap->release();
        $this->reverbAccepts(1);
        $this->work();

        $this->assertCount(1, $this->sent);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function a_run_is_stopped_before_the_next_run_of_its_campaign_may_start(): void
    {
        $job = new BroadcastProgress(1);
        $overlap = $job->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $overlap);
        $this->assertLessThan($overlap->expiresAfter, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
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

    /** One pass of a production-like worker on the database queue. */
    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
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
