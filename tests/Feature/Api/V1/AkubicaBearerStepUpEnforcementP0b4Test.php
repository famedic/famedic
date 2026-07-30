<?php

use App\Enums\P0aOtpPurpose;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\OtpChallenge;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Services\Otp\StepUp\BearerStepUpEnforcement;
use App\Services\Otp\StepUp\OtpSecureDownloadLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @return array{0: User, 1: string, 2: int}
 */
function p0b4CustomerToken(array $userAttrs = []): array
{
    $user = User::factory()->withRegularCustomer()->create(array_merge([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ], $userAttrs));

    $newToken = $user->createToken('akubica-test');

    return [$user, $newToken->plainTextToken, (int) $newToken->accessToken->id];
}

function p0b4StepUpHeaders(string $bearer, ?string $grantId = null): array
{
    $headers = authHeaders($bearer);
    if ($grantId !== null) {
        $headers[BearerStepUpEnforcement::HEADER] = $grantId;
    }

    return $headers;
}

function p0b4CreateGrant(
    User $user,
    int $tokenId,
    string $purpose,
    string $resourceType,
    int $resourceId,
    array $overrides = [],
): OtpStepUpGrant {
    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5512345678',
        'purpose' => $purpose,
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
        'context_type' => $resourceType,
        'context_id' => $resourceId,
    ]);

    return OtpStepUpGrant::query()->create(array_merge([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'personal_access_token_id' => $tokenId,
        'otp_challenge_id' => $challenge->id,
        'purpose' => $purpose,
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'granted_at' => now(),
        'expires_at' => now()->addMinutes(10),
        'revoked_at' => null,
    ], $overrides));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 15:00:00'));
    Storage::fake();
    disableAllAkubicaOtpFeatures();
});

afterEach(function () {
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
});

// ── Results Bearer ──────────────────────────────────────────────────────

test('p0b4 flag off allows result bearer download without grant', function () {
    [$user, $token] = p0b4CustomerToken();
    $path = 'results/p0b4-off.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->get("/api/v1/orders/{$order->id}/results/download", p0b4StepUpHeaders($token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('p0b4 results enforcement without header returns STEP_UP_REQUIRED', function () {
    enableBearerStepUpResultsEnforcement();
    [$user, $token] = p0b4CustomerToken();
    $path = 'results/p0b4-req.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->getJson("/api/v1/orders/{$order->id}/results/download", p0b4StepUpHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
});

test('p0b4 valid results grant allows bearer download and is reusable', function () {
    enableBearerStepUpResultsEnforcement();
    [$user, $token, $tokenId] = p0b4CustomerToken();
    $path = 'results/p0b4-ok.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $order->id,
    );

    $headers = p0b4StepUpHeaders($token, $grant->public_id);

    $response = $this->get("/api/v1/orders/{$order->id}/results/download", $headers);
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    assertExactCacheControlDirectives($response, [
        'private',
        'no-store',
        'no-cache',
        'must-revalidate',
    ]);

    // Grant not consumed — second download still works.
    $this->get("/api/v1/orders/{$order->id}/results/download", $headers)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($grant->fresh()->revoked_at)->toBeNull()
        ->and($grant->fresh()->isActive())->toBeTrue();
});

