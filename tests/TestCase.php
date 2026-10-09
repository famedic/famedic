<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $this->forceTestingEnvironmentVariables();

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->loadEnvironmentFrom('.env.testing');

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $app->instance('env', 'testing');
        config([
            'app.env' => 'testing',
            'database.default' => 'mysql',
            'database.connections.mysql.host' => env('DB_HOST', 'mysql'),
            'database.connections.mysql.database' => env('DB_DATABASE', 'famedic_test'),
            'database.connections.mysql.username' => env('DB_USERNAME', 'famedic'),
            'database.connections.mysql.password' => env('DB_PASSWORD', 'famedic'),
            'session.driver' => 'array',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'services.activecampaign.cart_site_events_enabled' => false,
            'services.activecampaign.cart_tag_remove_enabled' => false,
            'scout.driver' => 'collection',
            'scout.queue' => false,
            'services.laboratory_preparation.deterministic_v3_enabled' => false,
            'services.laboratory_preparation.deterministic_v3_shadow_enabled' => false,
        ]);

        return $app;
    }

    protected function forceTestingEnvironmentVariables(): void
    {
        $overrides = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_DATABASE' => 'famedic_test',
            'DB_USERNAME' => 'famedic',
            'DB_PASSWORD' => 'famedic',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'SCOUT_DRIVER' => 'null',
            'LAB_PREPARATION_DETERMINISTIC_V3_ENABLED' => 'false',
            'LAB_PREPARATION_DETERMINISTIC_V3_SHADOW_ENABLED' => 'false',
        ];

        foreach ($overrides as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestingDatabaseIsSafe();

        config([
            'services.laboratory_preparation.deterministic_v3_enabled' => filter_var(
                env('LAB_PREPARATION_DETERMINISTIC_V3_ENABLED', false),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'services.laboratory_preparation.deterministic_v3_shadow_enabled' => filter_var(
                env('LAB_PREPARATION_DETERMINISTIC_V3_SHADOW_ENABLED', false),
                FILTER_VALIDATE_BOOLEAN,
            ),
        ]);

        $this->withoutMiddleware([
            'password.confirm',
            \App\Http\Middleware\ExcludePasswordConfirm::class,
            \Illuminate\Auth\Middleware\RequirePassword::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    protected function assertTestingDatabaseIsSafe(): void
    {
        if (! app()->environment('testing')) {
            $this->fail('Los tests deben ejecutarse con APP_ENV=testing.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $databaseLower = strtolower($database);

        $blockedNames = [
            'famedic_jun_23',
            'famedic_old',
            'production',
        ];

        foreach ($blockedNames as $blockedName) {
            if ($databaseLower === $blockedName || str_contains($databaseLower, $blockedName)) {
                $this->fail("Refusing to run tests against protected database [{$database}] on connection [{$connection}].");
            }
        }

        if ($connection === 'mysql' && ! str_contains($databaseLower, 'test')) {
            $this->fail("Refusing to run tests against MySQL database [{$database}] without a test/testing suffix.");
        }
    }
}
