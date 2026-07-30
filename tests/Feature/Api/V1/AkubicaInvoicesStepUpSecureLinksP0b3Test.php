<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\P0aOtpPurpose;
use App\Models\Invoice;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\StepUp\OtpStepUpGrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function enableAkubicaInvoiceStepUpFlags(): void
{
    config()->set('otp.p0a.flags.step_up_invoices_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
    config()->set('otp.p0a.flags.sms_delivery_enabled', true);
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    config()->set('otp.p0a.flags.akubica_login_enabled', false);
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'fake');
    config()->set('otp.p0a.policy.require_verified_phone', true);
    config()->set('otp.p0a.policy.max_attempts', 5);
    config()->set('otp.p0a.policy.ttl_minutes', 5);
    config()->set('otp.p0a.policy.cooldown_seconds', 60);
    config()->set('otp.p0a.step_up.bind_to_sanctum_token', true);
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
    app(FakeOtpDeliveryProvider::class)->sent = [];
}

function enableAkubicaInvoiceSecureLinkFlags(): void
{
    enableAkubicaInvoiceStepUpFlags();
    config()->set('otp.p0a.flags.secure_links_invoices_enabled', true);
    config()->set('otp.p0a.flags.secure_links_results_enabled', false);
    config()->set('otp.p0a.secure_links.ttl_minutes', 5);
    config()->set('otp.p0a.secure_links.max_opens', 1);
}

function disableAkubicaInvoiceP0b3Flags(): void
{
    config()->set('otp.p0a.flags.step_up_invoices_enabled', false);
    config()->set('otp.p0a.flags.secure_links_invoices_enabled', false);
    config()->set('otp.p0a.flags.secure_links_results_enabled', false);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);
    config()->set('otp.p0a.flags.sms_delivery_enabled', false);
    config()->set('otp.p0a.flags.akubica_login_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'null');
}

/**
 * @return array{0: User, 1: string, 2: int}
 */
function invoiceStepUpCustomerToken(array $userAttrs = []): array
{
    $user = User::factory()->withRegularCustomer()->create(array_merge([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ], $userAttrs));

    $newToken = $user->createToken('akubica-test');

    return [$user, $newToken->plainTextToken, (int) $newToken->accessToken->id];
}

function createInvoiceStepUpGrant(
    User $user,
    int $invoiceId,
    int $tokenId,
    array $overrides = [],
): OtpStepUpGrant {
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
        'meta' => ['order_id' => $overrides['meta_order_id'] ?? null],
    ]);

    unset($overrides['meta_order_id']);

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

function opaqueTokenFromUrl(string $url): string
{
    $token = basename(parse_url($url, PHP_URL_PATH) ?: '');
    expect($token)->toMatch('/^[A-Fa-f0-9]{64}$/');

    return $token;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 16:00:00'));
    Storage::fake();
    disableAkubicaInvoiceP0b3Flags();
});

afterEach(function () {
    Carbon::setTestNow();
    disableAkubicaInvoiceP0b3Flags();
});

// ── Step-up invoices ────────────────────────────────────────────────────

test('p0b3 flags off invoice step-up returns FEATURE_DISABLED', function () {
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    )->assertStatus(503)->assertJsonPath('error.code', 'FEATURE_DISABLED');
});

test('p0b3 owner requests invoice step-up and sms is sent', function () {
    enableAkubicaInvoiceStepUpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $response = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    )->assertStatus(202)
        ->assertJsonPath('data.purpose', 'step_up_invoices')
        ->assertJsonPath('data.resource_type', 'invoice')
        ->assertJsonPath('data.resource_id', $invoice->id);

    expect(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(1)
        ->and(OtpDeliveryOperation::query()->where('purpose', 'step_up_invoices')->count())->toBe(1)
        ->and(OtpChallenge::query()->where('purpose', 'step_up_invoices')->value('meta'))
        ->toMatchArray(['order_id' => $order->id]);

    expect(json_encode($response->json()))->not->toContain('123456')
        ->and(json_encode($response->json()))->not->toContain('5512345678');
});

