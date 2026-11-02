<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saudi National Address. Orders copy the address they ship to, so an
        // address can be edited or deleted without touching past orders.
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 16);
            $table->string('city', 100);
            $table->string('district', 100);
            $table->string('street', 150);
            $table->char('building_no', 4);
            $table->char('postal_code', 5);
            $table->char('additional_no', 4)->nullable();
            $table->char('short_address', 8)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index('user_id');
        });

        // At most one default address per customer.
        DB::statement('CREATE UNIQUE INDEX addresses_one_default_per_user ON addresses (user_id) WHERE is_default');
        DB::statement('ALTER TABLE addresses ADD CONSTRAINT addresses_coordinates_check CHECK ((lat IS NULL) = (lng IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
