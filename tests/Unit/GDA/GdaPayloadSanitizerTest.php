<?php

use App\Support\GDA\GdaPayloadSanitizer;

it('removes heavy base64 keys from payload recursively', function () {
    $payload = [
        'id' => 'ORDER-1',
        'infogda_resultado_b64' => base64_encode('pdf'),
        'nested' => [
            'pdf_base64' => base64_encode('nested-pdf'),
            'keep' => 'value',
        ],
    ];

    $sanitized = GdaPayloadSanitizer::sanitize($payload);

    expect($sanitized)->toMatchArray([
        'id' => 'ORDER-1',
        'nested' => ['keep' => 'value'],
    ])
        ->and($sanitized)->not->toHaveKey('infogda_resultado_b64')
        ->and($sanitized['nested'])->not->toHaveKey('pdf_base64');
});

it('extracts results pdf base64 from payload', function () {
    $encoded = base64_encode('%PDF-1.4');

    expect(GdaPayloadSanitizer::extractResultsPdfBase64([
        'infogda_resultado_b64' => $encoded,
    ]))->toBe($encoded);
});

it('strips data uri prefix from base64', function () {
    $encoded = base64_encode('%PDF-1.4');

    expect(GdaPayloadSanitizer::stripDataUriPrefix('data:application/pdf;base64,'.$encoded))
        ->toBe($encoded);
});