test('p0b3 third party cannot request invoice step-up', function () {
    enableAkubicaInvoiceStepUpFlags();
    [$owner] = invoiceStepUpCustomerToken(['phone' => '5511111111']);
    [$stranger, $tokenB] = invoiceStepUpCustomerToken(['phone' => '5522222222']);
    $order = createAkubicaLaboratoryPurchase($owner);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($tokenB),
    )->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

    expect(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0)
        ->and(OtpChallenge::query()->count())->toBe(0);
});

test('p0b3 nonexistent and foreign order share ORDER_NOT_FOUND without sms', function () {
    enableAkubicaInvoiceStepUpFlags();
    [$owner] = invoiceStepUpCustomerToken(['phone' => '5533333333']);
    [$actor, $token] = invoiceStepUpCustomerToken(['phone' => '5544444444']);
    $order = createAkubicaLaboratoryPurchase($owner);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $a = $this->postJson('/api/v1/orders/999991/invoices/1/step-up/request', [], authHeaders($token));
    $b = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    );

    expect($a->status())->toBe(404)
        ->and($b->status())->toBe(404)
        ->and($a->json('error.code'))->toBe('ORDER_NOT_FOUND')
        ->and($b->json('error.code'))->toBe('ORDER_NOT_FOUND')
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0);
});

test('p0b3 nonexistent invoice and invoice of other order return INVOICE_NOT_FOUND', function () {
    enableAkubicaInvoiceStepUpFlags();
    [$user, $token] = invoiceStepUpCustomerToken();
    $orderA = createAkubicaLaboratoryPurchase($user);
    $orderB = createAkubicaLaboratoryPurchase($user);
    $invoiceB = createAkubicaLaboratoryInvoice($orderB);

    $this->postJson(
        "/api/v1/orders/{$orderA->id}/invoices/99999/step-up/request",
        [],
        authHeaders($token),
    )->assertNotFound()->assertJsonPath('error.code', 'INVOICE_NOT_FOUND');

    $this->postJson(
        "/api/v1/orders/{$orderA->id}/invoices/{$invoiceB->id}/step-up/request",
        [],
        authHeaders($token),
    )->assertNotFound()->assertJsonPath('error.code', 'INVOICE_NOT_FOUND');

    expect(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0);
});

test('p0b3 correct otp creates invoice grant', function () {
    enableAkubicaInvoiceStepUpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));
    [$user, $token, $tokenId] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $verify = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '654321'],
        authHeaders($token),
    )->assertOk()
        ->assertJsonPath('data.purpose', 'step_up_invoices')
        ->assertJsonPath('data.resource_type', 'invoice')
        ->assertJsonPath('data.resource_id', $invoice->id);

    $grant = OtpStepUpGrant::query()->where('public_id', $verify->json('data.grant_id'))->first();
    expect($grant)->not->toBeNull()
        ->and((int) $grant->personal_access_token_id)->toBe($tokenId)
        ->and((int) $grant->user_id)->toBe($user->id);
});

test('p0b3 wrong expired and consumed otp never create grants', function () {
    enableAkubicaInvoiceStepUpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '222222']));
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '000000'],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CODE');

    OtpChallenge::query()->where('public_id', $challengeId)->update(['expires_at' => now()->subMinute()]);
    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '111111'],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'CODE_EXPIRED');

    Carbon::setTestNow(now()->addMinutes(2));
    $challengeId2 = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
        ['challenge_id' => $challengeId2, 'code' => '222222'],
        authHeaders($token),
    )->assertOk();

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
        ['challenge_id' => $challengeId2, 'code' => '222222'],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'CODE_ALREADY_USED');

    expect(OtpStepUpGrant::query()->count())->toBe(1);
});

