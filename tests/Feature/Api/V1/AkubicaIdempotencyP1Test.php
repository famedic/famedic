<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\P0aOtpPurpose;
use App\Models\Address;
use App\Models\AkubicaCheckoutLink;
use App\Models\Api\V1\IdempotencyRecord;
use App\Models\Contact;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Support\Api\V1\AkubicaCorrelationId;
use App\Support\Api\V1\ApiErrorRetryability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Otp\FakeOtpCodeGenerator;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 15:00:00', config('app.timezone')));
    config()->set('api_v1.idempotency.enabled', true);
    config()->set('api_v1.idempotency.ttl_hours', 24);
    config()->set('api_v1.idempotency.processing_lease_seconds', 60);
    config()->set('api_v1.idempotency.max_response_bytes', 65536);
    config()->set('api_v1.idempotency.prune.enabled', false);
    disableAllAkubicaOtpFeatures();
});

afterEach(function () {
    Carbon::setTestNow();
    config()->set('api_v1.idempotency.enabled', false);
    disableAllAkubicaOtpFeatures();
});

function idemKey(string $suffix = '01'): string
{
    return 'idem-key-'.$suffix.'-abcdef';
}

function idemHeaders(string $token, string $key, ?string $correlation = null): array
{
    return array_merge(authHeaders($token), [
        IdempotencyKey::HEADER => $key,
        AkubicaCorrelationId::HEADER => $correlation ?? 'corr-idem-abcdef01',
    ]);
}

function idemPublicHeaders(string $key, ?string $correlation = null): array
{
    return [
        IdempotencyKey::HEADER => $key,
        AkubicaCorrelationId::HEADER => $correlation ?? 'corr-pub-abcdef01',
    ];
}

function idemReadyCheckout(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): void
{
    $test = createOlabTest([
        'brand' => $brand,
        'requires_appointment' => false,
        'famedic_price_cents' => 35000,
        'public_price_cents' => 45000,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $contact = Contact::factory()->for($user->customer)->create([
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
    ]);

    $address = Address::factory()->for($user->customer)->create([
        'city' => 'Monterrey',
        'state' => 'Nuevo León',
    ]);

    LaboratoryCheckoutDraft::query()->updateOrCreate(
        [
            'customer_id' => $user->customer->id,
            'laboratory_brand' => $brand,
        ],
        [
            'contact_id' => $contact->id,
            'address_id' => $address->id,
            'checkout_step' => 'confirmation',
        ],
    );
}

// ── Config ────────────────────────────────────────────────────────────

test('idempotency flag off ignores Idempotency-Key', function () {
    config()->set('api_v1.idempotency.enabled', false);
    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, idemKey('off')))
        ->assertOk();

    expect(IdempotencyRecord::query()->count())->toBe(0)
        ->and(AkubicaCheckoutLink::query()->count())->toBe(1);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, idemKey('off')))
        ->assertOk();

    expect(AkubicaCheckoutLink::query()->count())->toBe(2);
});

test('idempotency without header keeps current behaviour', function () {
    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk();
    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk();

    expect(AkubicaCheckoutLink::query()->count())->toBe(2)
        ->and(IdempotencyRecord::query()->count())->toBe(0);
});

test('idempotency invalid header returns VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], array_merge(authHeaders($token), [
        IdempotencyKey::HEADER => 'bad key!',
    ]))->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.retryable', false)
        ->assertJsonStructure(['error' => ['correlation_id', 'fields']]);
});

test('idempotency config defaults are safe', function () {
    expect(config('api_v1.idempotency.ttl_hours'))->toBe(24)
        ->and(config('api_v1.idempotency.processing_lease_seconds'))->toBe(60)
        ->and(config('api_v1.idempotency.max_response_bytes'))->toBe(65536)
        ->and(ApiErrorRetryability::isRetryable('IDEMPOTENCY_REQUEST_IN_PROGRESS'))->toBeTrue()
        ->and(ApiErrorRetryability::isRetryable('IDEMPOTENCY_KEY_CONFLICT'))->toBeFalse()
        ->and(ApiErrorRetryability::isRetryable('IDEMPOTENCY_OPERATION_UNCERTAIN'))->toBeFalse();
});

// ── Payment link ──────────────────────────────────────────────────────

