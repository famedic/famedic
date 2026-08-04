<?php

use App\Enums\P0aOtpPurpose;
use App\Models\Api\V1\ApiV1AuditEvent;
use App\Models\OtpChallenge;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Services\Api\V1\Audit\AuditActorResolver;
use App\Services\Api\V1\Audit\AuditEventDefinitions;
use App\Services\Api\V1\Audit\AuditEventWriter;
use App\Services\Api\V1\Audit\AuditMetadataNormalizer;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\DocumentAccessAuditRecorder;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Services\Otp\StepUp\BearerStepUpEnforcement;
use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00'));
    Storage::fake();
    disableAllAkubicaOtpFeatures();
    config()->set('api_v1.audit.enabled', false);
    config()->set('api_v1.idempotency.enabled', false);
});

afterEach(function () {
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
    config()->set('api_v1.audit.enabled', false);
    config()->set('api_v1.idempotency.enabled', false);
});

function enableDocumentAccessAudit(): void
{
    config()->set('api_v1.audit.enabled', true);
    app()->forgetInstance(AuditMetadataNormalizer::class);
    app()->forgetInstance(AuditEventWriter::class);
    app()->forgetInstance(DocumentAccessAuditRecorder::class);
    app()->forgetInstance(AuditActorResolver::class);
}

/**
 * @return array{0: User, 1: string, 2: int}
 */
function docAuditCustomerToken(array $userAttrs = []): array
{
    $user = User::factory()->withRegularCustomer()->create(array_merge([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
        'email' => 'doc.audit@ejemplo.com',
    ], $userAttrs));

    $newToken = $user->createToken('akubica-test');

    return [$user, $newToken->plainTextToken, (int) $newToken->accessToken->id];
}

function docAuditResultsGrant(User $user, int $orderId, int $tokenId, array $overrides = []): OtpStepUpGrant
{
    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5512345678',
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'channel' => 'sms',
        'destination_normalized' => '+525512345678',
        'destination_masked' => '***5678',
        'code_hash' => Hash::make('000000'),
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'context_id' => $orderId,
    ]);

    return OtpStepUpGrant::query()->create(array_merge([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'personal_access_token_id' => $tokenId,
        'otp_challenge_id' => $challenge->id,
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'resource_id' => $orderId,
        'granted_at' => now(),
        'expires_at' => now()->addMinutes(10),
        'revoked_at' => null,
    ], $overrides));
}

function docAuditInvoicesGrant(User $user, int $invoiceId, int $tokenId, array $overrides = []): OtpStepUpGrant
{
    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5512345678',
        'purpose' => P0aOtpPurpose::StepUpInvoices->value,
        'channel' => 'sms',
        'destination_normalized' => '+525512345678',
        'destination_masked' => '***5678',
        'code_hash' => Hash::make('000000'),
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => OtpStepUpGrant::RESOURCE_INVOICE,
        'context_id' => $invoiceId,
    ]);

    return OtpStepUpGrant::query()->create(array_merge([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'personal_access_token_id' => $tokenId,
        'otp_challenge_id' => $challenge->id,
        'purpose' => P0aOtpPurpose::StepUpInvoices->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_INVOICE,
        'resource_id' => $invoiceId,
        'granted_at' => now(),
        'expires_at' => now()->addMinutes(10),
        'revoked_at' => null,
    ], $overrides));
}

function docAuditOpaqueToken(string $url): string
{
    $token = basename(parse_url($url, PHP_URL_PATH) ?: '');
    expect($token)->toMatch('/^[A-Fa-f0-9]{64}$/');

    return $token;
}

function docAuditAssertNoSecrets(?ApiV1AuditEvent $event = null, array $extraForbidden = []): void
{
    $rows = $event !== null
        ? [DB::table('api_v1_audit_events')->where('id', $event->id)->first()]
        : DB::table('api_v1_audit_events')->get()->all();

    foreach ($rows as $row) {
        $blob = json_encode($row);
        expect($blob)->not->toContain('Bearer')
            ->and($blob)->not->toContain('+5255')
            ->and($blob)->not->toContain('@ejemplo.com')
            ->and($blob)->not->toContain('X-Step-Up-Grant')
            ->and($blob)->not->toContain('grant_public_id')
            ->and($blob)->not->toContain('token_hash')
            ->and($blob)->not->toContain('/api/v1/secure-downloads/')
            ->and($blob)->not->toContain('%PDF')
            ->and($blob)->not->toContain('results/')
            ->and($blob)->not->toContain('invoices/')
            ->and($blob)->not->toContain('idem-key-');

        foreach ($extraForbidden as $needle) {
            expect($blob)->not->toContain($needle);
        }
    }
}

