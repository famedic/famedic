<?php

use Tests\Support\Atix\AtixSandboxRequestBuilder;

const RC1_STAGING_OTP_CLI_NO_MAIN = true;

require_once __DIR__.'/../../../scripts/rc1-staging-request-otp.php';

function rc1_staging_otp_script_with_env(array $overrides = []): void
{
    $defaults = [
        'BASE_URL' => 'https://staging.famedic.com.mx/api/v1',
        'ATIX_SANDBOX_INTEGRATION_KEY_ID' => 'sandbox-test',
        'ATIX_SANDBOX_HMAC_SECRET' => 'sandbox-secret-for-tests',
        'RC1_STAGING_PHONE' => '+5255512345678',
    ];
    foreach (array_merge($defaults, $overrides) as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function rc1_staging_otp_clear_env(): void
{
    foreach (rc1_staging_otp_required_env_names() as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
    unset($GLOBALS['rc1_staging_otp_http_post_hook']);
}

beforeEach(function () {
    rc1_staging_otp_clear_env();
});

afterEach(function () {
    rc1_staging_otp_clear_env();
});

test('aborta cuando falta una variable de entorno obligatoria', function () {
    rc1_staging_otp_script_with_env(['RC1_STAGING_PHONE' => '']);
    expect(fn () => rc1_staging_otp_load_config_from_env())
        ->toThrow(RuntimeException::class, 'RC1_STAGING_PHONE');
});

test('bloquea hosts de produccion explicitos', function () {
    rc1_staging_otp_script_with_env(['BASE_URL' => 'https://famedic.com.mx/api/v1']);
    expect(fn () => rc1_staging_otp_load_config_from_env())
        ->toThrow(RuntimeException::class, 'produccion');

    rc1_staging_otp_script_with_env(['BASE_URL' => 'https://www.famedic.com.mx/api/v1']);
    expect(fn () => rc1_staging_otp_load_config_from_env())
        ->toThrow(RuntimeException::class, 'produccion');
});

test('rechaza telefono que no es E164', function () {
    rc1_staging_otp_script_with_env(['RC1_STAGING_PHONE' => '5551234567']);
    expect(fn () => rc1_staging_otp_load_config_from_env())
        ->toThrow(RuntimeException::class, 'E.164');
});

test('genera UUID distintos en cada ejecucion', function () {
    $first = rc1_staging_otp_fresh_run_ids();
    $second = rc1_staging_otp_fresh_run_ids();

    expect($first['conversation_id'])->not->toBe($second['conversation_id'])
        ->and($first['nonce'])->not->toBe($second['nonce'])
        ->and($first['lookup_idempotency_key'])->not->toBe($second['lookup_idempotency_key']);
});

test('firma ATIX coincide con AtixSandboxRequestBuilder', function () {
    $payload = [
        'phone' => '+5255512345678',
        'conversation_id' => '11111111-1111-4111-8111-111111111111',
        'channel_event_id' => '22222222-2222-4222-8222-222222222222',
        'channel' => 'whatsapp',
        'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ];
    $timestamp = '1760000000';
    $nonce = 'nonce-test-12345678';
    $secret = 'sandbox-secret';

    $body = rc1_staging_otp_json_body($payload);
    $scriptSig = rc1_staging_otp_atix_signature($body, $timestamp, $nonce, $secret);
    $laravelHeaders = AtixSandboxRequestBuilder::headers(
        $payload,
        $secret,
        'sandbox-test',
        $nonce,
        'idem-test',
        $timestamp,
        'corr-test',
    );

    expect($scriptSig)->toBe($laravelHeaders['X-Signature']);
});

test('dry-run no ejecuta HTTP y no imprime el secreto ATIX', function () {
    rc1_staging_otp_script_with_env();
    $config = rc1_staging_otp_load_config_from_env();
    $ids = rc1_staging_otp_fresh_run_ids();

    ob_start();
    $exit = rc1_staging_otp_run(true, $config, $ids);
    $output = ob_get_clean();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('sandbox-secret-for-tests')
        ->and($output)->toContain('dry-run OK');
});

test('flujo HTTP mock: lookup 202 y request-code 202 sin filtrar OTP', function () {
    rc1_staging_otp_script_with_env();
    $config = rc1_staging_otp_load_config_from_env();
    $ids = rc1_staging_otp_fresh_run_ids();

    $GLOBALS['rc1_staging_otp_http_post_hook'] = function (string $url, string $body, array $headers): array {
        if (str_contains($url, 'phone-lookups')) {
            return [
                'status' => 202,
                'body' => json_encode([
                    'success' => true,
                    'data' => [
                        'recognition_status' => 'recognized_unverified',
                        'assurance_level' => 'recognized_unverified',
                        'personalization' => ['allowed' => true, 'first_name' => 'Ada'],
                    ],
                ], JSON_THROW_ON_ERROR),
                'headers' => [],
            ];
        }

        expect($body)->not->toContain('654321');

        return [
            'status' => 202,
            'body' => json_encode([
                'success' => true,
                'data' => [
                    'challenge_id' => '33333333-3333-4333-8333-333333333333',
                    'expires_at' => '2026-10-21T12:05:00Z',
                    'channel' => 'sms',
                    'destination_masked' => '***5678',
                ],
            ], JSON_THROW_ON_ERROR),
            'headers' => [],
        ];
    };

    ob_start();
    $exit = rc1_staging_otp_run(false, $config, $ids);
    $output = ob_get_clean();

    expect($exit)->toBe(0)
        ->and($output)->toContain('http_status: 202')
        ->and($output)->toContain('33333333-3333-4333-8333-333333333333')
        ->and($output)->not->toContain('654321')
        ->and($output)->not->toContain('sandbox-secret-for-tests')
        ->and($output)->toContain('otp-movements-monitor');
});

test('flujo HTTP mock: lookup decoy unknown advierte challenge sintetico', function () {
    rc1_staging_otp_script_with_env();
    $config = rc1_staging_otp_load_config_from_env();
    $ids = rc1_staging_otp_fresh_run_ids();

    $GLOBALS['rc1_staging_otp_http_post_hook'] = function (string $url): array {
        if (str_contains($url, 'phone-lookups')) {
            return [
                'status' => 202,
                'body' => json_encode([
                    'success' => true,
                    'data' => [
                        'recognition_status' => 'unknown',
                        'assurance_level' => 'none',
                        'personalization' => ['allowed' => false],
                    ],
                ], JSON_THROW_ON_ERROR),
                'headers' => [],
            ];
        }

        return [
            'status' => 202,
            'body' => json_encode([
                'success' => true,
                'data' => [
                    'challenge_id' => 'decoy-challenge-id',
                    'expires_at' => '2026-10-21T12:05:00Z',
                    'channel' => 'sms',
                    'destination_masked' => '***9999',
                ],
            ], JSON_THROW_ON_ERROR),
            'headers' => [],
        ];
    };

    ob_start();
    $exit = rc1_staging_otp_run(false, $config, $ids);
    $output = ob_get_clean();

    expect($exit)->toBe(0)
        ->and($output)->toContain('decoy')
        ->and($output)->toContain('recognition_status: unknown');
});

test('flujo HTTP mock: lookup 409 replay termina con error', function () {
    rc1_staging_otp_script_with_env();
    $config = rc1_staging_otp_load_config_from_env();
    $ids = rc1_staging_otp_fresh_run_ids();

    $GLOBALS['rc1_staging_otp_http_post_hook'] = fn (): array => [
        'status' => 409,
        'body' => json_encode([
            'success' => false,
            'error' => ['code' => 'ATIX_NONCE_REPLAY', 'message' => 'Nonce repetido.'],
        ], JSON_THROW_ON_ERROR),
        'headers' => [],
    ];

    ob_start();
    $exit = rc1_staging_otp_run(false, $config, $ids);
    $output = ob_get_clean();

    expect($exit)->toBe(1)
        ->and($output)->toContain('409');
});

test('subproceso dry-run sale 0 y stdout sin secreto', function () {
    $root = dirname(__DIR__, 3);
    $env = [
        'BASE_URL=https://staging.famedic.com.mx/api/v1',
        'ATIX_SANDBOX_INTEGRATION_KEY_ID=sandbox-test',
        'ATIX_SANDBOX_HMAC_SECRET=super-secret-never-print',
        'RC1_STAGING_PHONE=+5255512345678',
    ];
    $cmd = 'cd '.escapeshellarg($root).' && '.implode(' ', $env).' php scripts/rc1-staging-request-otp.php --dry-run 2>&1';
    $output = shell_exec($cmd);

    expect($output)->not->toBeNull()
        ->and($output)->toContain('dry-run OK')
        ->and($output)->not->toContain('super-secret-never-print');
});