test('p0b4 results grant nonexistent expired revoked cross purpose and wrong binding fail', function () {
    enableBearerStepUpResultsEnforcement();
    [$user, $token, $tokenId] = p0b4CustomerToken();
    $path = 'results/p0b4-bad.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $otherOrder = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->getJson(
        "/api/v1/orders/{$order->id}/results/download",
        p0b4StepUpHeaders($token, (string) Str::uuid()),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $expired = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $order->id,
        ['expires_at' => now()->subMinute()],
    );
    $this->getJson(
        "/api/v1/orders/{$order->id}/results/download",
        p0b4StepUpHeaders($token, $expired->public_id),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_EXPIRED');

    $revoked = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $order->id,
        ['revoked_at' => now()],
    );
    $this->getJson(
        "/api/v1/orders/{$order->id}/results/download",
        p0b4StepUpHeaders($token, $revoked->public_id),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_REVOKED');

    $invoiceGrant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpInvoices->value,
        OtpStepUpGrant::RESOURCE_INVOICE,
        999,
    );
    $this->getJson(
        "/api/v1/orders/{$order->id}/results/download",
        p0b4StepUpHeaders($token, $invoiceGrant->public_id),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $otherResource = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $otherOrder->id,
    );
    $this->getJson(
        "/api/v1/orders/{$order->id}/results/download",
        p0b4StepUpHeaders($token, $otherResource->public_id),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b4 results grant from other user or other PAT fails', function () {
    enableBearerStepUpResultsEnforcement();
    [$owner, $ownerToken, $ownerPat] = p0b4CustomerToken(['phone' => '5511111111']);
    [$other, $otherToken, $otherPat] = p0b4CustomerToken(['phone' => '5522222222']);
    $path = 'results/p0b4-pat.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($owner, ['results' => $path]);

    $ownerGrant = p0b4CreateGrant(
        $owner,
        $ownerPat,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $order->id,
    );

    $this->switchApiBearerToken($otherToken);
    $this->getJson(
        "/api/v1/orders/{$order->id}/results/download",
        p0b4StepUpHeaders($otherToken, $ownerGrant->public_id),
    )->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

    $otherPatGrant = p0b4CreateGrant(
        $owner,
        $otherPat,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $order->id,
    );
    $this->switchApiBearerToken($ownerToken);
    $this->getJson(
        "/api/v1/orders/{$order->id}/results/download",
        p0b4StepUpHeaders($ownerToken, $otherPatGrant->public_id),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b4 results not ready still 409 after valid grant', function () {
    enableBearerStepUpResultsEnforcement();
    [$user, $token, $tokenId] = p0b4CustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $grant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $order->id,
    );

    $this->getJson(
        "/api/v1/orders/{$order->id}/results/download",
        p0b4StepUpHeaders($token, $grant->public_id),
    )->assertStatus(409)->assertJsonPath('error.code', 'RESULT_NOT_READY');
});

// ── Invoices Bearer ─────────────────────────────────────────────────────

test('p0b4 flag off allows invoice bearer download without grant', function () {
    [$user, $token] = p0b4CustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", p0b4StepUpHeaders($token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('p0b4 invoices enforcement without header returns STEP_UP_REQUIRED', function () {
    enableBearerStepUpInvoicesEnforcement();
    [$user, $token] = p0b4CustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->getJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download",
        p0b4StepUpHeaders($token),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
});

test('p0b4 valid invoice grant allows download; results grant and other invoice fail', function () {
    enableBearerStepUpInvoicesEnforcement();
    [$user, $token, $tokenId] = p0b4CustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $otherInvoice = createAkubicaLaboratoryInvoice($order, 'invoices/p0b4-other.pdf');

    $grant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpInvoices->value,
        OtpStepUpGrant::RESOURCE_INVOICE,
        (int) $invoice->id,
    );

    $this->get(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download",
        p0b4StepUpHeaders($token, $grant->public_id),
    )->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $resultsGrant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $order->id,
    );
    $this->getJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download",
        p0b4StepUpHeaders($token, $resultsGrant->public_id),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    $otherGrant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpInvoices->value,
        OtpStepUpGrant::RESOURCE_INVOICE,
        (int) $otherInvoice->id,
    );
    $this->getJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download",
        p0b4StepUpHeaders($token, $otherGrant->public_id),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b4 invoice of other order returns 404 before grant evaluation', function () {
    enableBearerStepUpInvoicesEnforcement();
    [$user, $token, $tokenId] = p0b4CustomerToken();
    $orderA = createAkubicaLaboratoryPurchase($user);
    $orderB = createAkubicaLaboratoryPurchase($user);
    $invoiceB = createAkubicaLaboratoryInvoice($orderB);
    $grant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpInvoices->value,
        OtpStepUpGrant::RESOURCE_INVOICE,
        (int) $invoiceB->id,
    );

    $this->getJson(
        "/api/v1/orders/{$orderA->id}/invoices/{$invoiceB->id}/download",
        p0b4StepUpHeaders($token, $grant->public_id),
    )->assertNotFound()->assertJsonPath('error.code', 'INVOICE_NOT_FOUND');
});