// ── Flag OFF ─────────────────────────────────────────────────────────────

test('flag OFF secure-link and download routes work without audit inserts', function () {
    enableResultsSecureLinks();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-off.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);

    $created = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertCreated();

    $plain = docAuditOpaqueToken($created->json('data.url'));
    $this->get("/api/v1/secure-downloads/{$plain}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

test('document access audit does not instrument cart checkout or catalog', function () {
    enableDocumentAccessAudit();
    [$user, $token] = docAuditCustomerToken();

    $this->getJson('/api/v1/cart?brand=olab', authHeaders($token))->assertOk();
    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token));
    $this->getJson('/api/v1/catalog/laboratory-brands')->assertOk();

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

// ── Secure link creation ─────────────────────────────────────────────────

test('results secure-link creation audits succeeded with safe metadata', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-create-r.pdf';
    storeFakePdf($path, '%PDF-1.4 audit create results');
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);
    $corr = 'corr-doc-create-results-01';

    $response = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        array_merge(authHeaders($token), [AkubicaCorrelationId::HEADER => $corr]),
    )->assertCreated();

    $url = $response->json('data.url');
    $plain = docAuditOpaqueToken($url);
    $link = OtpSecureDownloadLink::query()->sole();

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_CREATED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->http_status)->toBe(201)
        ->and($event->correlation_id)->toBe($corr)
        ->and($event->actor_type)->toBe('customer')
        ->and($event->actor_key)->toBe('customer:'.$user->customer->id)
        ->and($event->customer_id)->toBe($user->customer->id)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->personal_access_token_id)->toBe($tokenId)
        ->and($event->resource_type)->toBe('laboratory_purchase')
        ->and($event->resource_key)->toBe((string) $order->id)
        ->and($event->metadata['purpose'])->toBe('results')
        ->and($event->metadata['secure_link_row_id'])->toBe($link->id)
        ->and($event->metadata['step_up_row_id'])->toBe($grant->id)
        ->and($event->metadata['laboratory_purchase_row_id'])->toBe($order->id)
        ->and($event->metadata['ttl_minutes'])->toBe(5)
        ->and($event->metadata['max_opens'])->toBe(1)
        ->and($response->json('data'))->toHaveKeys(['url', 'expires_at', 'max_opens'])
        ->and($response->json('data'))->not->toHaveKey('audit');

    docAuditAssertNoSecrets($event, [$plain, $url, $grant->public_id, $link->public_id, $path]);
});

test('invoices secure-link creation audits succeeded with invoice resource', function () {
    enableInvoiceSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order, 'invoices/audit-create-i.pdf');
    $grant = docAuditInvoicesGrant($user, $invoice->id, $tokenId);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertCreated();

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_INVOICES_SECURE_LINK_CREATED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->resource_type)->toBe('invoice')
        ->and($event->resource_key)->toBe((string) $invoice->id)
        ->and($event->metadata['purpose'])->toBe('invoices')
        ->and($event->metadata['invoice_row_id'])->toBe($invoice->id)
        ->and($event->metadata['laboratory_purchase_row_id'])->toBe($order->id);

    docAuditAssertNoSecrets($event, [$grant->public_id]);
});

test('results secure-link ownership reject omits foreign resource ids', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$userA, $tokenA] = docAuditCustomerToken();
    [$userB] = docAuditCustomerToken(['phone' => '5511111111', 'email' => 'other@ejemplo.com']);
    $orderB = createAkubicaLaboratoryPurchase($userB, ['results' => 'results/x.pdf']);
    storeFakePdf('results/x.pdf');

    $this->postJson(
        "/api/v1/orders/{$orderB->id}/results/secure-link",
        ['grant_id' => (string) Str::uuid()],
        authHeaders($tokenA),
    )->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_CREATED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('ORDER_NOT_FOUND')
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata['laboratory_purchase_row_id'] ?? null)->toBeNull();

    docAuditAssertNoSecrets($event);
});

