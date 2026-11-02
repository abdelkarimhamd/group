<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per SMS code sent. Only an HMAC of the code is stored.
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('purpose', 20);
            $table->string('phone_e164', 16);
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->timestampTz('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('consumed_at')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampsTz();

            $table->index(['phone_e164', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT otp_challenges_purpose_check CHECK (purpose IN ('customer_login', 'admin_login'))");
        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT otp_challenges_admin_check CHECK ((purpose = 'admin_login') = (admin_user_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