test('p0b4 invoice without pdf returns 409 after valid grant', function () {
    enableBearerStepUpInvoicesEnforcement();
    [$user, $token, $tokenId] = p0b4CustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = Invoice::query()->create([
        'invoiceable_type' => LaboratoryPurchase::class,
        'invoiceable_id' => $order->id,
        'invoice' => 'invoices/missing-p0b4.pdf',
    ]);
    $grant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpInvoices->value,
        OtpStepUpGrant::RESOURCE_INVOICE,
        (int) $invoice->id,
    );

    $this->getJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download",
        p0b4StepUpHeaders($token, $grant->public_id),
    )->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_NOT_READY');
});

// ── Cross-resource + flags + metadata ───────────────────────────────────

test('p0b4 master flag enforces both resources; split flags are independent', function () {
    [$user, $token, $tokenId] = p0b4CustomerToken();
    $path = 'results/p0b4-master.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $invoice = createAkubicaLaboratoryInvoice($order);

    enableBearerStepUpMasterEnforcement();
    $this->getJson("/api/v1/orders/{$order->id}/results/download", p0b4StepUpHeaders($token))
        ->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
    $this->getJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download",
        p0b4StepUpHeaders($token),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_REQUIRED');

    disableAllAkubicaOtpFeatures();
    enableBearerStepUpResultsEnforcement();
    $this->getJson("/api/v1/orders/{$order->id}/results/download", p0b4StepUpHeaders($token))
        ->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
    $this->get(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download",
        p0b4StepUpHeaders($token),
    )->assertOk();

    disableAllAkubicaOtpFeatures();
    enableBearerStepUpInvoicesEnforcement();
    $this->get("/api/v1/orders/{$order->id}/results/download", p0b4StepUpHeaders($token))
        ->assertOk();
    $this->getJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download",
        p0b4StepUpHeaders($token),
    )->assertForbidden()->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
});

test('p0b4 metadata nests requires_step_up under download when enforcement on', function () {
    enableBearerStepUpResultsEnforcement();
    enableBearerStepUpInvoicesEnforcement();
    [$user, $token] = p0b4CustomerToken();
    $path = 'results/p0b4-meta.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->getJson("/api/v1/orders/{$order->id}/results", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.download.type', 'bearer')
        ->assertJsonPath('data.download.requires_step_up', true)
        ->assertJsonPath('data.download.url', fn ($url) => is_string($url) && $url !== '');

    $this->getJson("/api/v1/orders/{$order->id}/invoices", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.invoices.0.download.type', 'bearer')
        ->assertJsonPath('data.invoices.0.download.requires_step_up', true);
});

test('p0b4 secure links still work without X-Step-Up-Grant when bearer enforcement on', function () {
    enableBearerStepUpResultsEnforcement();
    enableResultsSecureLinks();

    [$user, $token, $tokenId] = p0b4CustomerToken();
    $path = 'results/p0b4-secure.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = p0b4CreateGrant(
        $user,
        $tokenId,
        P0aOtpPurpose::StepUpResults->value,
        OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        (int) $order->id,
    );

    $payload = app(OtpSecureDownloadLinkService::class)->issueResultsLink(
        $user,
        $order,
        $grant->public_id,
        $tokenId,
    );

    expect($payload)->toHaveKeys(['url', 'expires_at', 'max_opens']);

    $this->get($payload['url'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(OtpSecureDownloadLink::query()->count())->toBe(1);
});
