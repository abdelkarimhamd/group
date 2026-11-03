<?php

namespace Tests\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Checks that the contract check itself works: it rejects drift, and the
 * contract's nullable fields accept null without accepting junk.
 */
class ContractValidatorTest extends TestCase
{
    #[Test]
    public function a_response_that_breaks_the_contract_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        OpenApiContract::validator()->get(new JsonResponse(['status' => 'fine']), '/health');
    }

    #[Test]
    public function an_undocumented_status_code_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        OpenApiContract::validator()->get(new JsonResponse(['message' => 'Server Error'], 500), '/health');
    }

    #[Test]
    public function nullable_references_accept_null(): void
    {
        $this->assertTrue(OpenApiContract::validator()->get(
            new JsonResponse(['data' => $this->campaignDetail()]),
            '/campaigns/{campaign_id}',
        ));
    }

    #[Test]
    public function nullable_references_still_check_a_value(): void
    {
        $this->expectException(ValidationException::class);

        OpenApiContract::validator()->get(
            new JsonResponse(['data' => $this->campaignDetail(['final_unit_price' => '49.00'])]),
            '/campaigns/{campaign_id}',
        );
    }

    #[Test]
    public function routes_match_contract_paths_by_shape(): void
    {
        $bound = new Route(['GET'], 'api/v1/campaigns/{campaign}/progress', fn () => null);
        $unknown = new Route(['GET'], 'api/v1/nowhere/{thing}', fn () => null);
        $outside = new Route(['GET'], 'up', fn () => null);

        $this->assertSame('/campaigns/{campaign_id}/progress', OpenApiContract::pathFor($bound));
        $this->assertSame('/nowhere/{thing}', OpenApiContract::pathFor($unknown));
        $this->assertNull(OpenApiContract::pathFor($outside));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function campaignDetail(array $overrides = []): array
    {
        $id = '0f8f3c3e-6d1b-4c55-9d0f-4d6c3e2b1a90';
        $price = ['amount_minor' => 6_900, 'currency' => 'SAR', 'formatted' => '69.00 SAR'];

        return array_merge([
            'id' => $id,
            'code' => 'GB-2611-0007',
            'title' => 'غلاية كهربائية',
            'image' => null,
            'status' => 'active',
            'starts_at' => '2026-11-01T07:00:00Z',
            'deadline_at' => '2026-11-08T07:00:00Z',
            'current_unit_price' => $price,
            'lowest_unit_price' => $price,
            'progress' => [
                'campaign_id' => $id,
                'status' => 'active',
                'committed_qty' => 53,
                'min_target_qty' => 100,
                'max_qty' => 150,
                'remaining_qty' => 95,
                'units_to_target' => 47,
                'deal_confirmed_at' => null,
                'current_unit_price' => $price,
                'deadline_at' => '2026-11-08T07:00:00Z',
                'updated_at' => '2026-11-02T09:00:00Z',
                'version' => 12,
            ],
            'description' => 'وصف',
            'category' => null,
            'variants' => [],
            'images' => [],
            'tiers' => [
                ['min_qty' => 1, 'max_qty' => 150, 'unit_price' => $price, 'is_current' => true],
            ],
            'per_customer_max_qty' => null,
            'original_deadline_at' => null,
            'final_unit_price' => null,
            'cancellation_rule' => 'before_deal_confirmed',
        ], $overrides);
    }
}