test('results secure-link invalid grant audits rejected without public grant id', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-bad-grant.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $foreignGrant = (string) Str::uuid();

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $foreignGrant],
        authHeaders($token),
    )->assertStatus(422)
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_CREATED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('STEP_UP_GRANT_INVALID')
        ->and($event->resource_key)->toBe((string) $order->id)
        ->and($event->metadata['step_up_row_id'] ?? null)->toBeNull();

    docAuditAssertNoSecrets($event, [$foreignGrant]);
});

test('idempotent results secure-link replay does not duplicate link or audit event', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    config()->set('api_v1.idempotency.enabled', true);
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-idem.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);
    $key = 'idem-key-doc-results-abcdef12';
    $headers = array_merge(authHeaders($token), [
        IdempotencyKey::HEADER => $key,
        AkubicaCorrelationId::HEADER => 'corr-doc-idem-results',
    ]);
    $body = ['grant_id' => $grant->public_id];

    $first = $this->postJson("/api/v1/orders/{$order->id}/results/secure-link", $body, $headers)
        ->assertCreated();
    $replay = $this->postJson("/api/v1/orders/{$order->id}/results/secure-link", $body, $headers)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($replay->json('data.url'))->toBe($first->json('data.url'))
        ->and(OtpSecureDownloadLink::query()->count())->toBe(1)
        ->and(ApiV1AuditEvent::query()
            ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_CREATED)
            ->count())->toBe(1);

    docAuditAssertNoSecrets();
});

test('idempotent invoices secure-link conflict does not invent semantic audit', function () {
    enableInvoiceSecureLinks();
    enableDocumentAccessAudit();
    config()->set('api_v1.idempotency.enabled', true);
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $grantA = docAuditInvoicesGrant($user, $invoice->id, $tokenId);
    $grantB = docAuditInvoicesGrant($user, $invoice->id, $tokenId);
    $key = 'idem-key-doc-inv-conflict99';
    $headers = array_merge(authHeaders($token), [IdempotencyKey::HEADER => $key]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grantA->public_id],
        $headers,
    )->assertCreated();

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grantB->public_id],
        $headers,
    )->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

    expect(ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_INVOICES_SECURE_LINK_CREATED)
        ->count())->toBe(1)
        ->and(OtpSecureDownloadLink::query()->count())->toBe(1);
});

// ── Public open ──────────────────────────────────────────────────────────

test('results secure-link open audits succeeded with public hmac actor', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-open-r.pdf';
    storeFakePdf($path, '%PDF-1.4 open results');
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);
    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');
    $plain = docAuditOpaqueToken($url);
    DB::table('api_v1_audit_events')->delete();

    $corr = 'corr-doc-open-results';
    $response = $this->get("/api/v1/secure-downloads/{$plain}", [
        AkubicaCorrelationId::HEADER => $corr,
    ])->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="resultado-'.$order->id.'.pdf"');

    expect($response->getContent())->toStartWith('%PDF')
        ->and($response->headers->get(AkubicaCorrelationId::HEADER))->toBe($corr);

    $link = OtpSecureDownloadLink::query()->sole();
    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_OPENED)
        ->sole();

    $expectedActor = app(AuditActorResolver::class)
        ->resolvePublic(DocumentAccessAuditRecorder::ACTOR_PURPOSE_RESULTS_OPEN, $plain);

    expect($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->http_status)->toBe(200)
        ->and($event->actor_type)->toBe('public')
        ->and($event->actor_key)->toBe($expectedActor->key)
        ->and($event->correlation_id)->toBe($corr)
        ->and($event->metadata['open_number'])->toBe(1)
        ->and($event->metadata['max_opens'])->toBe(1)
        ->and($event->metadata['secure_link_row_id'])->toBe($link->id)
        ->and((int) $link->open_count)->toBe(1)
        ->and(ApiV1AuditEvent::query()
            ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_DOWNLOADED)
            ->count())->toBe(0);

    docAuditAssertNoSecrets($event, [$plain, $url, $grant->public_id, $link->public_id]);
});

