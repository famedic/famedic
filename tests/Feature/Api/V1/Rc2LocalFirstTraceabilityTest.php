<?php

// RC2 LOCAL-FIRST (t58u) — trazabilidad (R06) en el salto web de continuidad B24 y semantica de correlation id en replay ATIX (A06).
// LOCAL tests on MySQL 8. Sin SMS, sin QA.
use App\Support\Api\V1\AkubicaCorrelationId;
use Tests\Support\Atix\AtixSandboxRequestBuilder;

test('B24/R06 continuity web hop echoes a valid client correlation id on the redirect to login', function () {
    $this->get('/checkout/continue/rc2-local-first-invalid-token', [AkubicaCorrelationId::HEADER => 'rc2-local-first-cid-0001'])
        ->assertRedirect(route('login'))
        ->assertHeader(AkubicaCorrelationId::HEADER, 'rc2-local-first-cid-0001');
});

test('B24/R06 continuity web hop generates a correlation id when none is sent', function () {
    $response = $this->get('/checkout/continue/rc2-local-first-invalid-token')->assertRedirect(route('login'));

    expect($response->headers->get(AkubicaCorrelationId::HEADER))->toBeString()->not->toBe('');
});

test('B24/R06 resume and legacy link routes are traceable too', function () {
    $this->get('/checkout/continue', [AkubicaCorrelationId::HEADER => 'rc2-local-first-cid-0002'])
        ->assertRedirect(route('login'))
        ->assertHeader(AkubicaCorrelationId::HEADER, 'rc2-local-first-cid-0002');
    $this->get('/akubica/checkout/rc2-local-first-invalid-token', [AkubicaCorrelationId::HEADER => 'rc2-local-first-cid-0003'])
        ->assertHeader(AkubicaCorrelationId::HEADER, 'rc2-local-first-cid-0003');
});

test('P1-A6 scope is unchanged for unrelated web routes', function () {
    $this->withoutVite();
    expect($this->get('/login')->headers->has(AkubicaCorrelationId::HEADER))->toBeFalse();
});

test('A06 ATIX idempotent replay returns the same contractual body and echoes the correlation id sent (AtixCorrelationId)', function () {
    config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
    $payload = [
        'phone' => '+525500000999', 'conversation_id' => 'rc2-lf-a06', 'channel_event_id' => 'rc2-lf-a06-event',
        'channel' => 'whatsapp', 'audience' => 'leo', 'personalization' => ['allowed' => true],
    ];
    $first = $this->withHeaders(AtixSandboxRequestBuilder::headers($payload, nonce: 'rc2-lf-a06-nonce-1', idempotency: 'rc2-lf-a06-idem', correlationId: 'rc2-lf-a06-cid-first'))
        ->postJson('/api/v1/integrations/atix/phone-lookups', $payload)
        ->assertStatus(202)
        ->assertHeader(AkubicaCorrelationId::HEADER, 'rc2-lf-a06-cid-first');
    $replay = $this->withHeaders(AtixSandboxRequestBuilder::headers($payload, nonce: 'rc2-lf-a06-nonce-2', idempotency: 'rc2-lf-a06-idem', correlationId: 'rc2-lf-a06-cid-second'))
        ->postJson('/api/v1/integrations/atix/phone-lookups', $payload)
        ->assertStatus(202)
        ->assertHeader(AkubicaCorrelationId::HEADER, 'rc2-lf-a06-cid-second');

    expect($replay->json('data'))->toBe($first->json('data'));
});
