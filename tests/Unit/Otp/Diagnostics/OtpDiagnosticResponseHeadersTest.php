<?php

use App\Http\Middleware\Api\V1\AddOtpDiagnosticResponseHeaders;
use App\Services\Otp\Diagnostics\OtpDiagnosticContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

function otpDiagnosticMiddlewareForTest(?OtpDiagnosticContext $context = null): AddOtpDiagnosticResponseHeaders
{
    return new AddOtpDiagnosticResponseHeaders($context ?? app(OtpDiagnosticContext::class));
}

function otpDiagnosticRequestForTest(array $headers = []): Request
{
    return Request::create('/api/v1/auth/register', 'POST', [], [], [], collect($headers)
        ->mapWithKeys(fn (string $value, string $key): array => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])
        ->all());
}

function otpDiagnosticJsonResponseForTest(int $status = 202, array $body = ['success' => true]): Response
{
    return new Response(json_encode($body, JSON_THROW_ON_ERROR), $status, [
        'Content-Type' => 'application/json',
    ]);
}

beforeEach(function () {
    config()->set('otp.diagnostic_response_headers.enabled', false);
    config()->set('otp.diagnostic_response_headers.token', '');
});

test('production never exposes otp diagnostic headers even with flag and valid token', function () {
    $this->app->detectEnvironment(fn () => 'production');
    $token = str_repeat('a', 64);
    config()->set('otp.diagnostic_response_headers.enabled', true);
    config()->set('otp.diagnostic_response_headers.token', $token);

    $response = otpDiagnosticMiddlewareForTest()->handle(
        otpDiagnosticRequestForTest([
            'X-OTP-Diagnostic' => 'true',
            'X-OTP-Diagnostic-Token' => $token,
        ]),
        function () {
            app(OtpDiagnosticContext::class)->markDeliveryAccepted();

            return otpDiagnosticJsonResponseForTest();
        },
    );

    expect($response->headers->has(OtpDiagnosticContext::HEADER_OUTCOME))->toBeFalse();
});

test('missing invalid token and disabled flag do not expose otp diagnostic headers', function (bool $enabled, ?string $providedToken) {
    $this->app->detectEnvironment(fn () => 'local');
    config()->set('otp.diagnostic_response_headers.enabled', $enabled);
    config()->set('otp.diagnostic_response_headers.token', str_repeat('b', 64));

    $headers = ['X-OTP-Diagnostic' => 'true'];
    if ($providedToken !== null) {
        $headers['X-OTP-Diagnostic-Token'] = $providedToken;
    }

    $response = otpDiagnosticMiddlewareForTest()->handle(
        otpDiagnosticRequestForTest($headers),
        fn () => otpDiagnosticJsonResponseForTest(),
    );

    expect($response->headers->has(OtpDiagnosticContext::HEADER_OUTCOME))->toBeFalse();
})->with([
    'flag off valid token' => [false, str_repeat('b', 64)],
    'token absent' => [true, null],
    'token invalid' => [true, str_repeat('c', 64)],
]);

test('local valid diagnostic request exposes only closed vocabulary and preserves body and status', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $token = str_repeat('d', 64);
    $body = ['success' => true, 'data' => ['requires_otp' => true]];
    config()->set('otp.diagnostic_response_headers.enabled', true);
    config()->set('otp.diagnostic_response_headers.token', $token);

    $response = otpDiagnosticMiddlewareForTest()->handle(
        otpDiagnosticRequestForTest([
            'X-OTP-Diagnostic' => 'true',
            'X-OTP-Diagnostic-Token' => $token,
        ]),
        function () use ($body) {
            app(OtpDiagnosticContext::class)->markDeliveryAccepted();

            return otpDiagnosticJsonResponseForTest(202, $body);
        },
    );

    expect($response->getStatusCode())->toBe(202)
        ->and(json_decode((string) $response->getContent(), true))->toBe($body)
        ->and($response->headers->get(OtpDiagnosticContext::HEADER_OUTCOME))->toBe('delivery_accepted')
        ->and($response->headers->get(OtpDiagnosticContext::HEADER_STAGE))->toBe('provider_accepted')
        ->and($response->headers->get(OtpDiagnosticContext::HEADER_PROVIDER_RESULT))->toBe('accepted')
        ->and($response->headers->get(OtpDiagnosticContext::HEADER_DELIVERY_OPERATION))->toBe('created')
        ->and($response->headers->get(OtpDiagnosticContext::HEADER_TRACE_COMPLETENESS))->toBe('complete')
        ->and(strtolower((string) $response->headers->get('Cache-Control')))->toContain('no-store');
});