test('p0b3 cross purpose challenges cannot verify invoice step-up', function () {
    enableAkubicaInvoiceStepUpFlags();
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    foreach ([
        [P0aOtpPurpose::StepUpResults->value, 'laboratory_purchase', $order->id],
        [P0aOtpPurpose::AkubicaLogin->value, 'akubica_login', $user->id],
        [P0aOtpPurpose::AkubicaRegister->value, 'akubica_register', $user->id],
    ] as [$purpose, $contextType, $contextId]) {
        $challenge = OtpChallenge::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'subject_type' => 'phone',
            'subject_key' => 'MX|5512345678',
            'purpose' => $purpose,
            'channel' => 'sms',
            'destination_normalized' => '+525512345678',
            'destination_masked' => '***5678',
            'code_hash' => Hash::make('333333'),
            'expires_at' => now()->addMinutes(5),
            'failed_attempts' => 0,
            'max_attempts' => 5,
            'send_count' => 1,
            'last_sent_at' => now(),
            'context_type' => $contextType,
            'context_id' => $contextId,
        ]);

        $this->postJson(
            "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
            ['challenge_id' => $challenge->public_id, 'code' => '333333'],
            authHeaders($token),
        )->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CODE');
    }

    expect(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b3 challenge for another invoice cannot verify', function () {
    enableAkubicaInvoiceStepUpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('444444'));
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoiceA = createAkubicaLaboratoryInvoice($order);
    $invoiceB = createAkubicaLaboratoryInvoice($order, 'invoices/other.pdf');

    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoiceA->id}/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoiceB->id}/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '444444'],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CODE');
});

test('p0b3 request rejects client identity fields', function () {
    enableAkubicaInvoiceStepUpFlags();
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        ['phone' => '5587654321', 'user_id' => 9, 'purpose' => 'step_up_results'],
        authHeaders($token),
    )->assertUnprocessable();
});

test('p0b3 resend via request after cooldown invalidates previous challenge', function () {
    enableAkubicaInvoiceStepUpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '999999']));
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $firstId = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    )->assertStatus(429);

    Carbon::setTestNow(now()->addSeconds(60));
    $secondId = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        authHeaders($token),
    )->assertStatus(202)->json('data.challenge_id');

    expect($secondId)->not->toBe($firstId)
        ->and(OtpChallenge::query()->where('public_id', $firstId)->first()->status())
        ->toBe(OtpChallenge::STATUS_INVALIDATED);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
        ['challenge_id' => $firstId, 'code' => '111111'],
        authHeaders($token),
    )->assertUnprocessable();

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
        ['challenge_id' => $secondId, 'code' => '999999'],
        authHeaders($token),
    )->assertOk();
});

// ── Secure links invoices ───────────────────────────────────────────────

test('p0b3 owner with valid grant generates invoice secure link', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    [$user, $token, $tokenId] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $grant = createInvoiceStepUpGrant($user, $invoice->id, $tokenId, ['meta_order_id' => $order->id]);

    $data = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertCreated()->json('data');

    $plain = opaqueTokenFromUrl($data['url']);
    $link = OtpSecureDownloadLink::query()->first();
    expect($link->token_hash)->toBe(hash('sha256', $plain))
        ->and($link->purpose)->toBe('step_up_invoices')
        ->and($link->resource_type)->toBe('invoice')
        ->and((int) $link->resource_id)->toBe($invoice->id)
        ->and(json_encode($data))->not->toContain('token_hash')
        ->and(json_encode($data))->not->toContain($invoice->invoice);
});

