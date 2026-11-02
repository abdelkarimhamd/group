<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    #[Test]
    public function the_session_time_zone_is_utc(): void
    {
        $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
    }

    #[Test]
    public function tests_run_against_the_test_database(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
    }
}