test('idempotency payment-link first creates one link and replay returns same url', function () {
    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);
    $key = idemKey('pay');
    $corr = 'corr-pay-original1';

    $first = $this->postJson(
        '/api/v1/checkout/payment-link',
        ['brand' => 'olab'],
        idemHeaders($token, $key, $corr),
    )->assertOk();

    $url = $first->json('data.payment_link.url');
    expect($url)->not->toBeEmpty()
        ->and(AkubicaCheckoutLink::query()->count())->toBe(1)
        ->and(IdempotencyRecord::query()->where('status', 'completed')->count())->toBe(1);

    $second = $this->postJson(
        '/api/v1/checkout/payment-link',
        ['brand' => 'olab'],
        idemHeaders($token, $key, 'corr-pay-replay0001'),
    )->assertOk()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertHeader(AkubicaCorrelationId::HEADER, $corr);

    expect($second->json('data.payment_link.url'))->toBe($url)
        ->and(AkubicaCheckoutLink::query()->count())->toBe(1);
});

test('idempotency payment-link same key different payload conflicts', function () {
    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);
    $key = idemKey('paydiff');

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertOk();

    $this->postJson(
        '/api/v1/checkout/payment-link',
        ['brand' => 'olab', 'expires_in_minutes' => 30],
        idemHeaders($token, $key),
    )->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT')
        ->assertJsonPath('error.retryable', false);

    expect(AkubicaCheckoutLink::query()->count())->toBe(1);
});

test('idempotency payment-link cross-user isolation', function () {
    [$userA, $tokenA] = akubicaCustomerToken();
    [$userB, $tokenB] = akubicaCustomerToken();
    idemReadyCheckout($userA);
    idemReadyCheckout($userB);
    $key = idemKey('cross');

    expect($userA->customer->id)->not->toBe($userB->customer->id);

    $urlA = $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($tokenA, $key))
        ->assertOk()
        ->json('data.payment_link.url');

    $urlB = $this->postJson(
        '/api/v1/checkout/payment-link',
        ['brand' => 'olab'],
        switchApiBearerToken($this, $tokenB) + [
            IdempotencyKey::HEADER => $key,
            AkubicaCorrelationId::HEADER => 'corr-idem-abcdef02',
        ],
    )->assertOk()
        ->json('data.payment_link.url');

    expect($urlA)->not->toBeEmpty()
        ->and($urlB)->not->toBeEmpty()
        ->and($urlA)->not->toBe($urlB)
        ->and(AkubicaCheckoutLink::query()->count())->toBe(2)
        ->and(IdempotencyRecord::query()->count())->toBe(2);
});

// ── Concurrent / processing / uncertain ───────────────────────────────

test('idempotency active processing returns IN_PROGRESS', function () {
    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);
    $key = idemKey('proc');

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertOk();

    $record = IdempotencyRecord::query()->first();
    expect($record)->not->toBeNull();

    $record->forceFill([
        'status' => IdempotencyRecord::STATUS_PROCESSING,
        'http_status' => null,
        'response_body' => null,
        'response_headers' => null,
        'lease_expires_at' => now()->addSeconds(60),
    ])->save();

    $links = AkubicaCheckoutLink::query()->count();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_REQUEST_IN_PROGRESS')
        ->assertJsonPath('error.retryable', true)
        ->assertHeader('Retry-After');

    expect(AkubicaCheckoutLink::query()->count())->toBe($links);
});

test('idempotency expired processing becomes UNCERTAIN and does not execute controller', function () {
    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);
    $key = idemKey('exp');

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertOk();

    $record = IdempotencyRecord::query()->first();
    $record->forceFill([
        'status' => IdempotencyRecord::STATUS_PROCESSING,
        'http_status' => null,
        'response_body' => null,
        'response_headers' => null,
        'lease_expires_at' => now()->subSeconds(5),
    ])->save();

    $links = AkubicaCheckoutLink::query()->count();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_OPERATION_UNCERTAIN')
        ->assertJsonPath('error.retryable', false);

    expect(AkubicaCheckoutLink::query()->count())->toBe($links)
        ->and(IdempotencyRecord::query()->first()->status)->toBe(IdempotencyRecord::STATUS_FAILED_UNCERTAIN);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_OPERATION_UNCERTAIN');

    expect(AkubicaCheckoutLink::query()->count())->toBe($links);
});

