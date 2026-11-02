<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('slug', 100)->unique();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index('parent_id');
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');

        // Products are hand-picked Alibaba listings. A product is sold through
        // campaigns, never directly.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name_ar', 200);
            $table->string('name_en', 200);
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->text('alibaba_url');
            $table->string('hs_code', 12)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'id']);
            $table->index('category_id');
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'archived'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_hs_code_check CHECK (hs_code ~ '^[0-9]{6,12}$')");

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->jsonb('option_values')->default('{}');
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->index('product_id');
        });

        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT product_variants_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT product_variants_option_values_check CHECK (jsonb_typeof(option_values) = 'object')");

        // Files live on the media disk (config groupbuy.media.disk); only the
        // path is stored here.
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('alt_ar', 200)->nullable();
            $table->string('alt_en', 200)->nullable();
            $table->timestampsTz();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
