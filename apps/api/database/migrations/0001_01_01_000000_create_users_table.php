<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customers. They sign in with a Saudi mobile number and an SMS code, so
        // there is no password. An anonymized account (PDPL erasure) loses its
        // phone number, which frees the number for a new account.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('phone_e164', 16)->nullable()->unique();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('name', 100)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('locale', 2)->default('ar');
            $table->string('status', 16)->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('ar', 'en'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'blocked', 'anonymized'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_phone_check CHECK (phone_e164 ~ '^\\+9665[0-9]{8}$')");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_anonymized_check CHECK ((status = 'anonymized') = (anonymized_at IS NOT NULL) AND (status <> 'anonymized' OR phone_e164 IS NULL))");

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