test('invoices secure-link open audits succeeded with domain-separated hmac', function () {
    enableInvoiceSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order, 'invoices/audit-open-i.pdf');
    storeFakePdf('invoices/audit-open-i.pdf', '%PDF-1.4 open invoice');
    $grant = docAuditInvoicesGrant($user, $invoice->id, $tokenId);
    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');
    $plain = docAuditOpaqueToken($url);
    DB::table('api_v1_audit_events')->delete();

    $this->get("/api/v1/secure-downloads/{$plain}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_INVOICES_SECURE_LINK_OPENED)
        ->sole();

    $resultsActor = app(AuditActorResolver::class)
        ->resolvePublic(DocumentAccessAuditRecorder::ACTOR_PURPOSE_RESULTS_OPEN, $plain);
    $invoicesActor = app(AuditActorResolver::class)
        ->resolvePublic(DocumentAccessAuditRecorder::ACTOR_PURPOSE_INVOICES_OPEN, $plain);

    expect($event->actor_key)->toBe($invoicesActor->key)
        ->and($event->actor_key)->not->toBe($resultsActor->key)
        ->and($event->metadata['purpose'])->toBe('invoices')
        ->and($event->metadata['invoice_row_id'])->toBe($invoice->id);

    docAuditAssertNoSecrets($event, [$plain]);
});

test('secure-link expired open audits rejected without consuming opens', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-exp.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);
    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');
    $plain = docAuditOpaqueToken($url);
    DB::table('api_v1_audit_events')->delete();

    Carbon::setTestNow(now()->addMinutes(10));

    $this->getJson("/api/v1/secure-downloads/{$plain}")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'SECURE_LINK_EXPIRED')
        ->assertJsonPath('error.retryable', false);

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_OPENED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('SECURE_LINK_EXPIRED')
        ->and($event->metadata['open_number'] ?? null)->toBeNull()
        ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(0);

    docAuditAssertNoSecrets($event, [$plain]);
});

test('secure-link max_opens reached audits rejected', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-max.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);
    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');
    $plain = docAuditOpaqueToken($url);

    $this->get("/api/v1/secure-downloads/{$plain}")->assertOk();
    DB::table('api_v1_audit_events')->delete();

    $this->getJson("/api/v1/secure-downloads/{$plain}")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'SECURE_LINK_CONSUMED');

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_OPENED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('SECURE_LINK_CONSUMED')
        ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(1);
});

test('secure-link invalid token does not invent purpose-specific audit', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();

    $this->getJson('/api/v1/secure-downloads/'.str_repeat('ab', 32))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'SECURE_LINK_NOT_FOUND');

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

test('secure-link revoked audits rejected', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-rev.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);
    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');
    $plain = docAuditOpaqueToken($url);

    OtpSecureDownloadLink::query()->update(['revoked_at' => now()]);
    DB::table('api_v1_audit_events')->delete();

    $this->getJson("/api/v1/secure-downloads/{$plain}")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'SECURE_LINK_REVOKED');

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_OPENED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('SECURE_LINK_REVOKED')
        ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(0);
});

test('secure-link missing file audits rejected without consuming opens', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-missing.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);
    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');
    $plain = docAuditOpaqueToken($url);
    Storage::delete($path);
    DB::table('api_v1_audit_events')->delete();

    $this->getJson("/api/v1/secure-downloads/{$plain}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RESULT_NOT_READY');

    expect(ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_OPENED)
        ->where('outcome', AuditOutcome::REJECTED)
        ->count())->toBe(1)
        ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(0);
});

// ── Bearer downloads ─────────────────────────────────────────────────────

test('results bearer download audits succeeded', function () {
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-bearer-r.pdf';
    storeFakePdf($path, '%PDF-1.4 bearer results');
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $corr = 'corr-doc-bearer-results';

    $response = $this->get("/api/v1/orders/{$order->id}/results/download", array_merge(
        authHeaders($token),
        [AkubicaCorrelationId::HEADER => $corr],
    ))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="resultado-'.$order->id.'.pdf"');

    expect($response->getContent())->toBe('%PDF-1.4 bearer results');

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_DOWNLOADED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->http_status)->toBe(200)
        ->and($event->actor_type)->toBe('customer')
        ->and($event->customer_id)->toBe($user->customer->id)
        ->and($event->personal_access_token_id)->toBe($tokenId)
        ->and($event->correlation_id)->toBe($corr)
        ->and($event->resource_key)->toBe((string) $order->id)
        ->and($event->metadata['purpose'])->toBe('results');

    docAuditAssertNoSecrets($event, [$token, $path]);
});

