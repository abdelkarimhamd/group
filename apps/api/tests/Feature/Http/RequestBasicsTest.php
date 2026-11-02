<?php

namespace Tests\Feature\Http;

use App\Support\RequestContext;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequestBasicsTest extends TestCase
{
    #[Test]
    public function health_reports_ok_when_database_and_redis_respond(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'ok');
    }

    #[Test]
    public function health_reports_degraded_with_503_when_redis_is_down(): void
    {
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('connection refused'));

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'failing');
    }

    #[Test]
    public function arabic_is_the_default_language(): void
    {
        // The test client sends "en-us" unless told otherwise, so clear the header explicitly.
        $this->getJson('/api/v1/health', ['Accept-Language' => ''])
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', 'جميع الأنظمة تعمل.');
    }

    #[Test]
    public function english_is_used_when_the_client_prefers_it(): void
    {
        $this->getJson('/api/v1/health', ['Accept-Language' => 'en-US,en;q=0.9,ar;q=0.5'])
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'All systems are working.');
    }

    #[Test]
    public function an_unsupported_language_falls_back_to_arabic(): void
    {
        $this->getJson('/api/v1/health', ['Accept-Language' => 'fr-FR'])
            ->assertHeader('Content-Language', 'ar');
    }

    #[Test]
    public function every_response_carries_a_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
        $this->assertSame($response->headers->get('X-Request-Id'), RequestContext::requestId());
    }

    #[Test]
    public function a_well_formed_incoming_request_id_is_kept(): void
    {
        $this->getJson('/api/v1/health', ['X-Request-Id' => 'lb-abc123-def456'])
            ->assertHeader('X-Request-Id', 'lb-abc123-def456');
    }

    #[Test]
    public function a_malformed_incoming_request_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/health', ['X-Request-Id' => "bad id\nwith newline"]);

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    #[Test]
    public function unknown_api_routes_answer_in_json(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
