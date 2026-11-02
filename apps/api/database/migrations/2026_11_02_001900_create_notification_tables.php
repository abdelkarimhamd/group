<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Firebase push targets. A token belongs to the device's current
        // account: registering it from another account moves it. Tokens can
        // be up to 4 KB, more than a btree index takes, so the unique index
        // is on its SHA-256.
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 8);
            $table->text('fcm_token');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->index(['user_id', 'last_seen_at']);
        });

        DB::statement("ALTER TABLE device_tokens ADD CONSTRAINT device_tokens_platform_check CHECK (platform IN ('ios', 'android', 'web'))");

        // One row per message to one customer on one channel. The row is
        // written in the transaction that makes the news, so a rolled-back
        // change sends nothing, and the dedupe key makes a re-run send
        // nothing twice.
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 8);
            $table->string('template_key', 40);
            $table->string('locale', 2);
            $table->jsonb('payload')->default('{}');
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->string('status', 8)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            // When a queued message is due to be tried (again): after a
            // failed try, or once a try in progress has taken too long.
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'id']);
            $table->index(['status', 'id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check CHECK (channel IN ('push', 'sms', 'email', 'in_app'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_status_check CHECK (status IN ('queued', 'sent', 'failed', 'read'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_locale_check CHECK (locale IN ('ar', 'en'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_payload_check CHECK (jsonb_typeof(payload) = 'object')");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_sent_check CHECK ((status IN ('sent', 'read')) = (sent_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('device_tokens');
    }
};