test('p0b3 invalid grants cannot issue invoice secure link', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    [$user, $token, $tokenId] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => (string) Str::uuid()],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $expired = createInvoiceStepUpGrant($user, $invoice->id, $tokenId, [
        'expires_at' => now()->subMinute(),
        'meta_order_id' => $order->id,
    ]);
    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $expired->public_id],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $revoked = createInvoiceStepUpGrant($user, $invoice->id, $tokenId, [
        'revoked_at' => now(),
        'meta_order_id' => $order->id,
        'public_id' => (string) Str::uuid(),
    ]);
    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $revoked->public_id],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b3 results grant and other invoice grant cannot issue invoice link', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    [$user, $token, $tokenId] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoiceA = createAkubicaLaboratoryInvoice($order);
    $invoiceB = createAkubicaLaboratoryInvoice($order, 'invoices/b.pdf');

    $resultsGrant = createInvoiceStepUpGrant($user, $order->id, $tokenId, [
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'meta_order_id' => $order->id,
        'public_id' => (string) Str::uuid(),
    ]);
    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoiceA->id}/secure-link",
        ['grant_id' => $resultsGrant->public_id],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $otherInvoiceGrant = createInvoiceStepUpGrant($user, $invoiceB->id, $tokenId, [
        'meta_order_id' => $order->id,
        'public_id' => (string) Str::uuid(),
    ]);
    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoiceA->id}/secure-link",
        ['grant_id' => $otherInvoiceGrant->public_id],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b3 other user or PAT cannot use invoice grant', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    [$userA, , $tokenIdA] = invoiceStepUpCustomerToken(['phone' => '5555555555']);
    [$userB, $tokenB] = invoiceStepUpCustomerToken(['phone' => '5566666666']);
    $orderB = createAkubicaLaboratoryPurchase($userB);
    $invoiceB = createAkubicaLaboratoryInvoice($orderB);
    $grantA = createInvoiceStepUpGrant($userA, $invoiceB->id, $tokenIdA, ['meta_order_id' => $orderB->id]);

    $this->postJson(
        "/api/v1/orders/{$orderB->id}/invoices/{$invoiceB->id}/secure-link",
        ['grant_id' => $grantA->public_id],
        authHeaders($tokenB),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    [$user, $tokenA, $tokenIdA2] = invoiceStepUpCustomerToken(['phone' => '5577777777']);
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $grant = createInvoiceStepUpGrant($user, $invoice->id, $tokenIdA2, ['meta_order_id' => $order->id]);
    $otherPat = $user->createToken('other')->plainTextToken;

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        switchApiBearerToken($this, $otherPat),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    unset($tokenA);
});

test('p0b3 invoice without pdf does not create link', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    [$user, $token, $tokenId] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = Invoice::query()->create([
        'invoiceable_type' => \App\Models\LaboratoryPurchase::class,
        'invoiceable_id' => $order->id,
        'invoice' => 'invoices/missing-p0b3.pdf',
    ]);
    $grant = createInvoiceStepUpGrant($user, $invoice->id, $tokenId, ['meta_order_id' => $order->id]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_NOT_READY');

    expect(OtpSecureDownloadLink::query()->count())->toBe(0);
});

