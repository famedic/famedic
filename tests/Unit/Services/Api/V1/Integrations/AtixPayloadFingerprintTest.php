<?php

use App\Services\Api\V1\Integrations\AtixPayloadFingerprint;
use App\Services\Api\V1\Integrations\AtixResponseSerializer;

test('ATIX fingerprint is invariant to object property order and preserves list order', function () {
    $fingerprints = app(AtixPayloadFingerprint::class);

    $a = [
        'audience' => 'leo',
        'personalization' => ['allowed' => true, 'labels' => ['a', 'b']],
        'phone' => '+525512345678',
    ];
    $b = [
        'phone' => '+525512345678',
        'personalization' => ['labels' => ['a', 'b'], 'allowed' => true],
        'audience' => 'leo',
    ];
    $c = [
        'phone' => '+525512345678',
        'personalization' => ['labels' => ['b', 'a'], 'allowed' => true],
        'audience' => 'leo',
    ];

    expect($fingerprints->hash($a))->toBe($fingerprints->hash($b))
        ->and($fingerprints->hash($a))->not->toBe($fingerprints->hash($c));
});

test('ATIX response arrays serialize deterministically and reconstruct after encryption', function () {
    $serializer = app(AtixResponseSerializer::class);
    $response = [
        'success' => true,
        'data' => ['challenge_id' => 'synthetic-challenge', 'personalization' => ['allowed' => false]],
    ];

    $serialized = $serializer->encode($response);
    expect($serialized)->toBe($serializer->encode([
        'data' => ['personalization' => ['allowed' => false], 'challenge_id' => 'synthetic-challenge'],
        'success' => true,
    ]));
    $decoded = $serializer->decode($serialized);
    expect($decoded['success'])->toBeTrue()
        ->and($decoded['data'])->toMatchArray($response['data']);
});
