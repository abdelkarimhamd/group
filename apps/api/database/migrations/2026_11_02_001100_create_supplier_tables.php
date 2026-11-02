<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alibaba sellers. Only approved suppliers can back a campaign.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 200);
            $table->text('alibaba_store_url');
            $table->char('country', 2);
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_channel', 200)->nullable();
            $table->string('status', 16)->default('prospect');
            $table->unsignedSmallInteger('quality_rating')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'id']);
        });

        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_check CHECK (status IN ('prospect', 'approved', 'blocked'))");
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_country_check CHECK (country ~ '^[A-Z]{2}$')");
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_quality_rating_check CHECK (quality_rating BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_reviewed_check CHECK ((reviewed_by_admin_id IS NULL) = (reviewed_at IS NULL))');

        // One row per quote. Frozen once a campaign past draft uses it, because
        // the campaign's price and margin were checked against it.
        Schema::create('supplier_quotations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('moq');
            $table->bigInteger('unit_cost_minor');
            $table->char('currency', 3);
            $table->string('shipping_terms', 20);
            $table->unsignedInteger('lead_time_days');
            $table->date('valid_until');
            $table->decimal('fx_rate_to_sar', 18, 8);
            $table->unsignedInteger('landed_cost_basis_qty');
            $table->jsonb('landed_cost_breakdown');
            $table->bigInteger('landed_cost_per_unit_halalas');
            $table->string('attachment_disk', 32)->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['supplier_id', 'id']);
            $table->index(['product_id', 'id']);
            $table->index(['status', 'valid_until']);
        });

        DB::statement("ALTER TABLE supplier_quotations ADD CONSTRAINT supplier_quotations_status_check CHECK (status IN ('draft', 'accepted', 'rejected', 'expired'))");
        DB::statement("ALTER TABLE supplier_quotations ADD CONSTRAINT supplier_quotations_currency_check CHECK (currency IN ('SAR', 'USD', 'CNY'))");
        DB::statement('ALTER TABLE supplier_quotations ADD CONSTRAINT supplier_quotations_amounts_check CHECK (moq > 0 AND unit_cost_minor > 0 AND fx_rate_to_sar > 0 AND landed_cost_basis_qty > 0 AND landed_cost_per_unit_halalas >= 0)');
        DB::statement("ALTER TABLE supplier_quotations ADD CONSTRAINT supplier_quotations_breakdown_check CHECK (jsonb_typeof(landed_cost_breakdown) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_quotations');
        Schema::dropIfExists('suppliers');
    }
};
