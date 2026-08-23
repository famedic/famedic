<?php

use App\Support\EfevooPayLogSanitizer;

test('drops pan fields and keeps allowed identifiers', function () {
    $context = EfevooPayLogSanitizer::context([
        'card_number' => '4111111111111111',
        'nested' => ['card_number' => '4111111111111111'],
        'cvv' => '123',
        'reference' => 'ORDER-1',
        'customer_id' => 42,
    ]);

    expect($context)
        ->toBe([
            'reference' => 'ORDER-1',
            'customer_id' => 42,
        ])
        ->not->toHaveKeys(['card_number', 'nested', 'cvv']);
});

test('drops 3ds and track sensitive values', function () {
    $context = EfevooPayLogSanitizer::context([
        'cav' => 'secret-cav',
        'track2' => '4111111111111111=2512101',
        'operation' => 'payment',
        'efevoo_3ds_session_id' => 9,
    ]);

    expect($context)
        ->toBe([
            'operation' => 'payment',
            'efevoo_3ds_session_id' => 9,
        ])
        ->not->toHaveKeys(['cav', 'track2']);
});

test('drops tokens authorization and non allowlisted keys regardless of case', function () {
    $context = EfevooPayLogSanitizer::context([
        'Authorization' => 'Bearer secret-token',
        'card_token' => 'card-token-secret',
        'client_token' => 'client-token-secret',
        'nested_token' => ['token' => 'secret-token'],
        'amount_cents' => 15000,
        'customer_id' => 7,
    ]);

    expect($context)
        ->toBe(['customer_id' => 7])
        ->not->toHaveKeys(['Authorization', 'card_token', 'client_token', 'nested_token', 'amount_cents']);
});

test('sanitizes provider messages', function () {
    expect(EfevooPayLogSanitizer::providerMessage('Declined 4111 1111-1111 1111 Bearer abc.def.ghi'))
        ->toBe('Declined [redacted-pan] [redacted-token]');

    expect(EfevooPayLogSanitizer::providerMessage('card_token was rejected'))
        ->toBe('Respuesta del proveedor omitida por seguridad.');
});

test('keeps allowed fields and drops unknown payloads', function () {
    $context = EfevooPayLogSanitizer::context([
        'operation' => 'payment',
        'status' => 'failed',
        'reference' => 'REF-123',
        'unknown_payload' => ['foo' => 'bar'],
        'headers' => ['Authorization' => 'Bearer secret-token'],
        'json' => '{"card_number":"4111111111111111"}',
    ]);

    expect($context)
        ->toBe([
            'operation' => 'payment',
            'status' => 'failed',
            'reference' => 'REF-123',
        ])
        ->not->toHaveKeys(['unknown_payload', 'headers', 'json']);
});

test('converts arrays objects and exceptions without leaking raw messages', function () {
    $context = EfevooPayLogSanitizer::context([
        'status' => ['nested' => 'value'],
        'reference' => new stdClass(),
    ]);

    $exceptionContext = EfevooPayLogSanitizer::exception(
        new RuntimeException('PAN 4111111111111111 CVV 123')
    );

    expect($context)->toBe([
        'status' => '[non_scalar]',
        'reference' => '[non_scalar]',
    ]);

    expect($exceptionContext)
        ->toHaveKey('exception_class', RuntimeException::class)
        ->not->toContain('4111111111111111');
});

test('truncates long provider messages and redacts long token shaped values', function () {
    $message = str_repeat('A', 220).' abcdefghijklmnopqrstuvwxyzABCDEF1234567890';

    $sanitized = EfevooPayLogSanitizer::providerMessage($message);

    expect(strlen($sanitized))->toBeLessThanOrEqual(180);
    expect($sanitized)->not->toContain('abcdefghijklmnopqrstuvwxyzABCDEF1234567890');
});

test('does not throw for unexpected data', function () {
    $resource = fopen('php://memory', 'r');

    try {
        $context = EfevooPayLogSanitizer::context([
            'status' => $resource,
            'authorization' => $resource,
            'customer_id' => 12,
        ]);
    } finally {
        fclose($resource);
    }

    expect($context)->toBe([
        'status' => '[non_scalar]',
        'customer_id' => 12,
    ]);
});
