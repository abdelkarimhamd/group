<?php

namespace Tests\Feature\Http;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;

/**
 * Performance guards for the busiest reads: the number of queries stays the
 * same as the data grows (no N+1), and reading is not a database write.
 */
class QueryBudgetTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    #[Test]
    public function the_campaign_list_runs_the_same_queries_for_two_campaigns_or_ten(): void
    {
        self::campaigns(2);
        [$few] = $this->queries(fn () => $this->getJson('/api/v1/campaigns?filter=open')->assertOk()->assertJsonCount(2, 'data'));

        self::campaigns(8);
        [$many] = $this->queries(fn () => $this->getJson('/api/v1/campaigns?filter=open')->assertOk()->assertJsonCount(10, 'data'));

        $this->assertSame(count($few), count($many));
        $this->assertLessThanOrEqual(6, count($many));
    }

    #[Test]
    public function a_campaign_page_runs_the_same_queries_whatever_its_photos(): void
    {
        [$one, $other] = self::campaigns(2);
        self::photos($other, 6);

        [$few] = $this->queries(fn () => $this->getJson("/api/v1/campaigns/{$one->uuid}")->assertOk());
        [$many] = $this->queries(fn () => $this->getJson("/api/v1/campaigns/{$other->uuid}")->assertOk()->assertJsonCount(7, 'data.images'));

        $this->assertSame(count($few), count($many));
    }

    #[Test]
    public function a_customers_joins_list_runs_the_same_queries_for_one_join_or_five(): void
    {
        [, $token, $address] = $this->customerReadyToJoin();
        $campaigns = self::campaigns(5);
        $this->joinAs($token, $campaigns[0], $address);
        [$few] = $this->queries(fn () => $this->withToken($token)->getJson('/api/v1/me/participations')->assertOk()->assertJsonCount(1, 'data'));
        $this->forgetAuthenticatedUsers();

        foreach (array_slice($campaigns, 1) as $campaign) {
            $this->joinAs($token, $campaign, $address);
        }
        [$many] = $this->queries(fn () => $this->withToken($token)->getJson('/api/v1/me/participations')->assertOk()->assertJsonCount(5, 'data'));

        $this->assertSame(count($few), count($many));
    }

    #[Test]
    public function a_signed_in_read_writes_last_used_at_at_most_every_few_minutes(): void
    {
        [, $token] = $this->customerWithToken();
        $id = (int) Str::before($token, '|');

        [, $writes] = $this->queries(fn () => $this->withToken($token)->getJson('/api/v1/me')->assertOk());
        $this->assertCount(1, $writes, 'The first use of a token is recorded.');
        $this->assertNotNull(PersonalAccessToken::findOrFail($id)->last_used_at);

        $this->travel((int) config('groupbuy.auth.token_last_used_minutes') - 1)->minutes();
        $this->forgetAuthenticatedUsers();
        [, $writes] = $this->queries(fn () => $this->withToken($token)->getJson('/api/v1/me')->assertOk());
        $this->assertSame([], $writes, 'A read soon after writes nothing.');

        $this->travel(2)->minutes();
        $this->forgetAuthenticatedUsers();
        [, $writes] = $this->queries(fn () => $this->withToken($token)->getJson('/api/v1/me')->assertOk());
        $this->assertCount(1, $writes);
        $this->assertTrue(PersonalAccessToken::findOrFail($id)->last_used_at->isSameMinute(now()));
    }

    /**
     * Every statement the request ran, and those that wrote.
     *
     * @param  callable(): TestResponse  $request
     * @return array{list<string>, list<string>}
     */
    private function queries(callable $request): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $request();
        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        $writes = array_values(array_filter($sql, fn (string $statement): bool => (bool) preg_match('/^\s*(insert|update|delete)\b/i', $statement)));

        return [$sql, $writes];
    }

    /**
     * Open campaigns, each with one photo.
     *
     * @return list<Campaign>
     */
    private static function campaigns(int $count): array
    {
        $campaigns = [];
        for ($i = 0; $i < $count; $i++) {
            $campaign = Campaign::factory()->active()->create();
            self::photos($campaign, 1);
            $campaigns[] = $campaign;
        }

        return $campaigns;
    }

    private static function photos(Campaign $campaign, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $image = new ProductImage(['sort_order' => $i]);
            $image->uuid = (string) Str::uuid7();
            $image->product_id = $campaign->product_id;
            $image->disk = 'public';
            $image->path = "products/{$campaign->product->uuid}/{$i}.webp";
            $image->save();
        }
    }
}
