<?php

namespace Tests\Contract;

use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Yaml\Yaml;

/**
 * The API contract in openapi/v1.yaml, as the test suite sees it.
 *
 * Every response a feature test receives from an /api/v1 route is checked
 * against it (see Tests\TestCase::call), and RouteCoverageTest checks that
 * the routes and the contract list the same live operations.
 */
final class OpenApiContract
{
    public const SPEC_PATH = __DIR__.'/../../openapi/v1.yaml';

    public const ROUTE_PREFIX = 'api/v1';

    public const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    private static ?ValidatorInterface $validator = null;

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    public static function validator(): ValidatorInterface
    {
        return self::$validator ??= ValidatorBuilder::fromYamlFile(self::SPEC_PATH)->getValidator();
    }

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return self::$spec ??= Yaml::parseFile(self::SPEC_PATH);
    }

    /**
     * Every operation in the contract, keyed like "GET /campaigns/{campaign_id}".
     *
     * @return array<string, array<string, mixed>>
     */
    public static function operations(): array
    {
        $operations = [];

        foreach (self::spec()['paths'] as $path => $item) {
            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    $operations[strtoupper($method).' '.$path] = $item[$method];
                }
            }
        }

        return $operations;
    }

    /**
     * The contract path for a Laravel route, or null when the route is not
     * part of the versioned API. Parameter names may differ (a route can use
     * `{campaign}` for model binding where the contract says `{campaign_id}`),
     * so paths are matched by shape. An unmatched route keeps its own path,
     * which the coverage test then reports as missing.
     */
    public static function pathFor(Route $route): ?string
    {
        $uri = $route->uri();

        if (! str_starts_with($uri, self::ROUTE_PREFIX.'/')) {
            return null;
        }

        $path = '/'.str_replace('?}', '}', substr($uri, strlen(self::ROUTE_PREFIX) + 1));

        foreach (array_keys(self::spec()['paths']) as $contractPath) {
            if (self::shape($contractPath) === self::shape($path)) {
                return $contractPath;
            }
        }

        return $path;
    }

    private static function shape(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }

    /**
     * Fail the test when an API response does not match the contract.
     * Requests that matched no route (a 404 for an unknown path) are skipped.
     */
    public static function assertResponseMatches(TestResponse $response): void
    {
        $route = $response->baseRequest?->route();

        if (! $route instanceof Route || ($path = self::pathFor($route)) === null) {
            return;
        }

        $method = strtolower($response->baseRequest->getMethod());

        try {
            self::validator()->validate($response->baseResponse, $path, $method);
        } catch (ValidationException $e) {
            throw new AssertionFailedError(sprintf(
                "The %d response to %s %s does not match openapi/v1.yaml: %s\n\nBody: %s",
                $response->getStatusCode(),
                strtoupper($method),
                $path,
                $e->getMessage(),
                substr((string) $response->getContent(), 0, 2000),
            ));
        }
    }
}
