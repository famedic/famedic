<?php

use App\Support\Api\V1\AkubicaCorrelationId;
use App\Support\Api\V1\ApiErrorRetryability;

test('correlation id is generated when header is missing', function () {
    $response = $this->getJson('/api/v1/catalog/laboratory-brands');

    $response->assertOk();
    $header = $response->headers->get(AkubicaCorrelationId::HEADER);
    expect($header)->not->toBeEmpty()
        ->and(AkubicaCorrelationId::isValid($header))->toBeTrue();
});

test('correlation id accepts a valid client header', function () {
    $clientId = 'qa-client-corr-001';

    $response = $this->withHeader(AkubicaCorrelationId::HEADER, $clientId)
        ->getJson('/api/v1/catalog/laboratory-brands');

    $response->assertOk()
        ->assertHeader(AkubicaCorrelationId::HEADER, $clientId);
});

test('correlation id replaces an invalid client header', function () {
    $response = $this->withHeader(AkubicaCorrelationId::HEADER, 'bad id with spaces')
        ->getJson('/api/v1/catalog/laboratory-brands');

    $response->assertOk();
    $header = $response->headers->get(AkubicaCorrelationId::HEADER);
    expect($header)->not->toBe('bad id with spaces')
        ->and(AkubicaCorrelationId::isValid($header))->toBeTrue();
});

test('error responses include retryable and correlation_id matching header', function () {
    $clientId = 'err-corr-id-abcdef01';

    $response = $this->withHeader(AkubicaCorrelationId::HEADER, $clientId)
        ->getJson('/api/v1/cart?brand=olab');

    $response->assertUnauthorized()
        ->assertHeader(AkubicaCorrelationId::HEADER, $clientId)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'UNAUTHENTICATED',
                'retryable' => false,
                'correlation_id' => $clientId,
            ],
        ]);
});

test('validation errors include retryable false and correlation_id', function () {
    $clientId = 'val-corr-id-abcdef02';

    $response = $this->withHeader(AkubicaCorrelationId::HEADER, $clientId)
        ->postJson('/api/v1/auth/login/request-code', []);

    $response->assertUnprocessable()
        ->assertHeader(AkubicaCorrelationId::HEADER, $clientId)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'retryable' => false,
                'correlation_id' => $clientId,
            ],
        ])
        ->assertJsonStructure([
            'error' => ['fields'],
        ]);
});

test('soft-deny and unknown route errors are non-retryable with correlation', function () {
    $response = $this->getJson('/api/v1/ruta-inexistente');

    $response->assertNotFound()
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'NOT_FOUND',
                'retryable' => false,
            ],
        ]);

    $header = $response->headers->get(AkubicaCorrelationId::HEADER);
    expect($header)->not->toBeEmpty()
        ->and($response->json('error.correlation_id'))->toBe($header);
});

test('feature disabled is non-retryable', function () {
    $user = \App\Models\User::factory()->withRegularCustomer()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        AkubicaCorrelationId::HEADER => 'feat-corr-id-abcde03',
    ])->putJson('/api/v1/orders/1/cancel');

    $response->assertStatus(503)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'FEATURE_DISABLED',
                'retryable' => false,
                'correlation_id' => 'feat-corr-id-abcde03',
            ],
        ]);
});

test('two requests receive distinct generated correlation ids', function () {
    $a = $this->getJson('/api/v1/catalog/laboratory-brands');
    $b = $this->getJson('/api/v1/catalog/laboratory-brands');

    $idA = $a->headers->get(AkubicaCorrelationId::HEADER);
    $idB = $b->headers->get(AkubicaCorrelationId::HEADER);

    expect($idA)->not->toBeEmpty()
        ->and($idB)->not->toBeEmpty()
        ->and($idA)->not->toBe($idB);
});

test('success envelope remains compatible without correlation_id in body', function () {
    $response = $this->getJson('/api/v1/catalog/laboratory-brands');

    $response->assertOk()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonMissingPath('data.correlation_id')
        ->assertJsonMissingPath('correlation_id');

    expect($response->headers->get(AkubicaCorrelationId::HEADER))->not->toBeEmpty();
});

test('ApiErrorRetryability classifies known codes', function () {
    expect(ApiErrorRetryability::isRetryable('VALIDATION_ERROR', 422))->toBeFalse()
        ->and(ApiErrorRetryability::isRetryable('INVALID_CODE', 422))->toBeFalse()
        ->and(ApiErrorRetryability::isRetryable('STEP_UP_REQUIRED', 403))->toBeFalse()
        ->and(ApiErrorRetryability::isRetryable('SECURE_LINK_CONSUMED', 410))->toBeFalse()
        ->and(ApiErrorRetryability::isRetryable('FEATURE_DISABLED', 503))->toBeFalse()
        ->and(ApiErrorRetryability::isRetryable('OTP_CONFIGURATION_INVALID', 503))->toBeFalse()
        ->and(ApiErrorRetryability::isRetryable('INTERNAL_ERROR', 500))->toBeFalse()
        ->and(ApiErrorRetryability::isRetryable('TOO_MANY_REQUESTS', 429))->toBeTrue()
        ->and(ApiErrorRetryability::isRetryable('OTP_COOLDOWN', 429))->toBeTrue()
        ->and(ApiErrorRetryability::isRetryable('OTP_TEMPORARY_UNAVAILABLE', 503))->toBeTrue()
        ->and(ApiErrorRetryability::isRetryable('DELIVERY_FAILED', 503))->toBeTrue()
        ->and(ApiErrorRetryability::isRetryable('DOCUMENT_STORAGE_UNAVAILABLE', 503))->toBeTrue();
});

test('AkubicaCorrelationId validation rejects unsafe values', function () {
    expect(AkubicaCorrelationId::isValid('short'))->toBeFalse()
        ->and(AkubicaCorrelationId::isValid('user@example.com'))->toBeFalse()
        ->and(AkubicaCorrelationId::isValid('has spaces here'))->toBeFalse()
        ->and(AkubicaCorrelationId::isValid(str_repeat('a', 129)))->toBeFalse()
        ->and(AkubicaCorrelationId::isValid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue()
        ->and(AkubicaCorrelationId::isValid('qa-client-corr-001'))->toBeTrue();
});

test('pdf download response includes correlation header without changing body', function () {
    $support = app(\App\Support\Api\V1\OrderDocumentDownloadSupport::class);
    $pdf = "%PDF-1.4\nfake";
    $response = $support->pdfResponse($pdf, 'test.pdf');

    $request = request();
    AkubicaCorrelationId::bind($request, 'pdf-corr-id-abcdef04');
    $response->headers->set(AkubicaCorrelationId::HEADER, 'pdf-corr-id-abcdef04');

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get(AkubicaCorrelationId::HEADER))->toBe('pdf-corr-id-abcdef04')
        ->and($response->getContent())->toBe($pdf);
});

test('logging context receives correlation_id without secrets', function () {
    $clientId = 'log-corr-id-abcdef05';

    $request = \Illuminate\Http\Request::create('/api/v1/catalog/laboratory-brands', 'GET');
    $request->headers->set(AkubicaCorrelationId::HEADER, $clientId);

    $middleware = new \App\Http\Middleware\AssignAkubicaCorrelationId;
    $middleware->handle($request, function ($req) use ($clientId) {
        expect($req->attributes->get(AkubicaCorrelationId::REQUEST_ATTRIBUTE))->toBe($clientId)
            ->and(\Illuminate\Support\Facades\Log::sharedContext()['correlation_id'] ?? null)->toBe($clientId);

        return response()->json(['success' => true, 'data' => []]);
    });
});
