<?php

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Evidence of consent under the PDPL: which policy version, when, and
        // from where. Never changed or removed.
        Schema::create('privacy_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('policy_version', 20);
            $table->timestampTz('accepted_at');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'accepted_at']);
        });

        AppendOnly::protect('privacy_consents');
    }

    public function down(): void
    {
        AppendOnly::release('privacy_consents');
        Schema::dropIfExists('privacy_consents');
    }
};
