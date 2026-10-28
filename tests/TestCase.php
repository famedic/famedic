<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    private static bool $mysqlDatabaseLockAcquired = false;

    public function createApplication(): Application
    {
        $this->forceTestingEnvironmentVariables();

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->loadEnvironmentFrom('.env.testing');

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $app->instance('env', 'testing');
        $connection = env('TEST_DB_CONNECTION', env('DB_CONNECTION', 'mysql'));
        $database = env('TEST_DB_DATABASE', env('DB_DATABASE', 'famedic_test_description_ia'));

        $mysqlIsolated = $connection === 'mysql'
            && preg_match('/^famedic_test_description_ia_[0-9]{14}_[0-9]+_[0-9]+$/', (string) $database)
            && getenv('FAMEDIC_ISOLATED_TEST_RUN') === '1';
        $sqliteIsolated = $connection === 'sqlite'
            && preg_match('#^/var/www/html/storage/framework/testing/famedic_test_[0-9]{14}_[0-9]+_[0-9]+\.sqlite$#', (string) $database)
            && getenv('FAMEDIC_ISOLATED_TEST_RUN') === 'sqlite-memory';

        if (! $mysqlIsolated && ! $sqliteIsolated) {
            throw new \RuntimeException('Tests must use an approved isolated runner and disposable database.');
        }

        config([
            'app.env' => 'testing',
            'database.default' => $connection,
            'database.connections.mysql.host' => env('TEST_DB_HOST', env('DB_HOST', 'mysql')),
            'database.connections.mysql.database' => $database,
            'database.connections.mysql.username' => env('DB_USERNAME', 'famedic'),
            'database.connections.mysql.password' => env('DB_PASSWORD', 'famedic'),
            'database.connections.sqlite.database' => $connection === 'sqlite' ? $database : config('database.connections.sqlite.database'),
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

        $actualDatabase = \Illuminate\Support\Facades\DB::connection($connection)->getDatabaseName();
        if ($actualDatabase !== $database) {
            throw new \RuntimeException("Refusing test connection to unexpected database [{$actualDatabase}].");
        }

        return $app;
    }

    protected function forceTestingEnvironmentVariables(): void
    {
        $overrides = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => getenv('TEST_DB_CONNECTION') ?: getenv('DB_CONNECTION') ?: 'mysql',
            'DB_HOST' => getenv('TEST_DB_HOST') ?: getenv('DB_HOST') ?: 'mysql',
            'DB_DATABASE' => getenv('TEST_DB_DATABASE') ?: getenv('DB_DATABASE') ?: 'famedic_test_description_ia',
            'TEST_DB_CONNECTION' => getenv('TEST_DB_CONNECTION') ?: getenv('DB_CONNECTION') ?: 'mysql',
            'TEST_DB_DATABASE' => getenv('TEST_DB_DATABASE') ?: getenv('DB_DATABASE') ?: 'famedic_test_description_ia',
            'DB_USERNAME' => getenv('DB_USERNAME') ?: 'famedic',
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'famedic',
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

        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $this->assertTestingDatabaseIsSafe();
        $this->acquireTestingDatabaseLock();

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

    protected function tearDown(): void
    {
        try {
            if ($this->app && ! Schema::hasTable('migrations')) {
                RefreshDatabaseState::$migrated = false;
            }
        } catch (\Throwable) {
            RefreshDatabaseState::$migrated = false;
        }

        parent::tearDown();
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

        if ($connection === 'mysql' && ! str_starts_with($databaseLower, 'famedic_test_description_ia_')) {
            $this->fail("Refusing to run tests against non-disposable MySQL database [{$database}].");
        }
    }

    protected function acquireTestingDatabaseLock(): void
    {
        $connection = (string) config('database.default');

        if (self::$mysqlDatabaseLockAcquired || $connection !== 'mysql') {
            return;
        }

        $database = (string) config('database.connections.mysql.database');
        $lockName = 'famedic-test-db:'.substr(md5($database), 0, 32);
        $result = \Illuminate\Support\Facades\DB::selectOne('SELECT GET_LOCK(?, 0) as acquired', [$lockName]);

        if ((int) ($result->acquired ?? 0) !== 1) {
            $this->fail("Testing database [{$database}] is already locked by another run.");
        }

        self::$mysqlDatabaseLockAcquired = true;

        register_shutdown_function(static function () use ($lockName): void {
            try {
                \Illuminate\Support\Facades\DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {
                //
            }
        });
    }
}
