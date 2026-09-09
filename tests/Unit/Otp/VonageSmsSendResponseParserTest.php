<?php

use App\Services\Otp\Delivery\VonageSmsSendResponseParser;
use Vonage\SMS\Collection;

test('extrae message-id desde Collection real de vonage client-core 4.x', function () {
    $response = new Collection([
        'message-count' => 1,
        'messages' => [[
            'to' => '525512345678',
            'message-id' => '0A00000012345678ABCD',
            'status' => 0,
            'remaining-balance' => '12.34',
            'message-price' => '0.04500',
            'network' => '334020',
        ]],
    ]);

    expect(VonageSmsSendResponseParser::extractMessageId($response))
        ->toBe('0A00000012345678ABCD');
});

test('extractMessageId retorna null para respuesta vacia o invalida', function () {
    $empty = new Collection([
        'message-count' => 0,
        'messages' => [],
    ]);

    expect(VonageSmsSendResponseParser::extractMessageId($empty))->toBeNull();
    expect(VonageSmsSendResponseParser::extractMessageId(null))->toBeNull();
    expect(VonageSmsSendResponseParser::extractMessageId(new \stdClass()))->toBeNull();
});

test('SentSMS getMessageId coincide con clave message-id del json vonage', function () {
    $payload = [
        'to' => '525598765432',
        'message-id' => '0900000000ABCDEF',
        'status' => 0,
        'remaining-balance' => '1.00',
        'message-price' => '0.04500',
        'network' => '334020',
    ];

    $sent = (new Collection(['message-count' => 1, 'messages' => [$payload]]))->current();

    expect($sent->getMessageId())->toBe('0900000000ABCDEF');
    expect(VonageSmsSendResponseParser::parse(
        new Collection(['message-count' => 1, 'messages' => [$payload]])
    )->accepted)->toBeTrue();
});
