<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff who use the admin portal. Kept apart from customers so a customer
        // token can never act as an admin. Sign-in is password plus an SMS code
        // sent to phone_e164.
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email', 191)->unique();
            $table->string('password');
            $table->string('name', 100);
            // Unique: one phone is never the second factor for two accounts.
            $table->string('phone_e164', 16)->unique();
            $table->string('status', 16)->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE admin_users ADD CONSTRAINT admin_users_email_lower_check CHECK (email = lower(email))');
        DB::statement("ALTER TABLE admin_users ADD CONSTRAINT admin_users_status_check CHECK (status IN ('active', 'blocked'))");
        DB::statement("ALTER TABLE admin_users ADD CONSTRAINT admin_users_phone_check CHECK (phone_e164 ~ '^\\+9665[0-9]{8}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