test('invoices bearer download audits succeeded', function () {
    enableDocumentAccessAudit();
    [$user, $token] = docAuditCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order, 'invoices/audit-bearer-i.pdf');
    storeFakePdf('invoices/audit-bearer-i.pdf', '%PDF-1.4 bearer invoice');

    $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_INVOICES_DOWNLOADED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->resource_type)->toBe('invoice')
        ->and($event->resource_key)->toBe((string) $invoice->id)
        ->and($event->metadata['invoice_row_id'])->toBe($invoice->id);
});

test('bearer download missing step-up grant audits rejected', function () {
    enableBearerStepUpResultsEnforcement();
    enableDocumentAccessAudit();
    [$user, $token] = docAuditCustomerToken();
    $path = 'results/audit-stepup-req.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->getJson("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'STEP_UP_REQUIRED')
        ->assertJsonPath('error.retryable', false);

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_RESULTS_DOWNLOADED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('STEP_UP_REQUIRED')
        ->and($event->metadata['step_up_row_id'] ?? null)->toBeNull();
});

test('bearer download invalid expired and wrong-purpose grants audit rejected', function () {
    enableBearerStepUpResultsEnforcement();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-grants.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $headers = authHeaders($token);

    $this->getJson("/api/v1/orders/{$order->id}/results/download", array_merge($headers, [
        BearerStepUpEnforcement::HEADER => (string) Str::uuid(),
    ]))->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    expect(ApiV1AuditEvent::query()
        ->where('error_code', 'STEP_UP_GRANT_INVALID')->count())->toBe(1);

    DB::table('api_v1_audit_events')->delete();
    $expired = docAuditResultsGrant($user, $order->id, $tokenId, [
        'expires_at' => now()->subMinute(),
    ]);
    $this->getJson("/api/v1/orders/{$order->id}/results/download", array_merge($headers, [
        BearerStepUpEnforcement::HEADER => $expired->public_id,
    ]))->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_EXPIRED');

    expect(ApiV1AuditEvent::query()->where('error_code', 'STEP_UP_EXPIRED')->sole()->outcome)
        ->toBe(AuditOutcome::REJECTED);
    docAuditAssertNoSecrets(null, [$expired->public_id]);

    DB::table('api_v1_audit_events')->delete();
    $invoiceGrant = docAuditInvoicesGrant($user, 99999, $tokenId);
    $this->getJson("/api/v1/orders/{$order->id}/results/download", array_merge($headers, [
        BearerStepUpEnforcement::HEADER => $invoiceGrant->public_id,
    ]))->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    docAuditAssertNoSecrets(null, [$invoiceGrant->public_id]);
});

test('bearer download grant bound to other resource audits rejected without leaking grant public id', function () {
    enableBearerStepUpResultsEnforcement();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-bind.pdf';
    storeFakePdf($path);
    $orderA = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $orderB = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grantB = docAuditResultsGrant($user, $orderB->id, $tokenId);

    $this->getJson("/api/v1/orders/{$orderA->id}/results/download", array_merge(authHeaders($token), [
        BearerStepUpEnforcement::HEADER => $grantB->public_id,
    ]))->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $event = ApiV1AuditEvent::query()->sole();
    expect($event->error_code)->toBe('STEP_UP_GRANT_INVALID')
        ->and($event->resource_key)->toBe((string) $orderA->id)
        ->and($event->metadata['step_up_row_id'] ?? null)->toBeNull();

    docAuditAssertNoSecrets($event, [$grantB->public_id]);
});

test('bearer download cross-user omits foreign resource ids', function () {
    enableDocumentAccessAudit();
    [$userA, $tokenA] = docAuditCustomerToken();
    [$userB] = docAuditCustomerToken(['phone' => '5599999999', 'email' => 'b@ejemplo.com']);
    $path = 'results/audit-xuser.pdf';
    storeFakePdf($path);
    $orderB = createAkubicaLaboratoryPurchase($userB, ['results' => $path]);

    $this->getJson("/api/v1/orders/{$orderB->id}/results/download", authHeaders($tokenA))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

    $event = ApiV1AuditEvent::query()->sole();
    expect($event->resource_key)->toBeNull()
        ->and($event->metadata['laboratory_purchase_row_id'] ?? null)->toBeNull();

    docAuditAssertNoSecrets($event);
});

