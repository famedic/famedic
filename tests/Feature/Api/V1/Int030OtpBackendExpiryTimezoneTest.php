<?php

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Http\Middleware\Api\V1\AuthenticateOtpBackend;
use App\Services\Api\V1\Auth\AuthenticatedBackendClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * INT-030 · OBS-SEC-OTP-BACKEND-EXPIRY-TZ: the backend-bound OTP challenge must expire with the challenge
 * TTL (5 min) regardless of the application timezone, with the database session in UTC as in QA.
 * Synthetic ids only; no SMS, OTP codes, providers or network.
 */

const INT030_CHALLENGE = '30303030-3030-4030-8030-303030303030';

beforeEach(function () {
    $this->int030Tz = [config('app.timezone'), date_default_timezone_get()];
    config()->set('akubica_connectors.otp_enrollment.enabled', true);
    config()->set('akubica_connectors.otp_enrollment.certified', true);
    if (DB::getDriverName() === 'mysql') {
        DB::statement("SET time_zone = '+00:00'");
        // The registry rows behind the foreign keys are irrelevant for the expiry arithmetic under test.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
    }
});

afterEach(function () {
    if (DB::getDriverName() === 'mysql') {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
    Carbon::setTestNow();
    config()->set('app.timezone', $this->int030Tz[0]);
    date_default_timezone_set($this->int030Tz[1]);
});

function int030UseTimezone(string $tz): void
{
    config()->set('app.timezone', $tz);
    date_default_timezone_set($tz);
    Carbon::setTestNow(Carbon::parse('2026-11-27 07:59:31', 'America/Mexico_City'));
}

function int030Middleware(int $credentialMinutes = 30): AuthenticateOtpBackend
{
    $backend = \Mockery::mock(BackendClientAuthenticator::class);
    $backend->shouldReceive('authenticate')->andReturn(new AuthenticatedBackendClient('10', '20', now()->addMinutes($credentialMinutes)->timestamp));

    return new AuthenticateOtpBackend($backend);
}

function int030Request(string $routeName, array $input): Request
{
    $request = Request::create('/api/v1/auth/login/'.(str_ends_with($routeName, 'request-code') ? 'request-code' : 'verify-code'), 'POST', $input);
    $route = new Route('POST', $request->path(), []);
    $route->name($routeName);
    $request->setRouteResolver(static fn () => $route);

    return $request;
}

/** Simulates request-code exactly as AkubicaLoginOtpService answers it: expires_at in UTC ISO-8601 with Z, TTL 5 min. */
function int030RequestCode(AuthenticateOtpBackend $middleware): void
{
    $expires = now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z');
    $response = $middleware->handle(int030Request('api.v1.auth.login.request-code', []), fn () => new JsonResponse(['data' => ['challenge_id' => INT030_CHALLENGE, 'expires_at' => $expires]], 202));
    expect($response->getStatusCode())->toBe(202);
}

function int030Verify(AuthenticateOtpBackend $middleware, ?bool &$reached): \Symfony\Component\HttpFoundation\Response
{
    $reached = false;

    return $middleware->handle(int030Request('api.v1.auth.login.verify-code', ['challenge_id' => INT030_CHALLENGE]), function () use (&$reached) {
        $reached = true;

        return new JsonResponse(['data' => ['otp_verified' => true]], 200);
    });
}

it('stores the backend expiry 5 minutes after creation in the app timezone, not 6 hours later', function (string $tz) {
    int030UseTimezone($tz);
    int030RequestCode(int030Middleware());

    $row = DB::table('otp_backend_challenges')->where('challenge_hash', hash('sha256', INT030_CHALLENGE))->first();
    $expires = Carbon::parse($row->expires_at, config('app.timezone'));
    $created = Carbon::parse($row->created_at, config('app.timezone'));

    expect($expires->getTimestamp())->toBe(now()->addMinutes(5)->getTimestamp())
        ->and($created->diffInSeconds($expires))->toEqual(300)
        ->and((string) $row->expires_at)->toStartWith(now()->addMinutes(5)->setTimezone($tz)->format('Y-m-d H:i:s'));
})->with(['America/Mexico_City', 'UTC']);

it('lets verify-code through before the 5-minute TTL', function (string $tz, int $seconds) {
    int030UseTimezone($tz);
    $middleware = int030Middleware();
    int030RequestCode($middleware);

    Carbon::setTestNow(now()->addSeconds($seconds));
    $response = int030Verify($middleware, $reached);

    expect($response->getStatusCode())->toBe(200)->and($reached)->toBeTrue();
})->with(['America/Mexico_City', 'UTC'])->with(['inmediato' => 1, '4m59s' => 299]);

it('rejects verify-code with 422 CODE_EXPIRED once the 5-minute TTL has passed', function (string $tz, int $seconds) {
    int030UseTimezone($tz);
    $middleware = int030Middleware();
    int030RequestCode($middleware);

    Carbon::setTestNow(now()->addSeconds($seconds));
    $response = int030Verify($middleware, $reached);

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode($response->getContent(), true)['error']['code'])->toBe('CODE_EXPIRED')
        ->and((string) $response->getContent())->not->toContain('token')
        ->and($reached)->toBeFalse();
})->with(['America/Mexico_City', 'UTC'])->with(['5m00s' => 300, '5m01s' => 301, '1h' => 3600, '5h59m' => 21540]);

it('caps the backend expiry at the machine credential expiry', function () {
    int030UseTimezone('America/Mexico_City');
    $middleware = int030Middleware(credentialMinutes: 2);
    int030RequestCode($middleware);

    Carbon::setTestNow(now()->addSeconds(119));
    expect(int030Verify($middleware, $reached)->getStatusCode())->toBe(200);

    Carbon::setTestNow(now()->addSeconds(2));
    expect(int030Verify($middleware, $reached)->getStatusCode())->toBe(422)->and($reached)->toBeFalse();
});

it('fails closed when the challenge expiry cannot be parsed (same behaviour as before INT-030)', function () {
    int030UseTimezone('America/Mexico_City');
    $middleware = int030Middleware();
    $response = $middleware->handle(int030Request('api.v1.auth.login.request-code', []), fn () => new JsonResponse(['data' => ['challenge_id' => INT030_CHALLENGE, 'expires_at' => 'no-es-fecha']], 202));

    // The epoch expiry is rejected by the TIMESTAMP column: no binding is written and the caller gets 503.
    expect($response->getStatusCode())->toBe(503)
        ->and(json_decode($response->getContent(), true)['error']['code'])->toBe('AUTHENTICATION_UNAVAILABLE')
        ->and(DB::table('otp_backend_challenges')->where('challenge_hash', hash('sha256', INT030_CHALLENGE))->exists())->toBeFalse();

    $verify = int030Verify($middleware, $reached);
    expect($verify->getStatusCode())->toBe(403)
        ->and(json_decode($verify->getContent(), true)['error']['code'])->toBe('OTP_BACKEND_CONTEXT_INVALID')
        ->and($reached)->toBeFalse();
});
