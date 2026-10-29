<?php

use Illuminate\Support\Str;
use Tests\Support\Atix\AtixSandboxRequestBuilder;

function rc1_postman_collection_path(): string
{
    return base_path('postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json');
}

function rc1_postman_collection(): array
{
    $json = file_get_contents(rc1_postman_collection_path());

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<array{name: string, prerequest: string, tests: string}> */
function rc1_phase_a_request_scripts(): array
{
    $collection = rc1_postman_collection();
    $phaseA = collect($collection['item'] ?? [])->firstWhere('name', '00 - RC1 Fase A (staging OTP)');
    expect($phaseA)->not->toBeNull();

    $out = [];
    foreach ($phaseA['item'] ?? [] as $item) {
        $pre = '';
        $tests = '';
        foreach ($item['event'] ?? [] as $event) {
            if (($event['listen'] ?? '') === 'prerequest') {
                $pre = implode("\n", $event['script']['exec'] ?? []);
            }
            if (($event['listen'] ?? '') === 'test') {
                $tests = implode("\n", $event['script']['exec'] ?? []);
            }
        }
        $out[] = ['name' => (string) ($item['name'] ?? ''), 'prerequest' => $pre, 'tests' => $tests];
    }

    return $out;
}

test('coleccion RC1 define carpetas Fase A y Fase B separadas', function () {
    $collection = rc1_postman_collection();
    $names = collect($collection['item'] ?? [])->pluck('name')->all();

    expect($names)->toContain('00 - RC1 Fase A (staging OTP)')
        ->and($names)->toContain('01 - ATIX phone lookup (contratos)')
        ->and($names)->toContain('02 - RC1 Fase B (Bearer y carrito)');
});

test('coleccion RC1 no usa conversation_id estatico entre ejecuciones', function () {
    $collection = rc1_postman_collection();
    $vars = collect($collection['variable'] ?? [])->pluck('value', 'key');

    expect($vars->get('conversation_id'))->toBe('')
        ->and($vars->get('channel_event_id'))->toBe('');
});

test('Fase A: cada prerequest es autonomo con rc1NewGuid local', function () {
    foreach (rc1_phase_a_request_scripts() as $script) {
        expect($script['prerequest'])->toContain('function rc1NewGuid()')
            ->and($script['prerequest'])->not->toContain('rc1Uuid')
            ->and($script['prerequest'])->not->toContain('const uuid =');
    }
});

test('coleccion no depende de prerequest global de coleccion', function () {
    $collection = rc1_postman_collection();
    expect($collection['event'] ?? [])->toBe([]);
});

test('Fase A A1 genera timestamp nonce correlation e idempotency antes de firmar', function () {
    $a1 = collect(rc1_phase_a_request_scripts())->firstWhere('name', 'A1 atixPhoneLookup reconocido (Fase A)');
    expect($a1)->not->toBeNull();

    $pre = $a1['prerequest'];
    $tsPos = strpos($pre, 'Math.floor(Date.now() / 1000)');
    $noncePos = strpos($pre, "pm.variables.set('atix_nonce'");
    $sigPos = strpos($pre, "pm.variables.set('atix_signature'");
    $corrPos = strpos($pre, "pm.variables.set('correlation_id'");

    expect($tsPos)->not->toBeFalse()
        ->and($noncePos)->not->toBeFalse()
        ->and($sigPos)->not->toBeFalse()
        ->and($corrPos)->not->toBeFalse()
        ->and($tsPos)->toBeLessThan($sigPos)
        ->and($noncePos)->toBeLessThan($sigPos)
        ->and($corrPos)->toBeLessThan($sigPos);
});

test('canonical ATIX del builder coincide con Laravel', function () {
    $payload = [
        'phone' => '+5255512345678',
        'conversation_id' => '11111111-1111-4111-8111-111111111111',
        'channel_event_id' => '22222222-2222-4222-8222-222222222222',
        'channel' => 'whatsapp',
        'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ];
    $timestamp = (string) time();
    $nonce = 'n-test-nonce-12345678';
    $secret = 'sandbox-secret';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $canonical = implode("\n", ['POST', '/api/v1/integrations/atix/phone-lookups', $timestamp, $nonce, hash('sha256', (string) $body)]);
    $expected = hash_hmac('sha256', $canonical, $secret);
    $headers = AtixSandboxRequestBuilder::headers($payload, $secret, 'sandbox-test', $nonce, 'idem', $timestamp, 'corr');

    expect($headers['X-Signature'])->toBe($expected)
        ->and(abs(time() - (int) $timestamp))->toBeLessThan(5);
});

test('Fase A A1 marca lookup ok y A2 omite si A1 fallo', function () {
    $a1 = collect(rc1_phase_a_request_scripts())->firstWhere('name', 'A1 atixPhoneLookup reconocido (Fase A)');
    $a2 = collect(rc1_phase_a_request_scripts())->firstWhere('name', 'A2 authLoginRequestCode contextual (Fase A)');

    expect($a1['tests'])->toContain('rc1_phase_a_lookup_ok')
        ->and($a2['prerequest'])->toContain('rc1_phase_a_lookup_ok')
        ->and($a2['prerequest'])->toContain('skipRequest')
        ->and($a2['tests'])->toContain('skip_fixture_reason');
});

test('challenge_id solo se guarda dentro de pm.test exitoso', function () {
    $a2 = collect(rc1_phase_a_request_scripts())->firstWhere('name', 'A2 authLoginRequestCode contextual (Fase A)');

    $challengeAssignCount = substr_count($a2['tests'], "pm.collectionVariables.set('challenge_id'");

    expect($a2['tests'])->toContain("pm.collectionVariables.set('challenge_id', responsePayload.data.challenge_id)")
        ->and($a2['tests'])->toContain('rc1RequireSuccessData')
        ->and($challengeAssignCount)->toBe(1);
});

test('tests usan rc1RequireSuccessData antes de leer data', function () {
    $collection = rc1_postman_collection();
    $blob = json_encode($collection);

    expect($blob)->toContain('rc1RequireSuccessData')
        ->and($blob)->not->toContain('synthetic-conv-rc1-staging')
        ->and($blob)->not->toContain('rc1Uuid');
});

test('pares replay ATIX usan sufijos dedicados en conversation y channel_event', function () {
    $collection = rc1_postman_collection();
    $blob = json_encode($collection);

    expect($blob)->toContain('-nonce-replay-pair')
        ->and($blob)->toContain('-idem-replay-pair')
        ->and($blob)->toContain('atix_replay_nonce')
        ->and($blob)->toContain('idempotency_replay_key');
});

test('Fase B exige OTP_CODE y challenge_id via skip fixture', function () {
    $collection = rc1_postman_collection();
    $phaseB = collect($collection['item'] ?? [])->firstWhere('name', '02 - RC1 Fase B (Bearer y carrito)');
    $blob = json_encode($phaseB);

    expect($blob)->toContain('Fase B detenida: OTP_CODE no configurado')
        ->and($blob)->toContain('falta challenge_id');
});

test('Fase B B11/B12 aislamiento documenta error.code contractual RC1', function () {
    $collection = rc1_postman_collection();
    $phaseB = collect($collection['item'] ?? [])->firstWhere('name', '02 - RC1 Fase B (Bearer y carrito)');
    $blob = json_encode($phaseB);

    expect($blob)->toContain('LAB_TEST_NOT_FOUND')
        ->and($blob)->toContain('CART_SERVICE_LINE_MISMATCH')
        ->and($blob)->toContain('404,201,409,403,422')
        ->and($blob)->toContain('409,403,422');
});

test('scripts de fase A y B existen', function () {
    expect(base_path('scripts/run-newman-rc1-staging-phase-a.sh'))->toBeReadableFile()
        ->and(base_path('scripts/run-newman-rc1-staging-phase-b.sh'))->toBeReadableFile()
        ->and(base_path('scripts/extract-rc1-phase-a-state.py'))->toBeReadableFile();
});

test('extract-rc1-phase-a-state valida salida con ids distintos', function () {
    $raw = [
        'run' => [
            'executions' => [
                [
                    'item' => ['name' => 'A1 lookup'],
                    'request' => [
                        'body' => [
                            'raw' => json_encode([
                                'conversation_id' => '11111111-1111-4111-8111-111111111111',
                                'channel_event_id' => '22222222-2222-4222-8222-222222222222',
                            ]),
                        ],
                    ],
                ],
                [
                    'item' => ['name' => 'A2 authLoginRequestCode contextual (Fase A)'],
                    'response' => [
                        'stream' => json_encode([
                            'success' => true,
                            'data' => ['challenge_id' => '33333333-3333-4333-8333-333333333333'],
                        ]),
                    ],
                ],
            ],
        ],
    ];

    $tmp = sys_get_temp_dir().'/rc1-extract-'.Str::random(8).'.json';
    $out = sys_get_temp_dir().'/rc1-state-'.Str::random(8).'.json';
    file_put_contents($tmp, json_encode($raw));

    $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
    if ($python === '') {
        expect(file_get_contents(base_path('scripts/extract-rc1-phase-a-state.py')))->toContain('challenge_id');

        return;
    }

    $cmd = escapeshellarg($python).' '.escapeshellarg(base_path('scripts/extract-rc1-phase-a-state.py')).' '
        .escapeshellarg($tmp).' '.escapeshellarg($out).' 2>&1';
    exec($cmd, $lines, $code);

    expect($code)->toBe(0);
    $state = json_decode(file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
    expect($state['conversation_id'])->not->toBe($state['channel_event_id'])
        ->and($state['challenge_id'])->toBe('33333333-3333-4333-8333-333333333333');

    @unlink($tmp);
    @unlink($out);
});