test('bearer download with valid step-up grant records step_up_row_id', function () {
    enableBearerStepUpResultsEnforcement();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-ok-grant.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);

    $this->get("/api/v1/orders/{$order->id}/results/download", array_merge(authHeaders($token), [
        BearerStepUpEnforcement::HEADER => $grant->public_id,
    ]))->assertOk();

    $event = ApiV1AuditEvent::query()->sole();
    expect($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->metadata['step_up_row_id'])->toBe($grant->id);

    docAuditAssertNoSecrets($event, [$grant->public_id, $token]);
});

test('bearer download missing file audits rejected', function () {
    enableDocumentAccessAudit();
    [$user, $token] = docAuditCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user, ['results' => 'results/gone.pdf']);

    $this->getJson("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RESULT_NOT_READY');

    expect(ApiV1AuditEvent::query()->where('error_code', 'RESULT_NOT_READY')->count())->toBe(1);
});

// ── Metadata normalizer / fail-soft ──────────────────────────────────────

test('document access metadata allowlist keeps internal ids and strips secrets', function () {
    enableDocumentAccessAudit();
    $normalizer = app(AuditMetadataNormalizer::class);

    $result = $normalizer->normalize(AuditEventDefinitions::EVENT_RESULTS_SECURE_LINK_CREATED, [
        'purpose' => 'results',
        'secure_link_row_id' => 42,
        'step_up_row_id' => 7,
        'laboratory_purchase_row_id' => 9,
        'ttl_minutes' => 5,
        'max_opens' => 1,
        'secure_link_token' => 'tokensecret',
        'url' => 'https://example.test/api/v1/secure-downloads/aabb',
        'grant_public_id' => (string) Str::uuid(),
        'X-Step-Up-Grant' => 'presented',
        'bearer' => 'secret',
        'token' => 'plain',
    ]);

    expect($result)->toBe([
        'purpose' => 'results',
        'secure_link_row_id' => 42,
        'step_up_row_id' => 7,
        'laboratory_purchase_row_id' => 9,
        'ttl_minutes' => 5,
        'max_opens' => 1,
    ]);
});

test('broken audit writer does not alter secure-link create response', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-broken-create.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);

    Schema::rename('api_v1_audit_events', 'api_v1_audit_events_broken');

    try {
        $this->postJson(
            "/api/v1/orders/{$order->id}/results/secure-link",
            ['grant_id' => $grant->public_id],
            authHeaders($token),
        )->assertCreated()
            ->assertJsonPath('data.max_opens', 1);

        expect(OtpSecureDownloadLink::query()->count())->toBe(1);
    } finally {
        if (Schema::hasTable('api_v1_audit_events_broken')) {
            Schema::rename('api_v1_audit_events_broken', 'api_v1_audit_events');
        }
    }
});

test('broken audit writer does not alter binary download headers or body', function () {
    enableDocumentAccessAudit();
    [$user, $token] = docAuditCustomerToken();
    $path = 'results/audit-broken-dl.pdf';
    $content = '%PDF-1.4 broken writer download';
    storeFakePdf($path, $content);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    Schema::rename('api_v1_audit_events', 'api_v1_audit_events_broken');

    try {
        $response = $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="resultado-'.$order->id.'.pdf"');

        expect($response->getContent())->toBe($content);
    } finally {
        if (Schema::hasTable('api_v1_audit_events_broken')) {
            Schema::rename('api_v1_audit_events_broken', 'api_v1_audit_events');
        }
    }
});

test('broken audit writer does not alter public secure-link open', function () {
    enableResultsSecureLinks();
    enableDocumentAccessAudit();
    [$user, $token, $tokenId] = docAuditCustomerToken();
    $path = 'results/audit-broken-open.pdf';
    $content = '%PDF-1.4 broken open';
    storeFakePdf($path, $content);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = docAuditResultsGrant($user, $order->id, $tokenId);
    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');
    $plain = docAuditOpaqueToken($url);

    Schema::rename('api_v1_audit_events', 'api_v1_audit_events_broken');

    try {
        $response = $this->get("/api/v1/secure-downloads/{$plain}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        expect($response->getContent())->toBe($content)
            ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(1);
    } finally {
        if (Schema::hasTable('api_v1_audit_events_broken')) {
            Schema::rename('api_v1_audit_events_broken', 'api_v1_audit_events');
        }
    }
});

test('unauthenticated bearer download has no audit before api.audit', function () {
    enableDocumentAccessAudit();

    $this->getJson('/api/v1/orders/1/results/download')
        ->assertUnauthorized();

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});