test('p0b3 invoice secure link downloads pdf without bearer then consumes', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    [$user, $token, $tokenId] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $grant = createInvoiceStepUpGrant($user, $invoice->id, $tokenId, ['meta_order_id' => $order->id]);

    $plain = opaqueTokenFromUrl($this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url'));

    $response = $this->get('/api/v1/secure-downloads/'.$plain);
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $cache = strtolower((string) $response->headers->get('Cache-Control'));
    expect($response->getContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('factura-'.$invoice->id.'.pdf')
        ->and($cache)->toContain('private')
        ->and($cache)->toContain('no-store');

    $this->getJson('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'SECURE_LINK_CONSUMED');
});

test('p0b3 invoice link expired revoked unknown and missing file behaviors', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    [$user, $token, $tokenId] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $grant = createInvoiceStepUpGrant($user, $invoice->id, $tokenId, ['meta_order_id' => $order->id]);

    $plain = opaqueTokenFromUrl($this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url'));

    OtpSecureDownloadLink::query()->update(['expires_at' => now()->subMinute()]);
    $this->getJson('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(410)->assertJsonPath('error.code', 'SECURE_LINK_EXPIRED');

    OtpSecureDownloadLink::query()->update([
        'expires_at' => now()->addMinutes(5),
        'revoked_at' => null,
        'open_count' => 0,
        'consumed_at' => null,
    ]);
    app(OtpStepUpGrantService::class)->revoke($grant->fresh());
    $this->getJson('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(410)->assertJsonPath('error.code', 'SECURE_LINK_REVOKED');

    $this->getJson('/api/v1/secure-downloads/'.str_repeat('ab', 32))
        ->assertNotFound()->assertJsonPath('error.code', 'SECURE_LINK_NOT_FOUND');

    // Fresh link + delete PDF → 409 without consume
    enableAkubicaInvoiceSecureLinkFlags();
    $grant2 = createInvoiceStepUpGrant($user, $invoice->id, $tokenId, [
        'meta_order_id' => $order->id,
        'public_id' => (string) Str::uuid(),
    ]);
    $plain2 = opaqueTokenFromUrl($this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $grant2->public_id],
        authHeaders($token),
    )->json('data.url'));
    Storage::delete($invoice->invoice);
    $this->getJson('/api/v1/secure-downloads/'.$plain2)
        ->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_NOT_READY');
    expect((int) OtpSecureDownloadLink::query()->where('token_hash', hash('sha256', $plain2))->value('open_count'))
        ->toBe(0);
});

test('p0b3 cross resource secure links do not mix documents', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    config()->set('otp.p0a.flags.secure_links_results_enabled', true);
    config()->set('otp.p0a.flags.step_up_results_enabled', true);

    [$user, $token, $tokenId] = invoiceStepUpCustomerToken();
    $path = 'results/p0b3-cross.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $resultsGrant = createInvoiceStepUpGrant($user, $order->id, $tokenId, [
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'meta_order_id' => $order->id,
        'public_id' => (string) Str::uuid(),
    ]);
    $invoiceGrant = createInvoiceStepUpGrant($user, $invoice->id, $tokenId, [
        'meta_order_id' => $order->id,
        'public_id' => (string) Str::uuid(),
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $invoiceGrant->public_id],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $resultsGrant->public_id],
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $resultsPlain = opaqueTokenFromUrl($this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $resultsGrant->public_id],
        authHeaders($token),
    )->assertCreated()->json('data.url'));

    $invoicePlain = opaqueTokenFromUrl($this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => $invoiceGrant->public_id],
        authHeaders($token),
    )->assertCreated()->json('data.url'));

    $resultsPdf = $this->get('/api/v1/secure-downloads/'.$resultsPlain);
    $invoicePdf = $this->get('/api/v1/secure-downloads/'.$invoicePlain);

    expect($resultsPdf->headers->get('Content-Disposition'))->toContain('resultado-'.$order->id.'.pdf')
        ->and($invoicePdf->headers->get('Content-Disposition'))->toContain('factura-'.$invoice->id.'.pdf');
});

test('p0b3 flags off keep bearer invoice download and block secure endpoints', function () {
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link",
        ['grant_id' => (string) Str::uuid()],
        authHeaders($token),
    )->assertStatus(503)->assertJsonPath('error.code', 'FEATURE_DISABLED');

    $this->getJson('/api/v1/secure-downloads/'.str_repeat('cd', 32))
        ->assertStatus(503)->assertJsonPath('error.code', 'FEATURE_DISABLED');
});

test('p0b3 invoice resource metadata additive when flags on', function () {
    enableAkubicaInvoiceSecureLinkFlags();
    [$user, $token] = invoiceStepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    createAkubicaLaboratoryInvoice($order);

    $this->getJson("/api/v1/orders/{$order->id}/invoices", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.invoices.0.requires_step_up', true)
        ->assertJsonPath('data.invoices.0.secure_link_supported', true)
        ->assertJsonPath('data.invoices.0.download.type', 'bearer');
});
