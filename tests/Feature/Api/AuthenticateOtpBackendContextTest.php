<?php

namespace Tests\Feature\Api;

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Http\Middleware\Api\V1\AuthenticateOtpBackend;
use App\Services\Api\V1\Auth\AuthenticatedBackendClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class AuthenticateOtpBackendContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('akubica_connectors.otp_enrollment.enabled', true);
        config()->set('akubica_connectors.otp_enrollment.certified', true);

        Schema::create('otp_backend_challenges', static function (Blueprint $table): void {
            $table->id();
            $table->string('challenge_hash', 64)->unique();
            $table->unsignedBigInteger('consumer_id');
            $table->unsignedBigInteger('channel_id');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function test_request_and_verify_share_the_same_backend_context(): void
    {
        $backend = \Mockery::mock(BackendClientAuthenticator::class);
        $backend->shouldReceive('authenticate')->twice()->andReturn(new AuthenticatedBackendClient('10', '20', now()->addMinutes(5)->timestamp));
        $middleware = new AuthenticateOtpBackend($backend);
        $challenge = '11111111-1111-4111-8111-111111111111';
        $expires = now()->addMinutes(4)->toIso8601String();

        $request = $this->request('api.v1.auth.login.request-code', []);
        $response = $middleware->handle($request, fn () => new JsonResponse(['data' => ['challenge_id' => $challenge, 'expires_at' => $expires]], 202));
        $this->assertSame(202, $response->getStatusCode());

        $verify = $this->request('api.v1.auth.login.verify-code', ['challenge_id' => $challenge]);
        $response = $middleware->handle($verify, fn () => new JsonResponse(['data' => ['otp_verified' => true]], 200));
        $this->assertSame(200, $response->getStatusCode());

        $this->assertDatabaseHas('otp_backend_challenges', [
            'challenge_hash' => hash('sha256', $challenge),
            'consumer_id' => 10,
            'channel_id' => 20,
        ]);
    }

    public function test_verify_rejects_cross_bound_backend_context(): void
    {
        $backend = \Mockery::mock(BackendClientAuthenticator::class);
        $backend->shouldReceive('authenticate')->once()->andReturn(new AuthenticatedBackendClient('99', '100', now()->addMinutes(5)->timestamp));
        $middleware = new AuthenticateOtpBackend($backend);
        $challenge = '22222222-2222-4222-8222-222222222222';

        \DB::table('otp_backend_challenges')->insert([
            'challenge_hash' => hash('sha256', $challenge),
            'consumer_id' => 10,
            'channel_id' => 20,
            'expires_at' => now()->addMinutes(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $middleware->handle(
            $this->request('api.v1.auth.login.verify-code', ['challenge_id' => $challenge]),
            fn () => new JsonResponse(['data' => ['otp_verified' => true]], 200),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    private function request(string $routeName, array $input): Request
    {
        $request = Request::create('/api/v1/auth/login/verify-code', 'POST', $input);
        $route = new Route('POST', '/api/v1/auth/login/verify-code', []);
        $route->name($routeName);
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }
}
