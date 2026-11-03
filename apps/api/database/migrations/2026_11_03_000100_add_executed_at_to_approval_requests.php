<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an approved campaign cancellation was carried out (E15). Until
     * then it counts as waiting: no new capture starts, the campaign does
     * not move on, and finance:requeue-approved queues it again if its job
     * ran out of tries or was lost. A refund keeps its own status instead.
     */
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->timestampTz('executed_at')->nullable();
        });

        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_executed_check CHECK (executed_at IS NULL OR status = 'approved')");
        DB::statement("CREATE INDEX approval_requests_waiting ON approval_requests (subject_type, subject_id) WHERE status = 'approved' AND executed_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS approval_requests_waiting');
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT IF EXISTS approval_requests_executed_check');

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn('executed_at');
        });
    }
};
