<?php

namespace Tests\Contract;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The routes Laravel serves and the operations the contract marks `live`
 * must be the same set. Operations marked `mock` exist only on the mock
 * server until their epic ships.
 */
class RouteCoverageTest extends TestCase
{
    #[Test]
    public function every_api_route_is_in_the_contract(): void
    {
        $missing = array_diff($this->servedOperations(), array_keys(OpenApiContract::operations()));

        $this->assertSame([], array_values($missing), 'Routes missing from openapi/v1.yaml.');
    }

    #[Test]
    public function every_served_operation_is_marked_live(): void
    {
        $operations = OpenApiContract::operations();

        $notLive = array_filter(
            $this->servedOperations(),
            fn (string $key) => isset($operations[$key]) && ($operations[$key]['x-status'] ?? null) !== 'live',
        );

        $this->assertSame([], array_values($notLive), 'Served routes still marked x-status: mock.');
    }

    #[Test]
    public function every_live_operation_is_served(): void
    {
        $live = array_keys(array_filter(
            OpenApiContract::operations(),
            fn (array $operation) => ($operation['x-status'] ?? null) === 'live',
        ));

        $unserved = array_diff($live, $this->servedOperations());

        $this->assertSame([], array_values($unserved), 'Operations marked live that no route serves.');
    }

    #[Test]
    public function every_operation_names_its_epic_and_status(): void
    {
        $problems = [];

        foreach (OpenApiContract::operations() as $key => $operation) {
            if (! preg_match('/^E([1-9]|1[0-9])$/', (string) ($operation['x-epic'] ?? ''))) {
                $problems[] = "{$key}: x-epic";
            }

            if (! in_array($operation['x-status'] ?? null, ['live', 'mock'], true)) {
                $problems[] = "{$key}: x-status";
            }
        }

        $this->assertSame([], $problems);
    }

    /**
     * @return list<string> Keys like "GET /health".
     */
    private function servedOperations(): array
    {
        $served = [];

        foreach (Route::getRoutes() as $route) {
            $path = OpenApiContract::pathFor($route);

            if ($path === null) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array(strtolower($method), OpenApiContract::METHODS, true)) {
                    $served[] = $method.' '.$path;
                }
            }
        }

        sort($served);

        return array_values(array_unique($served));
    }
}
