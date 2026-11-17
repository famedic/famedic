<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Http\Middleware\Api\V1\AuthenticateOtpBackend;
use App\Services\Api\V1\Auth\AuthenticatedBackendClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RC2 A11 (anexo): verify-code con challenge vencido -> 422 sin Bearer.
 * Defecto LOCAL-FIRST: el vinculo de backend vencido respondia 403 OTP_BACKEND_CONTEXT_INVALID.
 * El aislamiento por consumidor/canal se conserva (ajeno o inexistente -> 403).
 */
final class Rc2A11ExpiredChallengeTest extends TestCase
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

    public function test_own_expired_challenge_is_422_code_expired_without_reaching_verify(): void
    {
        $this->bind('33333333-3333-4333-8333-333333333333', 10, 20, now()->subSecond());
        $reached = false;
        $response = $this->middleware('10', '20')->handle(
            $this->request(['challenge_id' => '33333333-3333-4333-8333-333333333333']),
            function () use (&$reached) { $reached = true; return new JsonResponse(['data' => ['token' => 'x']], 200); },
        );
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('CODE_EXPIRED', json_decode($response->getContent(), true)['error']['code']);
        $this->assertStringNotContainsString('token', (string) $response->getContent());
        $this->assertFalse($reached);
    }

    public function test_foreign_expired_challenge_stays_403(): void
    {
        $this->bind('44444444-4444-4444-8444-444444444444', 10, 20, now()->subMinute());
        $response = $this->middleware('99', '100')->handle(
            $this->request(['challenge_id' => '44444444-4444-4444-8444-444444444444']),
            fn () => new JsonResponse(['data' => []], 200),
        );
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('OTP_BACKEND_CONTEXT_INVALID', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_unknown_challenge_stays_403(): void
    {
        $response = $this->middleware('10', '20')->handle(
            $this->request(['challenge_id' => '55555555-5555-4555-8555-555555555555']),
            fn () => new JsonResponse(['data' => []], 200),
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    private function bind(string $challenge, int $consumer, int $channel, $expiresAt): void
    {
        DB::table('otp_backend_challenges')->insert(['challenge_hash' => hash('sha256', $challenge), 'consumer_id' => $consumer, 'channel_id' => $channel, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function middleware(string $consumer, string $channel): AuthenticateOtpBackend
    {
        $backend = \Mockery::mock(BackendClientAuthenticator::class);
        $backend->shouldReceive('authenticate')->once()->andReturn(new AuthenticatedBackendClient($consumer, $channel, now()->addMinutes(5)->timestamp));

        return new AuthenticateOtpBackend($backend);
    }

    private function request(array $input): Request
    {
        $request = Request::create('/api/v1/auth/login/verify-code', 'POST', $input);
        $route = new Route('POST', '/api/v1/auth/login/verify-code', []);
        $route->name('api.v1.auth.login.verify-code');
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }
}
