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

        // INT-031 (harness, RC04): tests never build front-end assets; render @vite tags without public/build/manifest.json.
        $this->withoutVite();

        $this->withoutMiddleware([
            'password.confirm',
            \App\Http\Middleware\ExcludePasswordConfirm::class,
            \Illuminate\Auth\Middleware\RequirePassword::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    /** INT-031 (harness only): see enableDefaultOtpBackend(). */
    private bool $defaultOtpBackend = false;

    /**
     * INT-031 (harness only, opt-in per legacy test file): enroll one registered OTP backend actor
     * (OtpEnrollmentFixture::anyCustomer) and attach its machine credential to /api/v1 requests that do not carry
     * one. It authenticates the backend client only; a Bearer bound to another consumer/channel is still rejected
     * by the application, and requests without a Bearer stay unauthenticated where a Bearer is required.
     */
    public function enableDefaultOtpBackend(string $kind = 'native'): void
    {
        if (getenv('FAMEDIC_REGISTERED_NATIVE_FIXTURES') !== '1') {
            return;
        }
        \Tests\Support\Api\V1\Identity\OtpEnrollmentFixture::anyCustomer($kind, \Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture::capabilitiesFor($kind, akubicaApiV1RouteNames()));
        $this->defaultOtpBackend = true;
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->defaultOtpBackend && ! isset($server['HTTP_X_SYNTHETIC_MACHINE_CREDENTIAL']) && str_contains((string) $uri, '/api/v1/')
            && ($machine = \Tests\Support\Api\V1\Identity\OtpEnrollmentFixture::machine()) !== null) {
            $server['HTTP_X_SYNTHETIC_MACHINE_CREDENTIAL'] = $machine;
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
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
        // INT-031 (RC17): a test may swap the DB facade for a Mockery double (TestingDatabaseGuardTest); it has no real
        // connections to inspect, so it cannot have committed rows.
        if (isset($this->app) && ! $this->runsInsideWrappingTransaction()
            && $this->app->resolved('db') && ! $this->app->make('db') instanceof \Mockery\MockInterface
            && $this->app->make('db')->getConnections() !== []) {
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
