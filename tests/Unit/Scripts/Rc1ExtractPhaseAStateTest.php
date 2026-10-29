<?php

use Illuminate\Support\Str;
use Tests\Support\Postman\NewmanResponseBodyDecoder;

function rc1_extract_phase_a_fixture(array $executions): string
{
    $path = sys_get_temp_dir().'/rc1-newman-'.Str::random(8).'.json';
    file_put_contents($path, json_encode(['run' => ['executions' => $executions]], JSON_THROW_ON_ERROR));

    return $path;
}

function rc1_run_extract_script(string $inputPath, ?string $outputPath = null): array
{
    $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
    if ($python === '') {
        return ['code' => 127, 'output' => '', 'skipped' => true, 'state_path' => ''];
    }

    $outArg = $outputPath ?? sys_get_temp_dir().'/rc1-state-'.Str::random(8).'.json';
    $cmd = escapeshellarg($python).' '.escapeshellarg(base_path('scripts/extract-rc1-phase-a-state.py')).' '
        .escapeshellarg($inputPath).' '.escapeshellarg($outArg).' 2>&1';
    exec($cmd, $lines, $code);

    return [
        'code' => $code,
        'output' => implode("\n", $lines),
        'state_path' => $outArg,
        'skipped' => false,
    ];
}

function rc1_lookup_execution(string $conv, string $event): array
{
    return [
        'item' => ['name' => 'A1 atixPhoneLookup reconocido (Fase A)'],
        'request' => [
            'body' => [
                'raw' => json_encode([
                    'conversation_id' => $conv,
                    'channel_event_id' => $event,
                    'phone' => '+5255512345678',
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}

function rc1_request_code_execution(array $response, string $name = 'A2 authLoginRequestCode contextual (Fase A)'): array
{
    return [
        'item' => ['name' => $name],
        'request' => [
            'header' => [
                ['key' => 'X-Correlation-ID', 'value' => 'corr-test-12345678-1234-1234-1234-123456789012'],
            ],
        ],
        'response' => $response,
    ];
}

function rc1_assert_extract_parity(array $executions, bool $expectSuccess): void
{
    $report = ['run' => ['executions' => $executions]];
    $phpState = NewmanResponseBodyDecoder::extractPhaseAState($report);

    $input = rc1_extract_phase_a_fixture($executions);
    $result = rc1_run_extract_script($input);

    if ($result['skipped']) {
        if ($expectSuccess) {
            expect($phpState)->toHaveKeys(['conversation_id', 'channel_event_id', 'challenge_id']);
        } else {
            expect($phpState)->not->toHaveKey('challenge_id');
        }

        @unlink($input);

        return;
    }

    if ($expectSuccess) {
        expect($result['code'])->toBe(0);
        $pyState = json_decode(file_get_contents($result['state_path']), true, 512, JSON_THROW_ON_ERROR);
        expect($pyState['challenge_id'])->toBe($phpState['challenge_id']);
    } else {
        expect($result['code'])->toBe(1)
            ->and($result['output'])->toContain('challenge_id');
    }

    @unlink($input);
    if ($result['state_path'] !== '' && file_exists($result['state_path'])) {
        @unlink($result['state_path']);
    }
}

test('extractor reconoce nombre real A2 authLoginRequestCode contextual con Buffer', function () {
    $conv = '77777777-7777-4777-8777-777777777777';
    $event = '88888888-8888-4888-8888-888888888888';
    $challenge = '99999999-9999-4999-8999-999999999999';
    $json = json_encode(['success' => true, 'data' => ['challenge_id' => $challenge]], JSON_THROW_ON_ERROR);
    $bytes = array_values(unpack('C*', $json));

    $executions = [
        rc1_lookup_execution($conv, $event),
        rc1_request_code_execution(
            ['code' => 202, 'stream' => ['type' => 'Buffer', 'data' => $bytes]],
            'A2 authLoginRequestCode contextual (Fase A)',
        ),
    ];

    $state = NewmanResponseBodyDecoder::extractPhaseAState(['run' => ['executions' => $executions]]);
    expect($state['conversation_id'])->toBe($conv)
        ->and($state['channel_event_id'])->toBe($event)
        ->and($state['challenge_id'])->toBe($challenge);

    rc1_assert_extract_parity($executions, true);
});

test('extractor lee response.stream como string JSON', function () {
    $conv = '11111111-1111-4111-8111-111111111111';
    $event = '22222222-2222-4222-8222-222222222222';
    $challenge = '33333333-3333-4333-8333-333333333333';

    rc1_assert_extract_parity([
        rc1_lookup_execution($conv, $event),
        rc1_request_code_execution([
            'code' => 202,
            'stream' => json_encode(['success' => true, 'data' => ['challenge_id' => $challenge]], JSON_THROW_ON_ERROR),
        ]),
    ], true);

    $decoded = NewmanResponseBodyDecoder::decodeResponseJson([
        'stream' => json_encode(['success' => true, 'data' => ['challenge_id' => $challenge]], JSON_THROW_ON_ERROR),
    ]);
    expect($decoded['data']['challenge_id'])->toBe($challenge);
});

test('extractor lee response.stream como Buffer data', function () {
    $challenge = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    $json = json_encode(['success' => true, 'data' => ['challenge_id' => $challenge]], JSON_THROW_ON_ERROR);
    $bytes = array_values(unpack('C*', $json));

    rc1_assert_extract_parity([
        rc1_lookup_execution('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
        rc1_request_code_execution(['code' => 202, 'stream' => ['type' => 'Buffer', 'data' => $bytes]]),
    ], true);

    $decoded = NewmanResponseBodyDecoder::decodeResponseJson(['stream' => ['type' => 'Buffer', 'data' => $bytes]]);
    expect($decoded['data']['challenge_id'])->toBe($challenge);
});

test('extractor lee response.body como string JSON', function () {
    $challenge = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    rc1_assert_extract_parity([
        rc1_lookup_execution('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'),
        rc1_request_code_execution([
            'code' => 202,
            'body' => json_encode(['success' => true, 'data' => ['challenge_id' => $challenge]], JSON_THROW_ON_ERROR),
        ]),
    ], true);

    $decoded = NewmanResponseBodyDecoder::decodeResponseJson([
        'body' => json_encode(['success' => true, 'data' => ['challenge_id' => $challenge]], JSON_THROW_ON_ERROR),
    ]);
    expect($decoded['data']['challenge_id'])->toBe($challenge);
});

test('extractor conserva correlation_id en salida python cuando hay header', function () {
    $corr = '99999999-9999-4999-8999-999999999999';
    $executions = [
        rc1_lookup_execution('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'),
        rc1_request_code_execution([
            'code' => 202,
            'header' => [['key' => 'X-Correlation-ID', 'value' => $corr]],
            'stream' => json_encode([
                'success' => true,
                'data' => ['challenge_id' => '33333333-3333-4333-8333-333333333333'],
            ], JSON_THROW_ON_ERROR),
        ]),
    ];

    $input = rc1_extract_phase_a_fixture($executions);
    $result = rc1_run_extract_script($input);
    if ($result['skipped']) {
        expect(true)->toBeTrue();

        return;
    }
    expect($result['code'])->toBe(0);
    $state = json_decode(file_get_contents($result['state_path']), true, 512, JSON_THROW_ON_ERROR);
    expect($state['correlation_id'] ?? null)->toBe($corr);
    @unlink($input);
    @unlink($result['state_path']);
});

test('extractor falla cuando request-code es error sin challenge_id', function () {
    rc1_assert_extract_parity([
        rc1_lookup_execution('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'),
        rc1_request_code_execution([
            'code' => 422,
            'stream' => json_encode([
                'success' => false,
                'error' => ['code' => 'ACCOUNT_CONTEXT_REQUIRED', 'correlation_id' => 'corr-err'],
            ], JSON_THROW_ON_ERROR),
        ]),
    ], false);
});

test('extractor no genera estado completo cuando falta challenge_id en 202', function () {
    $state = NewmanResponseBodyDecoder::extractPhaseAState([
        'run' => [
            'executions' => [
                rc1_lookup_execution('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'),
                rc1_request_code_execution([
                    'code' => 202,
                    'stream' => json_encode(['success' => true, 'data' => ['requires_otp' => true]], JSON_THROW_ON_ERROR),
                ]),
            ],
        ],
    ]);

    expect($state)->not->toHaveKey('challenge_id');

    rc1_assert_extract_parity([
        rc1_lookup_execution('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'),
        rc1_request_code_execution([
            'code' => 202,
            'stream' => json_encode(['success' => true, 'data' => ['requires_otp' => true]], JSON_THROW_ON_ERROR),
        ]),
    ], false);
});
