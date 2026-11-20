<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api\V1\Identity\LocalMachineCredentialSource;
use Tests\Support\Database\TestingDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('FAMEDIC_SYNTHETIC_MYSQL') === '1') {
            config()->set('benefits.synthetic_mysql', true);
            config()->set('database.default', 'mysql');
            config()->set('database.connections.mysql.database', 'benefits_synthetic_testing');
            config()->set('database.connections.mysql.unix_socket', '/phase-socket/mysql.sock');
            config()->set('database.connections.mysql.host', '127.0.0.1');
            DB::purge('mysql');
            $this->app->bind(\App\Contracts\Api\V1\Auth\BackendCredentialSource::class, LocalMachineCredentialSource::class);
        }

        if (getenv('FAMEDIC_RC2_LOCAL_MYSQL') === '1') {
            $this->app->bind(\App\Contracts\Api\V1\Auth\BackendCredentialSource::class, LocalMachineCredentialSource::class);
        }

        TestingDatabaseGuard::assertSafeTestingDatabase();

        $this->withoutMiddleware([
            'password.confirm',
            \App\Http\Middleware\ExcludePasswordConfirm::class,
            \Illuminate\Auth\Middleware\RequirePassword::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    /**
     * Clear sticky auth guards and bind a new Sanctum Bearer for the next HTTP call.
     *
     * Needed when a single Pest/PHPUnit case issues requests as different API users;
     * without this, Auth may keep resolving the first token's user.
     *
     * @return array{Authorization: string}
     */
    public function switchApiBearerToken(string $token): array
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        $headers = ['Authorization' => 'Bearer '.$token] + (getenv('FAMEDIC_REGISTERED_NATIVE_FIXTURES') === '1'
            ? \Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture::headers($token) : []);
        $this->withHeaders($headers);

        return $headers;
    }

    /**
     * RC2 IT-0041 (OBS-ORDEN-UAT-RESET): a test that touched the database without a wrapping transaction
     * (the *Isolated suites: connectionsToTransact() === [] and RefreshDatabaseState::$migrated forced to true,
     * or classes without RefreshDatabase/DatabaseTransactions) commits its rows. Mark the schema as no longer
     * fresh, so the next RefreshDatabase test re-runs migrate:fresh and starts from the clean baseline that
     * RefreshDatabase promises instead of inheriting those rows. Does not touch data itself and does not
     * relax any assertion; transactional tests are unaffected.
     */
    protected function tearDown(): void
    {
        if (isset($this->app) && ! $this->runsInsideWrappingTransaction()
            && $this->app->resolved('db') && $this->app->make('db')->getConnections() !== []) {
            RefreshDatabaseState::$migrated = false;
        }

        parent::tearDown();
    }

    private function runsInsideWrappingTransaction(): bool
    {
        $traits = class_uses_recursive(static::class);
        if (! isset($traits[RefreshDatabase::class]) && ! isset($traits[DatabaseTransactions::class])) {
            return false;
        }

        return ! method_exists($this, 'connectionsToTransact') || $this->connectionsToTransact() !== [];
    }
}