test('local diagnostic headers expose closed decoy reason when present', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $token = str_repeat('g', 64);
    config()->set('otp.diagnostic_response_headers.enabled', true);
    config()->set('otp.diagnostic_response_headers.token', $token);

    $response = otpDiagnosticMiddlewareForTest()->handle(
        otpDiagnosticRequestForTest([
            'X-OTP-Diagnostic' => 'true',
            'X-OTP-Diagnostic-Token' => $token,
        ]),
        function () {
            app(OtpDiagnosticContext::class)->markDecoy('phone_not_verified');

            return otpDiagnosticJsonResponseForTest(202);
        },
    );

    expect($response->headers->get(OtpDiagnosticContext::HEADER_OUTCOME))->toBe('decoy')
        ->and($response->headers->get(OtpDiagnosticContext::HEADER_REASON))->toBe('phone_not_verified');
});

test('invalid diagnostic reason is not exposed', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $token = str_repeat('h', 64);
    config()->set('otp.diagnostic_response_headers.enabled', true);
    config()->set('otp.diagnostic_response_headers.token', $token);

    $response = otpDiagnosticMiddlewareForTest()->handle(
        otpDiagnosticRequestForTest([
            'X-OTP-Diagnostic' => 'true',
            'X-OTP-Diagnostic-Token' => $token,
        ]),
        function () {
            app(OtpDiagnosticContext::class)->set(reason: 'raw pii reason');

            return otpDiagnosticJsonResponseForTest(202);
        },
    );

    expect($response->headers->has(OtpDiagnosticContext::HEADER_REASON))->toBeFalse();
});

test('otp diagnostic token is not logged by middleware', function () {
    $this->app->detectEnvironment(fn () => 'staging');
    $token = str_repeat('e', 64);
    config()->set('otp.diagnostic_response_headers.enabled', true);
    config()->set('otp.diagnostic_response_headers.token', $token);
    Log::spy();

    otpDiagnosticMiddlewareForTest()->handle(
        otpDiagnosticRequestForTest([
            'X-OTP-Diagnostic' => 'true',
            'X-OTP-Diagnostic-Token' => $token,
        ]),
        function () {
            app(OtpDiagnosticContext::class)->markReplay();

            return otpDiagnosticJsonResponseForTest(202);
        },
    );

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

test('otp diagnostic headers preserve idempotency replay header and execute next once', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $token = str_repeat('f', 64);
    $calls = 0;
    config()->set('otp.diagnostic_response_headers.enabled', true);
    config()->set('otp.diagnostic_response_headers.token', $token);

    $response = otpDiagnosticMiddlewareForTest()->handle(
        otpDiagnosticRequestForTest([
            'X-OTP-Diagnostic' => 'true',
            'X-OTP-Diagnostic-Token' => $token,
        ]),
        function () use (&$calls) {
            $calls++;
            app(OtpDiagnosticContext::class)->markReplay();

            $response = otpDiagnosticJsonResponseForTest(202);
            $response->headers->set('Idempotency-Replayed', 'true');

            return $response;
        },
    );

    expect($calls)->toBe(1)
        ->and($response->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($response->headers->get(OtpDiagnosticContext::HEADER_OUTCOME))->toBe('replay')
        ->and($response->headers->get(OtpDiagnosticContext::HEADER_PROVIDER_RESULT))->toBe('not_called');
});
