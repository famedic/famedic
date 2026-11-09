<?php

namespace Tests;

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
}
