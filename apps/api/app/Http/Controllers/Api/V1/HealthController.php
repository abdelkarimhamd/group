<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness check for load balancers and uptime monitors: the API answers
 * 200 only when PostgreSQL and Redis both respond.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'redis' => $this->check('redis', fn () => Redis::connection()->ping()),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'message' => __($healthy ? 'messages.health.ok' : 'messages.health.degraded'),
            'checks' => array_map(fn (bool $ok) => $ok ? 'ok' : 'failing', $checks),
            'time' => now()->toIso8601ZuluString(),
        ], $healthy ? 200 : 503);
    }

    private function check(string $name, callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $e) {
            Log::warning('Health check failed', ['check' => $name, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
