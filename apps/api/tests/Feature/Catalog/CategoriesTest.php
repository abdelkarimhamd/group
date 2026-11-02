<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Identity\Enums\AdminRole;
use App\Support\Database\UniqueViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->adminWithToken(AdminRole::Sourcing);
    }

    #[Test]
    public function customers_see_categories_in_their_language(): void
    {
        $kitchen = Category::factory()->create(['slug' => 'kitchen', 'name_ar' => 'المطبخ', 'name_en' => 'Kitchen', 'sort_order' => 1]);
        Category::factory()->create(['slug' => 'blenders', 'name_ar' => 'خلاطات', 'name_en' => 'Blenders', 'sort_order' => 2, 'parent_id' => $kitchen->id]);

        $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'المطبخ')
            ->assertJsonPath('data.1.name', 'خلاطات')
            ->assertJsonPath('data.1.parent_id', $kitchen->uuid);

        $this->getJson('/api/v1/categories', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Kitchen')
            ->assertJsonPath('data.0.slug', 'kitchen')
            ->assertJsonPath('data.0.parent_id', null);
    }

    #[Test]
    public function admins_create_and_update_categories_with_an_audit_trail(): void
    {
        $parent = $this->withToken($this->token)
            ->postJson('/api/v1/admin/categories', ['slug' => 'kitchen', 'name_ar' => 'المطبخ', 'name_en' => 'Kitchen'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 0)
            ->json('data');

        $child = $this->withToken($this->token)
            ->postJson('/api/v1/admin/categories', ['slug' => 'blenders', 'name_ar' => 'خلاطات', 'name_en' => 'Blenders', 'parent_id' => $parent['id']])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parent['id'])
            ->json('data');

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/categories/{$child['id']}", ['slug' => 'blenders', 'name_ar' => 'خلاطات', 'name_en' => 'Blenders and mixers', 'parent_id' => null, 'sort_order' => 5])
            ->assertOk()
            ->assertJsonPath('data.name_en', 'Blenders and mixers')
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.sort_order', 5);

        $this->withToken($this->token)->getJson('/api/v1/admin/categories')->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(2, AuditLog::where('action', 'category.create')->count());
        $update = AuditLog::where('action', 'category.update')->sole();
        $this->assertSame('Blenders', $update->before['name_en']);
        $this->assertSame('Blenders and mixers', $update->after['name_en']);
    }

    #[Test]
    public function slugs_are_unique_and_well_formed(): void
    {
        $existing = Category::factory()->create(['slug' => 'kitchen']);

        $this->withToken($this->token)
            ->postJson('/api/v1/admin/categories', ['slug' => 'kitchen', 'name_ar' => 'x', 'name_en' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->withToken($this->token)
            ->postJson('/api/v1/admin/categories', ['slug' => 'Not A Slug', 'name_ar' => 'x', 'name_en' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        // Keeping its own slug is not a conflict.
        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/categories/{$existing->uuid}", ['slug' => 'kitchen', 'name_ar' => 'x', 'name_en' => 'x'])
            ->assertOk();
    }

    #[Test]
    public function a_category_cannot_move_under_itself_or_its_descendants(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);

        foreach ([$root, $grandchild] as $newParent) {
            $this->withToken($this->token)
                ->patchJson("/api/v1/admin/categories/{$root->uuid}", [
                    'slug' => $root->slug, 'name_ar' => $root->name_ar, 'name_en' => $root->name_en, 'parent_id' => $newParent->uuid,
                ], ['Accept-Language' => 'en'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'category_cycle')
                ->assertJsonValidationErrors('parent_id');
        }

        $this->assertNull($root->fresh()->parent_id);
    }

    #[Test]
    public function a_category_sent_without_sort_order_goes_back_to_zero(): void
    {
        $category = Category::factory()->create(['slug' => 'kitchen', 'sort_order' => 4]);

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/categories/{$category->uuid}", ['slug' => 'kitchen', 'name_ar' => 'المطبخ', 'name_en' => 'Kitchen'])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 0);

        // A number typed into a form arrives as a string and still comes back as a number.
        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/categories/{$category->uuid}", ['slug' => 'kitchen', 'name_ar' => 'المطبخ', 'name_en' => 'Kitchen', 'sort_order' => '3'])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 3);
    }

    #[Test]
    public function a_slug_taken_at_the_same_moment_is_a_validation_error(): void
    {
        Category::factory()->create(['slug' => 'kitchen']);

        // The form check passed for both requests; the database catches the second.
        try {
            UniqueViolation::asValidationError(
                fn () => Category::factory()->create(['slug' => 'kitchen']),
                ['categories_slug_unique' => 'slug'],
            );
            $this->fail('A duplicate slug was saved.');
        } catch (ValidationException $e) {
            $this->assertSame(['slug'], array_keys($e->errors()));
        }
    }

    #[Test]
    public function catalog_permissions_are_checked(): void
    {
        [, $support] = $this->adminWithToken(AdminRole::Support);
        [, $operations] = $this->adminWithToken(AdminRole::Operations);

        $this->withToken($support)->getJson('/api/v1/admin/categories')->assertForbidden();
        $this->forgetAuthenticatedUsers();
        $this->withToken($operations)->getJson('/api/v1/admin/categories')->assertOk();
        $this->withToken($operations)->postJson('/api/v1/admin/categories', ['slug' => 'x', 'name_ar' => 'x', 'name_en' => 'x'])->assertForbidden();
        $this->forgetAuthenticatedUsers();
        $this->flushHeaders()->getJson('/api/v1/admin/categories')->assertUnauthorized();
    }
}
