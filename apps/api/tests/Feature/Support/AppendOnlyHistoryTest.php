<?php

namespace Tests\Feature\Support;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppendOnlyHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_audit_logger_records_who_did_what(): void
    {
        RequestContext::start('req-12345678', '203.0.113.7', 'PHPUnit');

        $log = app(AuditLogger::class)->record(
            Actor::admin(7),
            'campaign.approve',
            before: ['status' => 'pending_approval'],
            after: ['status' => 'scheduled'],
        );

        $fresh = AuditLog::findOrFail($log->id);
        $this->assertSame('admin', $fresh->actor_type);
        $this->assertSame(7, $fresh->actor_id);
        $this->assertSame('campaign.approve', $fresh->action);
        $this->assertSame(['status' => 'scheduled'], $fresh->after);
        $this->assertSame('req-12345678', $fresh->request_id);
        $this->assertSame('203.0.113.7', $fresh->ip);
        $this->assertSame('PHPUnit', $fresh->user_agent);
    }

    #[Test]
    public function status_history_records_a_transition_with_its_subject(): void
    {
        $subject = $this->subject();

        $transition = app(StatusHistory::class)->record(
            $subject,
            'pending_approval',
            'scheduled',
            Actor::system(),
            reason: 'approved',
            metadata: ['tier_version' => 1],
        );

        $fresh = StatusTransition::findOrFail($transition->id);
        $this->assertSame($subject->getMorphClass(), $fresh->subject_type);
        $this->assertSame($subject->getKey(), $fresh->subject_id);
        $this->assertSame('pending_approval', $fresh->from_status);
        $this->assertSame('scheduled', $fresh->to_status);
        $this->assertSame('system', $fresh->actor_type);
        $this->assertNull($fresh->actor_id);
        $this->assertSame(['tier_version' => 1], $fresh->metadata);
    }

    #[Test]
    public function models_refuse_updates_and_deletes(): void
    {
        $log = app(AuditLogger::class)->record(Actor::system(), 'test.action');

        try {
            $log->update(['action' => 'rewritten']);
            $this->fail('An audit log was updated.');
        } catch (LogicException) {
            // expected
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rawMutations(): array
    {
        return [
            'audit_logs update' => ['audit_logs', "UPDATE audit_logs SET action = 'rewritten'"],
            'audit_logs delete' => ['audit_logs', 'DELETE FROM audit_logs'],
            'audit_logs truncate' => ['audit_logs', 'TRUNCATE audit_logs'],
            'status_transitions update' => ['status_transitions', "UPDATE status_transitions SET to_status = 'rewritten'"],
            'status_transitions delete' => ['status_transitions', 'DELETE FROM status_transitions'],
            'status_transitions truncate' => ['status_transitions', 'TRUNCATE status_transitions'],
        ];
    }

    #[Test]
    #[DataProvider('rawMutations')]
    public function the_database_rejects_raw_updates_deletes_and_truncates(string $table, string $sql): void
    {
        app(AuditLogger::class)->record(Actor::system(), 'test.action');
        DB::transaction(fn () => app(StatusHistory::class)->record($this->subject(), null, 'draft', Actor::system()));
        $rowsBefore = DB::table($table)->count();

        try {
            // A nested transaction is a savepoint, so the outer test transaction survives the error.
            DB::transaction(fn () => DB::statement($sql));
            $this->fail("The database allowed: {$sql}");
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame($rowsBefore, DB::table($table)->count());
    }

    private function subject(): AuditLog
    {
        // Any persisted model works as a subject; an audit log is the simplest one available here.
        return app(AuditLogger::class)->record(Actor::system(), 'subject.placeholder');
    }
}
