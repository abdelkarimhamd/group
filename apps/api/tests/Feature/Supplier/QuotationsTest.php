<?php

namespace Tests\Feature\Supplier;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Modules\Supplier\Services\QuotationUsage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class QuotationsTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private string $token;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // 22:30 UTC on 2 Nov is already 3 Nov in Riyadh.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 22:30:00', 'UTC'));
        [, $this->token] = $this->adminWithToken(AdminRole::Sourcing);
        $this->supplier = Supplier::factory()->create();
        $this->product = Product::factory()->active()->create();
    }

    #[Test]
    public function the_server_works_out_the_landed_cost(): void
    {
        $quote = $this->withToken($this->token)->postJson('/api/v1/admin/quotations', $this->input())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.unit_cost', ['amount_minor' => 800, 'currency' => 'USD', 'formatted' => '8.00 USD'])
            ->assertJsonPath('data.fx_rate_to_sar', '3.75000000')
            ->assertJsonPath('data.landed_cost_breakdown.freight.amount_minor', 800)
            ->assertJsonPath('data.landed_cost_breakdown.import_vat.currency', 'SAR')
            ->assertJsonPath('data.landed_cost_per_unit', ['amount_minor' => 6140, 'currency' => 'SAR', 'formatted' => '61.40 SAR'])
            ->assertJsonPath('data.valid_until', '2026-12-01')
            ->assertJsonPath('data.product_variant_id', null)
            ->assertJsonPath('data.attachment_url', null)
            ->assertJsonPath('data.used_by_campaign_ids', [])
            ->json('data');

        $model = SupplierQuotation::where('uuid', $quote['id'])->sole();
        $this->assertSame(6140, $model->landed_cost_per_unit_halalas);
        $this->assertEquals(['product' => 3000, 'freight' => 800, 'customs_duty' => 150, 'import_vat' => 590, 'clearance' => 100, 'last_mile' => 1500], $model->landed_cost_breakdown);
        $this->assertSame(6140, AuditLog::where('action', 'quotation.create')->sole()->after['landed_cost_per_unit_halalas']);
    }

    #[Test]
    public function quotations_are_validated(): void
    {
        $otherVariant = ProductVariant::factory()->create();

        $this->withToken($this->token)->postJson('/api/v1/admin/quotations', $this->input([
            'fx_rate_to_sar' => 3.75,
            'product_variant_id' => $otherVariant->uuid,
            'landed_cost_breakdown' => ['product_halalas' => -1],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fx_rate_to_sar', 'landed_cost_breakdown.product_halalas', 'landed_cost_breakdown.freight_halalas']);

        $this->withToken($this->token)->postJson('/api/v1/admin/quotations', $this->input(['product_variant_id' => $otherVariant->uuid]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_variant_id');

        foreach (['0', '0.00000000', '1.123456789', '12345678901'] as $fx) {
            $this->withToken($this->token)->postJson('/api/v1/admin/quotations', $this->input(['fx_rate_to_sar' => $fx]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('fx_rate_to_sar');
        }

        // A SAR quote needs no conversion.
        $this->withToken($this->token)->postJson('/api/v1/admin/quotations', $this->input(['currency' => 'SAR', 'fx_rate_to_sar' => '3.75']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fx_rate_to_sar');
        $this->withToken($this->token)->postJson('/api/v1/admin/quotations', $this->input(['currency' => 'SAR', 'fx_rate_to_sar' => '1.00000000']))
            ->assertCreated();

        $variant = $this->product->variants()->sole();
        $this->withToken($this->token)->postJson('/api/v1/admin/quotations', $this->input(['product_variant_id' => $variant->uuid]))
            ->assertCreated()
            ->assertJsonPath('data.product_variant_id', $variant->uuid);
    }

    #[Test]
    public function a_blocked_suppliers_quotes_are_refused(): void
    {
        $blocked = Supplier::factory()->blocked()->create();

        $this->withToken($this->token)->postJson('/api/v1/admin/quotations', $this->input(['supplier_id' => $blocked->uuid]), ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'supplier_blocked')
            ->assertJsonValidationErrors('supplier_id');

        // An existing draft can still be edited, but not accepted.
        $quote = SupplierQuotation::factory()->create(['supplier_id' => $blocked->id, 'product_id' => $this->product->id]);
        $this->withToken($this->token)->patchJson("/api/v1/admin/quotations/{$quote->uuid}", $this->input(['supplier_id' => $blocked->uuid, 'moq' => 50]))
            ->assertOk();
        $this->withToken($this->token)->patchJson("/api/v1/admin/quotations/{$quote->uuid}", $this->input(['supplier_id' => $blocked->uuid, 'status' => 'accepted']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'supplier_blocked');
    }

    #[Test]
    public function a_quote_is_accepted_only_while_valid_in_riyadh(): void
    {
        $quote = SupplierQuotation::factory()->create(['supplier_id' => $this->supplier->id, 'product_id' => $this->product->id]);

        // 2 Nov has already ended in Riyadh.
        $this->withToken($this->token)->patchJson("/api/v1/admin/quotations/{$quote->uuid}", $this->input(['valid_until' => '2026-11-02', 'status' => 'accepted']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'quotation_expired')
            ->assertJsonValidationErrors('valid_until');

        $this->withToken($this->token)->patchJson("/api/v1/admin/quotations/{$quote->uuid}", $this->input(['valid_until' => '2026-11-03', 'status' => 'accepted']))
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $transition = StatusTransition::where('subject_type', 'supplier_quotation')->sole();
        $this->assertSame(['draft', 'accepted'], [$transition->from_status, $transition->to_status]);

        $this->withToken($this->token)->patchJson("/api/v1/admin/quotations/{$quote->uuid}", $this->input(['valid_until' => '2026-11-03', 'status' => 'expired']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    #[Test]
    public function quotations_past_their_date_expire_overnight(): void
    {
        $open = SupplierQuotation::factory()->create(['valid_until' => '2026-11-02']);
        $accepted = SupplierQuotation::factory()->accepted()->create(['valid_until' => '2026-11-01']);
        $today = SupplierQuotation::factory()->create(['valid_until' => '2026-11-03']);
        $rejected = SupplierQuotation::factory()->create(['valid_until' => '2026-10-01', 'status' => 'rejected']);

        $this->artisan('quotations:expire')->expectsOutputToContain('Expired 2 quotation(s).')->assertSuccessful();

        $this->assertSame('expired', $open->fresh()->status->value);
        $this->assertSame('expired', $accepted->fresh()->status->value);
        $this->assertSame('draft', $today->fresh()->status->value);
        $this->assertSame('rejected', $rejected->fresh()->status->value);

        $transitions = StatusTransition::where('subject_type', 'supplier_quotation')->orderBy('id')->get();
        $this->assertSame(['draft', 'accepted'], $transitions->pluck('from_status')->all());
        $this->assertSame(['system', 'system'], $transitions->pluck('actor_type')->all());

        $this->artisan('quotations:expire')->expectsOutputToContain('Expired 0 quotation(s).')->assertSuccessful();

        // Reviving one takes a new date and a status.
        $this->withToken($this->token)->patchJson("/api/v1/admin/quotations/{$open->uuid}", $this->input(['supplier_id' => $open->supplier->uuid, 'product_id' => $open->product->uuid]))
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
        $this->withToken($this->token)->patchJson("/api/v1/admin/quotations/{$open->uuid}", $this->input(['supplier_id' => $open->supplier->uuid, 'product_id' => $open->product->uuid, 'status' => 'draft']))
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    #[Test]
    public function a_quote_a_campaign_relies_on_is_frozen(): void
    {
        $quote = SupplierQuotation::factory()->create(['supplier_id' => $this->supplier->id, 'product_id' => $this->product->id]);
        $this->app->instance(QuotationUsage::class, new class extends QuotationUsage
        {
            public function isFrozen(SupplierQuotation $quotation): bool
            {
                return true;
            }
        });

        $this->withToken($this->token)->patchJson("/api/v1/admin/quotations/{$quote->uuid}", $this->input(), ['Accept-Language' => 'en'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'quotation_frozen');

        Storage::fake('local');
        $this->withToken($this->token)->post("/api/v1/admin/quotations/{$quote->uuid}/attachment", [
            'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function the_quote_file_is_private_and_replaceable(): void
    {
        Storage::fake('local');
        $quote = SupplierQuotation::factory()->create(['supplier_id' => $this->supplier->id, 'product_id' => $this->product->id]);

        $first = $this->withToken($this->token)->post("/api/v1/admin/quotations/{$quote->uuid}/attachment", [
            'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.attachment_url');

        $path = $quote->fresh()->attachment_path;
        $this->assertStringStartsWith("quotations/{$quote->uuid}/", $path);
        $this->assertStringContainsString('expiration=', $first);
        Storage::disk('local')->assertExists($path);

        $this->withToken($this->token)->post("/api/v1/admin/quotations/{$quote->uuid}/attachment", [
            'file' => UploadedFile::fake()->image('screenshot.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->withToken($this->token)->post("/api/v1/admin/quotations/{$quote->uuid}/attachment", [
            'file' => UploadedFile::fake()->create('quote.exe', 10),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(2, AuditLog::where('action', 'quotation.attach')->count());
    }

    #[Test]
    public function quotations_are_filtered_by_supplier_product_and_status(): void
    {
        SupplierQuotation::factory()->create(['supplier_id' => $this->supplier->id, 'product_id' => $this->product->id]);
        SupplierQuotation::factory()->accepted()->create(['supplier_id' => $this->supplier->id]);
        SupplierQuotation::factory()->create();

        $this->withToken($this->token)->getJson("/api/v1/admin/quotations?supplier_id={$this->supplier->uuid}")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($this->token)->getJson("/api/v1/admin/quotations?product_id={$this->product->uuid}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->token)->getJson('/api/v1/admin/quotations?status=accepted&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
        $this->withToken($this->token)->getJson('/api/v1/admin/quotations?supplier_id=nope')->assertStatus(422);

        $this->withToken($this->token)->getJson('/api/v1/admin/quotations/0195f8a4-0000-7000-8000-000000000000')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->uuid,
            'product_id' => $this->product->uuid,
            'moq' => 100,
            'unit_cost_minor' => 800,
            'currency' => 'USD',
            'shipping_terms' => 'FOB',
            'lead_time_days' => 30,
            'valid_until' => '2026-12-01',
            'fx_rate_to_sar' => '3.75',
            'landed_cost_basis_qty' => 200,
            'landed_cost_breakdown' => [
                'product_halalas' => 3000,
                'freight_halalas' => 800,
                'customs_duty_halalas' => 150,
                'import_vat_halalas' => 590,
                'clearance_halalas' => 100,
                'last_mile_halalas' => 1500,
            ],
        ], $overrides);
    }
}
