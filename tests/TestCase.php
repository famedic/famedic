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

        return $app;
    }

    protected function forceTestingEnvironmentVariables(): void
    {
        $overrides = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => 'test_db.sqlite',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'SCOUT_DRIVER' => 'null',
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