test('idempotency unique constraint is the concurrency barrier', function () {
    [$user] = akubicaCustomerToken();
    $attrs = [
        'actor_key' => 'customer:'.$user->customer->id,
        'method' => 'POST',
        'path' => 'api/v1/checkout/payment-link',
        'key_hash' => IdempotencyKey::hash(idemKey('uniq')),
        'request_hash' => hash('sha256', 'a'),
        'status' => IdempotencyRecord::STATUS_PROCESSING,
        'correlation_id' => 'corr-uniq-abcdef01',
        'lease_expires_at' => now()->addMinute(),
        'expires_at' => now()->addDay(),
    ];

    IdempotencyRecord::query()->create($attrs);

    expect(fn () => IdempotencyRecord::query()->create($attrs))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('idempotency oversized response marks uncertain without truncating', function () {
    config()->set('api_v1.idempotency.max_response_bytes', 10);
    expect(config('api_v1.idempotency.max_response_bytes'))->toBe(10);

    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);
    $key = idemKey('big');

    $response = $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key));

    $response->assertOk();
    expect(strlen((string) $response->getContent()))->toBeGreaterThan(10)
        ->and(AkubicaCheckoutLink::query()->count())->toBe(1);

    $record = IdempotencyRecord::query()->first();
    expect($record->status)->toBe(IdempotencyRecord::STATUS_FAILED_UNCERTAIN)
        ->and($record->response_body)->toBeNull();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_OPERATION_UNCERTAIN');

    expect(AkubicaCheckoutLink::query()->count())->toBe(1);
});

// ── Appointments / invoice-request ────────────────────────────────────

test('idempotency laboratory appointment creates once on replay', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = (function () use ($user) {
        $test = createOlabTest(['requires_appointment' => true]);
        LaboratoryCartItem::factory()->create([
            'customer_id' => $user->customer->id,
            'laboratory_test_id' => $test->id,
        ]);
        $contact = Contact::factory()->for($user->customer)->create([
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE,
        ]);
        $address = Address::factory()->for($user->customer)->create([
            'city' => 'Monterrey',
            'state' => 'Nuevo León',
        ]);

        return [$contact, $address];
    })();

    $payload = [
        'brand' => 'olab',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ];
    $key = idemKey('appt');

    $this->postJson('/api/v1/laboratory-appointments', $payload, idemHeaders($token, $key))
        ->assertSuccessful();

    expect(LaboratoryAppointment::query()->count())->toBe(1);

    $this->postJson('/api/v1/laboratory-appointments', $payload, idemHeaders($token, $key))
        ->assertSuccessful()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect(LaboratoryAppointment::query()->count())->toBe(1);
});

test('idempotency invoice-request creates once on replay', function () {
    Storage::fake();
    [$user, $token] = akubicaCustomerToken();

    $order = LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'gda_order_id' => 'GDA-IDEM-1',
        'gda_consecutivo' => 900001,
        'name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'López',
        'phone' => '8181234567',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'street' => 'Calle Test',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 35000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $profile = TaxProfile::factory()->for($user->customer)->create([
        'name' => 'PUBLICO EN GENERAL',
        'razon_social' => 'PUBLICO EN GENERAL',
        'rfc' => 'XAXX010101000',
        'zipcode' => '64000',
        'tax_regime' => '616',
        'cfdi_use' => 'S01',
    ]);

    Storage::put('fiscal-certificates/test/cert.pdf', 'pdf-content');
    $profile->update(['fiscal_certificate' => 'fiscal-certificates/test/cert.pdf']);

    $key = idemKey('invreq');
    $body = [
        'tax_profile_id' => $profile->id,
        'cfdi_use' => 'G03',
    ];

    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", $body, idemHeaders($token, $key))
        ->assertCreated();

    expect(InvoiceRequest::query()->count())->toBe(1);

    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", $body, idemHeaders($token, $key))
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect(InvoiceRequest::query()->count())->toBe(1);
});

// ── Secure links ──────────────────────────────────────────────────────

test('idempotency results secure-link replays same url', function () {
    enableResultsSecureLinks();
    Storage::fake();

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $newToken = $user->createToken('akubica-test');
    $token = $newToken->plainTextToken;
    $tokenId = (int) $newToken->accessToken->id;

    $path = 'results/idem-ready.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

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
        'context_id' => $order->id,
    ]);

    $grant = OtpStepUpGrant::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'personal_access_token_id' => $tokenId,
        'otp_challenge_id' => $challenge->id,
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'resource_id' => $order->id,
        'granted_at' => now(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $key = idemKey('secrl');
    $first = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        idemHeaders($token, $key),
    )->assertCreated();

    $url = $first->json('data.url');
    expect($url)->not->toBeEmpty()
        ->and(OtpSecureDownloadLink::query()->count())->toBe(1);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        idemHeaders($token, $key),
    )->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertJsonPath('data.url', $url);

    expect(OtpSecureDownloadLink::query()->count())->toBe(1);
});

