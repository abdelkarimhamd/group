<?php

namespace Tests\Contract;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Permissions\AdminPermissions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin operations name the permission they need (`x-permission`). This keeps
 * the contract, the permission catalogue in AdminPermissions and the route
 * middleware in step, so the admin portal can trust the contract.
 */
class PermissionCoverageTest extends TestCase
{
    /**
     * Admin operations every signed-in admin may use, whatever their roles.
     */
    private const SELF_SERVICE = [
        'POST /admin/auth/login',
        'POST /admin/auth/verify',
        'POST /admin/auth/logout',
        'GET /admin/me',
        'POST /admin/me/password',
    ];

    #[Test]
    public function every_admin_operation_names_a_known_permission(): void
    {
        $problems = [];

        foreach ($this->adminOperations() as $key => $operation) {
            $permission = $operation['x-permission'] ?? null;

            if (in_array($key, self::SELF_SERVICE, true)) {
                if ($permission !== null) {
                    $problems[] = "{$key}: self-service operations take no x-permission";
                }

                continue;
            }

            foreach ([$permission, ...array_values($operation['x-field-permissions'] ?? [])] as $name) {
                if (! isset(AdminPermissions::ALL[$name])) {
                    $problems[] = "{$key}: permission [{$name}] is not in AdminPermissions::ALL";
                }
            }

            foreach (['401', '403'] as $status) {
                if (! isset($operation['responses'][$status])) {
                    $problems[] = "{$key}: no {$status} response";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    #[Test]
    public function every_permission_guards_at_least_one_operation(): void
    {
        $used = [];

        foreach ($this->adminOperations() as $operation) {
            $used = [...$used, ...array_filter([$operation['x-permission'] ?? null]), ...array_values($operation['x-field-permissions'] ?? [])];
        }

        $this->assertSame([], array_values(array_diff(array_keys(AdminPermissions::ALL), $used)), 'Permissions no operation uses.');
    }

    #[Test]
    public function the_contract_enums_match_the_catalogue(): void
    {
        $schemas = OpenApiContract::spec()['components']['schemas'];

        $this->assertEqualsCanonicalizing(array_keys(AdminPermissions::ALL), $schemas['AdminPermission']['enum']);
        $this->assertEqualsCanonicalizing(array_column(AdminRole::cases(), 'value'), $schemas['AdminRoleName']['enum']);
    }

    #[Test]
    public function every_served_admin_route_checks_its_permission(): void
    {
        $operations = OpenApiContract::operations();
        $problems = [];

        foreach (Route::getRoutes() as $route) {
            $path = OpenApiContract::pathFor($route);

            if ($path === null || ! str_starts_with($path, '/admin/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.$path;
                $permission = $operations[$key]['x-permission'] ?? null;
                $middleware = $route->gatherMiddleware();

                if ($permission !== null && ! in_array('can:'.$permission, $middleware, true)) {
                    $problems[] = "{$key}: route lacks can:{$permission}";
                }

                $checks = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'can:'));

                if ($permission === null && $checks !== []) {
                    $problems[] = "{$key}: route checks a permission the contract does not name";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function adminOperations(): array
    {
        return array_filter(
            OpenApiContract::operations(),
            fn (string $key) => str_contains($key, ' /admin/'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
