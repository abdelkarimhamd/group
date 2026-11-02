<?php

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('reason')->nullable();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
        });

        DB::statement("ALTER TABLE status_transitions ADD CONSTRAINT status_transitions_actor_type_check CHECK (actor_type IN ('system', 'admin', 'user'))");

        AppendOnly::protect('status_transitions');
    }

    public function down(): void
    {
        AppendOnly::release('status_transitions');
        Schema::dropIfExists('status_transitions');
    }
};