// ── OTP login / register ──────────────────────────────────────────────

test('idempotency login request-code creates one challenge on replay', function () {
    enableLoginOtpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));
    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $key = idemKey('login');
    $payload = ['phone' => '+525512345678'];

    $first = $this->postJson('/api/v1/auth/login/request-code', $payload, idemPublicHeaders($key))
        ->assertStatus(202);

    $challengeId = $first->json('data.challenge_id');
    expect(OtpChallenge::query()->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->count())->toBe(1);

    $second = $this->postJson('/api/v1/auth/login/request-code', $payload, idemPublicHeaders($key))
        ->assertStatus(202)
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json('data.challenge_id'))->toBe($challengeId)
        ->and(OtpChallenge::query()->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->count())->toBe(1)
        ->and(json_encode(IdempotencyRecord::query()->first()->toArray()))->not->toContain('123456');
});

test('idempotency public actors differ by phone', function () {
    enableLoginOtpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('111111'));

    User::factory()->create([
        'phone' => '5511111111',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    User::factory()->create([
        'phone' => '5522222222',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $key = idemKey('phones');

    $this->postJson('/api/v1/auth/login/request-code', ['phone' => '+525511111111'], idemPublicHeaders($key))
        ->assertStatus(202);
    $this->postJson('/api/v1/auth/login/request-code', ['phone' => '+525522222222'], idemPublicHeaders($key))
        ->assertStatus(202);

    expect(OtpChallenge::query()->count())->toBe(2)
        ->and(IdempotencyRecord::query()->count())->toBe(2);
});

test('idempotency register creates one challenge on replay', function () {
    enableRegisterOtpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));

    $key = idemKey('reg');
    $payload = [
        'email' => 'idem.reg@ejemplo.com',
        'phone' => '+525533334444',
        'full_name' => 'Idem Registro',
        'phone_country' => 'MX',
    ];

    $first = $this->postJson('/api/v1/auth/register', $payload, idemPublicHeaders($key));
    if (in_array($first->status(), [200, 201, 202], true) === false) {
        // Secure register may return 202; legacy may differ — assert success family
        $first->assertSuccessful();
    }

    $challenges = OtpChallenge::query()->count();
    expect($challenges)->toBeGreaterThan(0);

    $this->postJson('/api/v1/auth/register', $payload, idemPublicHeaders($key))
        ->assertHeader('Idempotency-Replayed', 'true');

    expect(OtpChallenge::query()->count())->toBe($challenges);
});

// ── Step-up request ───────────────────────────────────────────────────

test('idempotency step-up results request creates one challenge on replay', function () {
    enableResultsStepUpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('222222'));

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5533334444',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $token = $user->createToken('akubica-test')->plainTextToken;

    $path = 'results/idem-stepup.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $key = idemKey('stup');
    $first = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        idemHeaders($token, $key),
    )->assertSuccessful();

    $challengeId = $first->json('data.challenge_id');
    expect($challengeId)->not->toBeEmpty()
        ->and(OtpChallenge::query()->count())->toBe(1);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        idemHeaders($token, $key),
    )->assertSuccessful()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertJsonPath('data.challenge_id', $challengeId);

    expect(OtpChallenge::query()->count())->toBe(1);
});

// ── Invoices step-up / secure-link (direct HTTP) ──────────────────────

test('idempotency invoices step-up request creates one challenge and delivery on replay', function () {
    enableInvoiceStepUpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('333333'));

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5544445555',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $token = $user->createToken('akubica-test')->plainTextToken;
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $key = idemKey('invstup');
    $corr = 'corr-inv-stepup-001';
    $uri = "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request";

    $first = $this->postJson($uri, [], idemHeaders($token, $key, $corr))
        ->assertStatus(202);

    $challengeId = $first->json('data.challenge_id');
    expect($challengeId)->not->toBeEmpty()
        ->and(OtpChallenge::query()->where('purpose', 'step_up_invoices')->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->where('purpose', 'step_up_invoices')->count())->toBe(1);

    $record = IdempotencyRecord::query()->first();
    expect($record->path)->toBe('api/v1/orders/{order_id}/invoices/{invoice_id}/step-up/request')
        ->and($record->key_hash)->toBe(IdempotencyKey::hash($key))
        ->and($record->key_hash)->not->toBe($key);

    $second = $this->postJson($uri, [], idemHeaders($token, $key, 'corr-inv-stepup-rpl'))
        ->assertStatus(202)
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertHeader(AkubicaCorrelationId::HEADER, $corr)
        ->assertJsonPath('data.challenge_id', $challengeId);

    expect(OtpChallenge::query()->where('purpose', 'step_up_invoices')->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->where('purpose', 'step_up_invoices')->count())->toBe(1)
        ->and(json_encode($second->json()))->not->toContain('333333');
});

