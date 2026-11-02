<?php

namespace Tests\Feature\Campaign;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\Joins;
use App\Modules\Identity\Models\Address;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\PaymentAuthorization;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrent joins: each contender runs in its own process with its own
 * PostgreSQL connection, and all start at the same instant. These cannot run
 * inside the usual per-test transaction (the other connections would not see
 * the data), so this class commits its data and makes the next test that
 * uses RefreshDatabase rebuild the schema.
 */
class ConcurrentJoinsTest extends TestCase
{
    use InteractsWithCampaigns;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->fail('The concurrency tests need the pcntl extension.');
        }

        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    #[Test]
    public function thirty_customers_racing_for_ten_units_get_exactly_ten(): void
    {
        $campaign = $this->campaign(maxQty: 10);
        $customers = $this->customers(30);

        $results = $this->inParallel(array_map(
            fn (array $customer) => fn () => $this->join($customer, $campaign, 1),
            $customers,
        ));

        $this->assertSame(['joined' => 10, 'sold_out' => 20], $this->tally($results));
        $campaign->refresh();
        $this->assertSame([0, 10], [$campaign->committed_qty, $campaign->reserved_qty]);
        $this->assertSame(10, CampaignParticipation::count());
        $this->assertSame(10, PaymentAuthorization::count());
    }

    #[Test]
    public function large_orders_racing_for_the_last_units_never_oversell(): void
    {
        $campaign = $this->campaign(maxQty: 10);
        $customers = $this->customers(20);

        $results = $this->inParallel(array_map(
            fn (array $customer) => fn () => $this->join($customer, $campaign, 3),
            $customers,
        ));

        // Three orders of 3 fit; the tenth unit stays free for a smaller order.
        $this->assertSame(['joined' => 3, 'sold_out' => 17], $this->tally($results));
        $this->assertSame(9, $campaign->refresh()->reserved_qty);
        $this->assertSame(9, (int) CampaignParticipation::sum('qty'));
    }

    #[Test]
    public function one_customer_joining_from_many_devices_stays_within_their_limit(): void
    {
        $campaign = $this->campaign(maxQty: 100, perCustomerMax: 2);
        $customer = $this->customers(1)[0];

        $results = $this->inParallel(array_fill(0, 8, fn () => $this->join($customer, $campaign, 1)));

        $this->assertSame(['joined' => 2, 'per_customer_limit_exceeded' => 6], $this->tally($results));
        $this->assertSame(2, $campaign->refresh()->reserved_qty);
    }

    #[Test]
    public function the_same_join_retried_at_once_is_made_only_once(): void
    {
        $campaign = $this->campaign(maxQty: 100);
        $customer = $this->customers(1)[0];
        $requestId = (string) Str::uuid();

        $results = $this->inParallel(array_fill(0, 6, fn () => $this->join($customer, $campaign, 2, $requestId)));

        $participation = CampaignParticipation::sole();
        $this->assertSame(array_fill(0, 6, 'joined:'.$participation->uuid), $results);
        $this->assertSame(2, $campaign->refresh()->reserved_qty);
        $this->assertSame(1, PaymentAuthorization::count());
    }

    #[Test]
    public function payments_completing_at_once_fill_the_campaign_and_close_it_once(): void
    {
        $campaign = $this->campaign(maxQty: 10, minTarget: 4);
        $participations = array_map(
            fn (array $customer) => CampaignParticipation::query()->where('uuid', explode(':', $this->join($customer, $campaign, 1))[1])->sole(),
            $this->customers(10),
        );

        $results = $this->inParallel(array_map(
            fn (CampaignParticipation $participation) => fn () => $this->authorizeHold($participation) ? 'counted' : 'not_counted',
            $participations,
        ));

        $this->assertSame(['counted' => 10], $this->tally($results));
        $campaign->refresh();
        $this->assertSame(['closing', 'max_reached', 10, 0], [$campaign->status->value, $campaign->close_reason->value, $campaign->committed_qty, $campaign->reserved_qty]);

        $events = CampaignEvent::where('campaign_id', $campaign->id)->pluck('event_type')->countBy()->all();
        $this->assertSame([10, 1, 1, 1], [$events['joined'], $events['deal_confirmed'], $events['max_reached'], $events['closed']]);
        $this->assertSame(range(1, 10), CampaignEvent::where('event_type', 'joined')->orderBy('id')->pluck('committed_qty_after')->all());
    }

    private function campaign(int $maxQty, ?int $perCustomerMax = null, int $minTarget = 1): Campaign
    {
        return Campaign::factory()
            ->active()
            ->withTiers([['min_qty' => 1, 'max_qty' => $maxQty, 'unit_price_halalas' => 12000]])
            ->create(['min_target_qty' => $minTarget, 'max_qty' => $maxQty, 'per_customer_max_qty' => $perCustomerMax]);
    }

    /**
     * @return list<array{User, Address}>
     */
    private function customers(int $count): array
    {
        return array_map(function (): array {
            [$user, , $address] = $this->customerReadyToJoin();

            return [$user, $address];
        }, range(1, $count));
    }

    /**
     * @param  array{User, Address}  $customer
     */
    private function join(array $customer, Campaign $campaign, int $quantity, ?string $requestId = null): string
    {
        [$user, $address] = $customer;

        try {
            $participation = app(Joins::class)->join($user, $campaign, [
                'client_request_id' => $requestId ?? (string) Str::uuid(),
                'product_variant_id' => $campaign->variants()->firstOrFail()->uuid,
                'quantity' => $quantity,
                'shown_unit_price_halalas' => 12000,
                'delivery_address_id' => $address->uuid,
            ]);

            return 'joined:'.$participation->uuid;
        } catch (ApiException $e) {
            return $e->errorCode;
        }
    }

    /**
     * @param  list<string>  $results
     * @return array<string, int>
     */
    private function tally(array $results): array
    {
        $counts = array_count_values(array_map(fn (string $result) => explode(':', $result)[0], $results));
        ksort($counts);

        return $counts;
    }

    /**
     * Runs each task in its own process and database connection, all
     * starting at the same moment, and returns what each one returned (or
     * the error it threw).
     *
     * @param  list<Closure(): string>  $tasks
     * @return list<string>
     */
    private function inParallel(array $tasks): array
    {
        // A forked child must never share the parent's connection.
        DB::disconnect();

        $dir = sys_get_temp_dir().'/groupbuy-race-'.Str::random(8);
        mkdir($dir);
        $startAt = microtime(true) + 1.0;
        $pids = [];

        foreach ($tasks as $i => $task) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork.');
            }

            if ($pid === 0) {
                try {
                    time_sleep_until($startAt);
                    $result = $task();
                } catch (Throwable $e) {
                    $result = 'error:'.$e::class.': '.$e->getMessage();
                }

                file_put_contents("{$dir}/{$i}", $result);

                // Leave at once: no shutdown handlers, no PHPUnit output.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        foreach (array_keys($tasks) as $i) {
            $results[] = is_file("{$dir}/{$i}") ? (string) file_get_contents("{$dir}/{$i}") : 'error:no result';
            @unlink("{$dir}/{$i}");
        }

        rmdir($dir);

        return $results;
    }
}
