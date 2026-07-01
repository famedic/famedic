<?php

use App\Support\Odessa\OdessaApiErrorFormatter;

test('formatter extracts getToken socio inactivo message', function () {
    $message = json_encode([
        'response' => [
            'errorCode' => 1,
            'message' => 'Socio inactivo.',
            'token' => '',
        ],
    ]);

    expect(OdessaApiErrorFormatter::summarize($message))->toBe('Socio inactivo.');
});

test('formatter extracts getUserData chrMessage from list envelope', function () {
    $message = json_encode([
        'response' => [
            [
                'intError' => 1,
                'chrMessage' => 'Error de prueba',
            ],
        ],
    ]);

    expect(OdessaApiErrorFormatter::summarize($message))->toBe('Error de prueba');
});