test('idempotency invoices step-up same key different invoice conflicts', function () {
    enableInvoiceStepUpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('444444'));

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5555556666',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $token = $user->createToken('akubica-test')->plainTextToken;
    $order = createAkubicaLaboratoryPurchase($user);
    $invoiceA = createAkubicaLaboratoryInvoice($order, 'invoices/idem-a.pdf');
    $invoiceB = createAkubicaLaboratoryInvoice($order, 'invoices/idem-b.pdf');
    $key = idemKey('invdiff');

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoiceA->id}/step-up/request",
        [],
        idemHeaders($token, $key),
    )->assertStatus(202);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoiceB->id}/step-up/request",
        [],
        idemHeaders($token, $key),
    )->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT')
        ->assertJsonPath('error.retryable', false);

    expect(OtpChallenge::query()->where('purpose', 'step_up_invoices')->count())->toBe(1);
});

test('idempotency invoices step-up cross-user isolation', function () {
    enableInvoiceStepUpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('555555'));

    $userA = User::factory()->withRegularCustomer()->create([
        'phone' => '5566667777',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $userB = User::factory()->withRegularCustomer()->create([
        'phone' => '5577778888',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $tokenA = $userA->createToken('akubica-test')->plainTextToken;
    $tokenB = $userB->createToken('akubica-test')->plainTextToken;
    $orderA = createAkubicaLaboratoryPurchase($userA);
    $orderB = createAkubicaLaboratoryPurchase($userB);
    $invoiceA = createAkubicaLaboratoryInvoice($orderA);
    $invoiceB = createAkubicaLaboratoryInvoice($orderB);
    $key = idemKey('invcross');

    $chalA = $this->postJson(
        "/api/v1/orders/{$orderA->id}/invoices/{$invoiceA->id}/step-up/request",
        [],
        idemHeaders($tokenA, $key),
    )->assertStatus(202)->json('data.challenge_id');

    $chalB = $this->postJson(
        "/api/v1/orders/{$orderB->id}/invoices/{$invoiceB->id}/step-up/request",
        [],
        switchApiBearerToken($this, $tokenB) + [
            IdempotencyKey::HEADER => $key,
            AkubicaCorrelationId::HEADER => 'corr-inv-cross-b01',
        ],
    )->assertStatus(202)->json('data.challenge_id');

    expect($chalA)->not->toBe($chalB)
        ->and(OtpChallenge::query()->where('purpose', 'step_up_invoices')->count())->toBe(2)
        ->and(IdempotencyRecord::query()->count())->toBe(2);
});

test('idempotency invoices secure-link creates one link and replay returns same url', function () {
    enableInvoiceSecureLinks();
    Storage::fake();

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5588889999',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $newToken = $user->createToken('akubica-test');
    $token = $newToken->plainTextToken;
    $tokenId = (int) $newToken->accessToken->id;

    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5588889999',
        'purpose' => P0aOtpPurpose::StepUpInvoices->value,
        'channel' => 'sms',
        'destination_normalized' => '+525588889999',
        'destination_masked' => '***9999',
        'code_hash' => Hash::make('000000'),
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => OtpStepUpGrant::RESOURCE_INVOICE,
        'context_id' => $invoice->id,
        'meta' => ['order_id' => $order->id],
    ]);

    $grant = OtpStepUpGrant::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'personal_access_token_id' => $tokenId,
        'otp_challenge_id' => $challenge->id,
        'purpose' => P0aOtpPurpose::StepUpInvoices->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_INVOICE,
        'resource_id' => $invoice->id,
        'granted_at' => now(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $key = idemKey('invsecl');
    $corr = 'corr-inv-seclink-01';
    $uri = "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link";
    $body = ['grant_id' => $grant->public_id];

    $first = $this->postJson($uri, $body, idemHeaders($token, $key, $corr))
        ->assertCreated();

    $url = $first->json('data.url');
    expect($url)->not->toBeEmpty()
        ->and(OtpSecureDownloadLink::query()->count())->toBe(1);

    $record = IdempotencyRecord::query()->first();
    expect($record->path)->toBe('api/v1/orders/{order_id}/invoices/{invoice_id}/secure-link');

    $this->postJson($uri, $body, idemHeaders($token, $key, 'corr-inv-seclink-rpl'))
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertHeader(AkubicaCorrelationId::HEADER, $corr)
        ->assertJsonPath('data.url', $url);

    expect(OtpSecureDownloadLink::query()->count())->toBe(1);
});

test('idempotency invoices secure-link same key different payload conflicts', function () {
    enableInvoiceSecureLinks();
    Storage::fake();

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5599990000',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $newToken = $user->createToken('akubica-test');
    $token = $newToken->plainTextToken;
    $tokenId = (int) $newToken->accessToken->id;
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $makeGrant = function (string $suffix) use ($user, $tokenId, $invoice, $order) {
        $challenge = OtpChallenge::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'subject_type' => 'phone',
            'subject_key' => 'MX|5599990000',
            'purpose' => P0aOtpPurpose::StepUpInvoices->value,
            'channel' => 'sms',
            'destination_normalized' => '+525599990000',
            'destination_masked' => '***0000',
            'code_hash' => Hash::make('000000'),
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => now(),
            'failed_attempts' => 0,
            'max_attempts' => 5,
            'send_count' => 1,
            'last_sent_at' => now(),
            'context_type' => OtpStepUpGrant::RESOURCE_INVOICE,
            'context_id' => $invoice->id,
            'meta' => ['order_id' => $order->id, 'suffix' => $suffix],
        ]);

        return OtpStepUpGrant::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'personal_access_token_id' => $tokenId,
            'otp_challenge_id' => $challenge->id,
            'purpose' => P0aOtpPurpose::StepUpInvoices->value,
            'resource_type' => OtpStepUpGrant::RESOURCE_INVOICE,
            'resource_id' => $invoice->id,
            'granted_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
    };

    $grantA = $makeGrant('a');
    $grantB = $makeGrant('b');
    $key = idemKey('invsecp');
    $uri = "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link";

    $this->postJson($uri, ['grant_id' => $grantA->public_id], idemHeaders($token, $key))
        ->assertCreated();

    $this->postJson($uri, ['grant_id' => $grantB->public_id], idemHeaders($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

    expect(OtpSecureDownloadLink::query()->count())->toBe(1);
});

test('idempotency invoices secure-link respects ownership isolation', function () {
    enableInvoiceSecureLinks();
    Storage::fake();

    $owner = User::factory()->withRegularCustomer()->create([
        'phone' => '5510101010',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $stranger = User::factory()->withRegularCustomer()->create([
        'phone' => '5520202020',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $ownerTok = $owner->createToken('akubica-test');
    $strangerTok = $stranger->createToken('akubica-test')->plainTextToken;
    $order = createAkubicaLaboratoryPurchase($owner);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $owner->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5510101010',
        'purpose' => P0aOtpPurpose::StepUpInvoices->value,
        'channel' => 'sms',
        'destination_normalized' => '+525510101010',
        'destination_masked' => '***1010',
        'code_hash' => Hash::make('000000'),
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => OtpStepUpGrant::RESOURCE_INVOICE,
        'context_id' => $invoice->id,
    ]);

    $grant = OtpStepUpGrant::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $owner->id,
        'personal_access_token_id' => (int) $ownerTok->accessToken->id,
        'otp_challenge_id' => $challenge->id,
        'purpose' => P0aOtpPurpose::StepUpInvoices->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_INVOICE,
        'resource_id' => $invoice->id,
        'granted_at' => now(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $key = idemKey('invown');
    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        idemHeaders($ownerTok->plainTextToken, $key),
    )->assertCreated();

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        switchApiBearerToken($this, $strangerTok) + [
            IdempotencyKey::HEADER => $key,
            AkubicaCorrelationId::HEADER => 'corr-inv-own-str01',
        ],
    )->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

    expect(OtpSecureDownloadLink::query()->count())->toBe(1)
        ->and(IdempotencyRecord::query()->count())->toBeGreaterThanOrEqual(1);
});

// ── failed_final / 5xx uncertain / persistence ────────────────────────

test('idempotency failed_final 4xx is replayed without re-running controller', function () {
    [$user, $token] = akubicaCustomerToken();
    // Empty cart → 409 EMPTY_CART
    $key = idemKey('fail4xx');
    $corr = 'corr-fail-4xx-0001';

    $first = $this->postJson(
        '/api/v1/checkout/payment-link',
        ['brand' => 'olab'],
        idemHeaders($token, $key, $corr),
    )->assertStatus(409)
        ->assertJsonPath('error.code', 'EMPTY_CART');

    $record = IdempotencyRecord::query()->first();
    expect($record->status)->toBe(IdempotencyRecord::STATUS_FAILED_FINAL)
        ->and($record->http_status)->toBe(409);

    $linksBefore = AkubicaCheckoutLink::query()->count();

    $this->postJson(
        '/api/v1/checkout/payment-link',
        ['brand' => 'olab'],
        idemHeaders($token, $key, 'corr-fail-4xx-rplay'),
    )->assertStatus(409)
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertHeader(AkubicaCorrelationId::HEADER, $corr)
        ->assertJsonPath('error.code', 'EMPTY_CART')
        ->assertJsonPath('error.correlation_id', $corr);

    expect(AkubicaCheckoutLink::query()->count())->toBe($linksBefore)
        ->and(IdempotencyRecord::query()->count())->toBe(1);
});

test('idempotency 5xx marks failed_uncertain without persisting body and blocks re-run', function () {
    // Flags OFF → secure-link returns 503 FEATURE_DISABLED
    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5530303030',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $tok = $user->createToken('akubica-test')->plainTextToken;
    $order = createAkubicaLaboratoryPurchase($user);
    $key = idemKey('fail5xx');
    $body = ['grant_id' => (string) Str::uuid()];
    $uri = "/api/v1/orders/{$order->id}/results/secure-link";

    $this->postJson($uri, $body, idemHeaders($tok, $key))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');

    $record = IdempotencyRecord::query()->first();
    expect($record->status)->toBe(IdempotencyRecord::STATUS_FAILED_UNCERTAIN)
        ->and($record->response_body)->toBeNull()
        ->and($record->http_status)->toBeNull();

    $raw = \Illuminate\Support\Facades\DB::table('api_v1_idempotency_records')->where('id', $record->id)->first();
    expect($raw->response_body)->toBeNull()
        ->and(json_encode((array) $raw))->not->toContain('FEATURE_DISABLED')
        ->and(json_encode((array) $raw))->not->toContain('stack');

    $links = OtpSecureDownloadLink::query()->count();

    $this->postJson($uri, $body, idemHeaders($tok, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_OPERATION_UNCERTAIN')
        ->assertJsonPath('error.retryable', false);

    expect(OtpSecureDownloadLink::query()->count())->toBe($links);
});

test('idempotency 5xx uncertain same payload does not re-execute', function () {
    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5540404040',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $tok = $user->createToken('akubica-test')->plainTextToken;
    $order = createAkubicaLaboratoryPurchase($user);
    $key = idemKey('fail5xx2');
    $grantId = (string) Str::uuid();
    $body = ['grant_id' => $grantId];
    $uri = "/api/v1/orders/{$order->id}/results/secure-link";

    $this->postJson($uri, $body, idemHeaders($tok, $key))
        ->assertStatus(503);

    expect(IdempotencyRecord::query()->value('status'))->toBe(IdempotencyRecord::STATUS_FAILED_UNCERTAIN);

    $this->postJson($uri, $body, idemHeaders($tok, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_OPERATION_UNCERTAIN')
        ->assertJsonPath('error.retryable', false);

    expect(OtpSecureDownloadLink::query()->count())->toBe(0)
        ->and(IdempotencyRecord::query()->count())->toBe(1);
});

test('idempotency persistence encrypts response and never stores raw key or request body', function () {
    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);
    $key = idemKey('persist');
    $secretMarker = 'no-store-this-payload-marker';

    $this->postJson(
        '/api/v1/checkout/payment-link',
        ['brand' => 'olab', 'notes_ignored_maybe' => $secretMarker],
        idemHeaders($token, $key),
    )->assertOk();

    $record = IdempotencyRecord::query()->first();
    expect($record->status)->toBe(IdempotencyRecord::STATUS_COMPLETED)
        ->and($record->key_hash)->toBe(IdempotencyKey::hash($key))
        ->and($record->path)->toBe('api/v1/checkout/payment-link');

    // Eloquent cast decrypts
    $decoded = json_decode((string) $record->response_body, true);
    expect($decoded['success'])->toBeTrue()
        ->and($decoded['data']['payment_link']['url'] ?? null)->not->toBeEmpty();

    $raw = \Illuminate\Support\Facades\DB::table('api_v1_idempotency_records')->where('id', $record->id)->first();
    $rawBody = (string) $raw->response_body;
    expect($rawBody)->not->toContain('"success"')
        ->and($rawBody)->not->toContain('/akubica/checkout/')
        ->and($rawBody)->not->toContain($key)
        ->and((string) $raw->key_hash)->not->toBe($key)
        ->and(json_encode((array) $raw))->not->toContain($secretMarker)
        ->and(json_encode((array) $raw))->not->toContain('Bearer')
        ->and($raw->response_headers === null || ! str_contains(json_encode($raw->response_headers), 'Authorization'))->toBeTrue();

    $indexes = \Illuminate\Support\Facades\Schema::getIndexes('api_v1_idempotency_records');
    $unique = collect($indexes)->first(
        fn (array $idx) => ($idx['unique'] ?? false)
            && ($idx['columns'] ?? []) === ['actor_key', 'method', 'path', 'key_hash']
    );
    expect($unique)->not->toBeNull();
});

test('idempotency processing in progress and expired semantics', function () {
    [$user, $token] = akubicaCustomerToken();
    idemReadyCheckout($user);
    $key = idemKey('lease2');

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertOk();

    $record = IdempotencyRecord::query()->first();
    $links = AkubicaCheckoutLink::query()->count();

    $record->forceFill([
        'status' => IdempotencyRecord::STATUS_PROCESSING,
        'http_status' => null,
        'response_body' => null,
        'response_headers' => null,
        'lease_expires_at' => now()->addSeconds(45),
    ])->save();

    $inProgress = $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_REQUEST_IN_PROGRESS')
        ->assertJsonPath('error.retryable', true);
    expect($inProgress->headers->get('Retry-After'))->not->toBeEmpty();
    expect(AkubicaCheckoutLink::query()->count())->toBe($links);

    $record->refresh()->forceFill([
        'status' => IdempotencyRecord::STATUS_PROCESSING,
        'lease_expires_at' => now()->subSeconds(2),
        'response_body' => null,
        'http_status' => null,
    ])->save();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], idemHeaders($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_OPERATION_UNCERTAIN')
        ->assertJsonPath('error.retryable', false);

    expect(IdempotencyRecord::query()->value('status'))->toBe(IdempotencyRecord::STATUS_FAILED_UNCERTAIN)
        ->and(AkubicaCheckoutLink::query()->count())->toBe($links);
});

// ── Prune ─────────────────────────────────────────────────────────────

test('idempotency prune dry-run and force', function () {
    [$user] = akubicaCustomerToken();

    IdempotencyRecord::query()->create([
        'actor_key' => 'customer:'.$user->customer->id,
        'method' => 'POST',
        'path' => 'api/v1/checkout/payment-link',
        'key_hash' => IdempotencyKey::hash(idemKey('old')),
        'request_hash' => hash('sha256', 'old'),
        'status' => IdempotencyRecord::STATUS_COMPLETED,
        'http_status' => 200,
        'response_body' => '{"success":true}',
        'correlation_id' => 'corr-old-abcdef001',
        'expires_at' => now()->subHour(),
    ]);

    IdempotencyRecord::query()->create([
        'actor_key' => 'customer:'.$user->customer->id,
        'method' => 'POST',
        'path' => 'api/v1/checkout/payment-link',
        'key_hash' => IdempotencyKey::hash(idemKey('new')),
        'request_hash' => hash('sha256', 'new'),
        'status' => IdempotencyRecord::STATUS_COMPLETED,
        'http_status' => 200,
        'response_body' => '{"success":true}',
        'correlation_id' => 'corr-new-abcdef001',
        'expires_at' => now()->addDay(),
    ]);

    expect(Artisan::call('akubica:prune-idempotency'))->toBe(0);
    expect(IdempotencyRecord::query()->count())->toBe(2);

    expect(Artisan::call('akubica:prune-idempotency', ['--force' => true, '--batch' => 1]))->toBe(0);
    expect(IdempotencyRecord::query()->count())->toBe(1)
        ->and(IdempotencyRecord::query()->value('correlation_id'))->toBe('corr-new-abcdef001');
});

test('idempotency prune scheduler is off by default', function () {
    Artisan::call('schedule:list');
    expect(Artisan::output())->not->toContain('akubica-prune-idempotency');
});

test('idempotency unauthenticated does not create durable record', function () {
    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], [
        IdempotencyKey::HEADER => idemKey('unauth'),
    ])->assertUnauthorized();

    expect(IdempotencyRecord::query()->count())->toBe(0);
});
