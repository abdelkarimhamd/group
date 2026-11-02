<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Contract\OpenApiContract;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a *_test database. RefreshDatabase
     * drops every table, so a stray DB_URL or .env value must fail loudly
     * before any migration runs.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tests must use a *_test database, not [{$database}].");
        }

        return $app;
    }

    /**
     * Every response from an /api/v1 route must match the API contract, so a
     * change to the API that the contract does not describe fails the build.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        OpenApiContract::assertResponseMatches($response);

        return $response;
    }
}
