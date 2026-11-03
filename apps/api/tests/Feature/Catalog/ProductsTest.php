<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Enums\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Fakes\UndeletableDisk;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private string $token;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->adminWithToken(AdminRole::Sourcing);
        $this->category = Category::factory()->create();
    }

    #[Test]
    public function a_product_is_created_as_a_draft_with_its_variants(): void
    {
        $product = $this->withToken($this->token)->postJson('/api/v1/admin/products', $this->input([
            'status' => 'active',
            'variants' => [
                ['sku' => 'BL-500-BLK', 'name_ar' => 'أسود', 'name_en' => 'Black', 'option_values' => ['color' => 'black'], 'weight_grams' => 1800],
                ['sku' => 'BL-500-WHT', 'name_ar' => 'أبيض', 'name_en' => 'White'],
                ['sku' => 'BL-500-RED', 'name_ar' => 'أحمر', 'name_en' => 'Red', 'status' => 'archived', 'weight_grams' => '1750'],
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.category_id', $this->category->uuid)
            ->assertJsonPath('data.variants.0.sku', 'BL-500-BLK')
            ->assertJsonPath('data.variants.0.option_values', ['color' => 'black'])
            ->assertJsonPath('data.variants.1.status', 'active')
            ->assertJsonPath('data.variants.2.status', 'archived')
            ->assertJsonPath('data.variants.2.weight_grams', 1750)
            ->assertJsonPath('data.images', [])
            ->json('data');

        // An empty option map stays a JSON object.
        $this->assertStringContainsString('"option_values":{}', $this->withToken($this->token)->getJson("/api/v1/admin/products/{$product['id']}")->getContent());

        $audit = AuditLog::where('action', 'product.create')->sole();
        $this->assertSame(['BL-500-BLK', 'BL-500-WHT', 'BL-500-RED'], $audit->after['variants']);
    }

    #[Test]
    public function variants_are_validated(): void
    {
        ProductVariant::factory()->create(['sku' => 'TAKEN-1']);

        $this->withToken($this->token)->postJson('/api/v1/admin/products', $this->input([
            'variants' => [
                ['sku' => 'TAKEN-1', 'name_ar' => 'x', 'name_en' => 'x'],
                ['sku' => 'DUP', 'name_ar' => 'x', 'name_en' => 'x', 'option_values' => ['not a list']],
                ['sku' => 'dup', 'name_ar' => 'x', 'name_en' => 'x'],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['variants.0.sku', 'variants.1.sku', 'variants.1.option_values', 'variants.2.sku']);

        $this->assertSame(1, Product::count());
    }

    #[Test]
    public function products_are_listed_searched_and_paged(): void
    {
        $blender = Product::factory()->create(['name_en' => 'Electric blender', 'category_id' => $this->category->id]);
        ProductVariant::factory()->create(['product_id' => $blender->id, 'sku' => 'BL-500']);
        Product::factory()->active()->create(['name_en' => 'Kettle', 'category_id' => $this->category->id]);
        Product::factory()->create(['name_en' => 'Toaster', 'category_id' => $this->category->id]);

        $first = $this->withToken($this->token)->getJson('/api/v1/admin/products?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name_en', 'Toaster');

        $this->withToken($this->token)->getJson('/api/v1/admin/products?per_page=2&cursor='.urlencode($first->json('meta.next_cursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name_en', 'Electric blender')
            ->assertJsonPath('meta.next_cursor', null);

        $this->withToken($this->token)->getJson('/api/v1/admin/products?q=bl-5')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $blender->uuid);
        $this->withToken($this->token)->getJson('/api/v1/admin/products?q=%25')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->token)->getJson('/api/v1/admin/products?status=active')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name_en', 'Kettle');
        $this->withToken($this->token)->getJson('/api/v1/admin/products?status=sold')->assertStatus(422);
    }

    #[Test]
    public function a_product_goes_active_only_with_an_active_variant(): void
    {
        $product = Product::factory()->create(['category_id' => $this->category->id, 'description_en' => 'Old text']);

        $this->withToken($this->token)->patchJson("/api/v1/admin/products/{$product->uuid}", $this->input(['status' => 'active']), ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_needs_variant');

        ProductVariant::factory()->archived()->create(['product_id' => $product->id]);
        $this->withToken($this->token)->patchJson("/api/v1/admin/products/{$product->uuid}", $this->input(['status' => 'active']))
            ->assertStatus(422);

        $this->withToken($this->token)->postJson("/api/v1/admin/products/{$product->uuid}/variants", ['sku' => 'NEW-1', 'name_ar' => 'x', 'name_en' => 'x'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $this->withToken($this->token)->patchJson("/api/v1/admin/products/{$product->uuid}", $this->input(['status' => 'active', 'name_en' => 'Blender 500W']))
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.name_en', 'Blender 500W')
            ->assertJsonPath('data.description_en', null)
            ->assertJsonCount(2, 'data.variants');

        $transition = StatusTransition::where('subject_type', 'product')->sole();
        $this->assertSame(['draft', 'active'], [$transition->from_status, $transition->to_status]);
        $this->assertSame('Blender 500W', AuditLog::where('action', 'product.update')->sole()->after['name_en']);
    }

    #[Test]
    public function the_last_active_variant_of_an_active_product_cannot_be_archived(): void
    {
        $product = Product::factory()->active()->create(['category_id' => $this->category->id]);
        $variant = $product->variants()->sole();

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/variants/{$variant->uuid}", ['sku' => $variant->sku, 'name_ar' => 'x', 'name_en' => 'x', 'status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'last_active_variant');

        $second = ProductVariant::factory()->create(['product_id' => $product->id]);

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/variants/{$variant->uuid}", ['sku' => $variant->sku, 'name_ar' => 'x', 'name_en' => 'x', 'status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.option_values', [])
            ->assertJsonPath('data.weight_grams', null);

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/variants/{$second->uuid}", ['sku' => $variant->sku, 'name_ar' => 'x', 'name_en' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');

        $this->assertSame(1, StatusTransition::where('subject_type', 'product_variant')->count());
    }

    #[Test]
    public function photos_are_uploaded_and_deleted(): void
    {
        Storage::fake('public');
        $product = Product::factory()->active()->create(['category_id' => $this->category->id]);
        $variant = $product->variants()->sole();
        $other = ProductVariant::factory()->create();

        $image = $this->withToken($this->token)->post("/api/v1/admin/products/{$product->uuid}/images", [
            'file' => UploadedFile::fake()->image('blender.jpg', 800, 800),
            'product_variant_id' => $variant->uuid,
            'alt_ar' => 'خلاط أسود',
            // Multipart fields arrive as strings.
            'sort_order' => '2',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.product_variant_id', $variant->uuid)
            ->assertJsonPath('data.sort_order', 2)
            ->json('data');

        $path = ProductImage::where('uuid', $image['id'])->sole()->path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith("products/{$product->uuid}/", $path);
        $this->assertStringEndsWith($path, $image['url']);

        $this->withToken($this->token)->getJson("/api/v1/admin/products/{$product->uuid}")
            ->assertJsonPath('data.images.0.id', $image['id']);

        // A variant of another product, or a file that is not a photo, is refused.
        $this->withToken($this->token)->post("/api/v1/admin/products/{$product->uuid}/images", [
            'file' => UploadedFile::fake()->image('x.jpg'),
            'product_variant_id' => $other->uuid,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('product_variant_id');
        $this->withToken($this->token)->post("/api/v1/admin/products/{$product->uuid}/images", [
            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertCount(1, Storage::disk('public')->allFiles());

        $this->withToken($this->token)->deleteJson("/api/v1/admin/product-images/{$image['id']}")->assertNoContent();
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, ProductImage::count());
        $this->assertSame($product->uuid, AuditLog::where('action', 'product_image.delete')->sole()->before['product_id']);

        $this->withToken($this->token)->deleteJson("/api/v1/admin/product-images/{$image['id']}")->assertNotFound();
    }

    #[Test]
    public function a_photo_is_deleted_even_when_its_file_cannot_be_removed(): void
    {
        $disk = UndeletableDisk::fake('public');
        Log::spy();
        $product = Product::factory()->active()->create(['category_id' => $this->category->id]);
        $image = $this->withToken($this->token)->post("/api/v1/admin/products/{$product->uuid}/images", [
            'file' => UploadedFile::fake()->image('blender.jpg', 800, 800),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $path = ProductImage::where('uuid', $image)->sole()->path;

        // The row is gone, so the answer is 204 whatever the storage says;
        // the file left behind is logged for cleanup.
        $this->withToken($this->token)->deleteJson("/api/v1/admin/product-images/{$image}")->assertNoContent();
        $this->assertSame(0, ProductImage::count());
        $disk->assertExists($path);
        Log::shouldHaveReceived('warning')->with('storage.orphan_file', ['disk' => 'public', 'path' => $path])->once();
    }

    #[Test]
    public function an_unknown_product_is_not_found(): void
    {
        $missing = '0195f8a4-0000-7000-8000-000000000000';

        $this->withToken($this->token)->getJson("/api/v1/admin/products/{$missing}")->assertNotFound();
        $this->withToken($this->token)->patchJson("/api/v1/admin/products/{$missing}", $this->input())->assertNotFound();
        $this->withToken($this->token)->postJson("/api/v1/admin/products/{$missing}/variants", ['sku' => 'A', 'name_ar' => 'x', 'name_en' => 'x'])->assertNotFound();
        $this->withToken($this->token)->patchJson("/api/v1/admin/variants/{$missing}", ['sku' => 'A', 'name_ar' => 'x', 'name_en' => 'x'])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->uuid,
            'name_ar' => 'خلاط كهربائي',
            'name_en' => 'Electric blender',
            'alibaba_url' => 'https://www.alibaba.com/product-detail/1600000000000.html',
            'hs_code' => '850940',
        ], $overrides);
    }
}
