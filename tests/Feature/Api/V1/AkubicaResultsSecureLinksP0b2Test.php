<?php

use App\Enums\P0aOtpPurpose;
use App\Models\OtpChallenge;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Services\Otp\StepUp\OtpStepUpGrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function enableAkubicaSecureLinksResultsFlags(): void
{
    enableResultsSecureLinks();
}

function disableAkubicaSecureLinksResultsFlags(): void
{
    disableAllAkubicaOtpFeatures();
}

/**
 * @return array{0: User, 1: string, 2: int}
 */
function secureLinkCustomerToken(array $userAttrs = []): array
{
    $user = User::factory()->withRegularCustomer()->create(array_merge([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ], $userAttrs));

    $newToken = $user->createToken('akubica-test');

    return [$user, $newToken->plainTextToken, (int) $newToken->accessToken->id];
}

function createStepUpResultsGrant(
    User $user,
    int $orderId,
    int $tokenId,
    array $overrides = [],
): OtpStepUpGrant {
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

function opaqueTokenFromSecureUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $token = basename($path);
    expect($token)->toMatch('/^[A-Fa-f0-9]{64}$/');

    return $token;
}

function issueResultsSecureLink(
    \Tests\TestCase $testCase,
    User $user,
    string $token,
    int $tokenId,
    string $path,
    int $maxOpens = 1,
): array
{
    enableAkubicaSecureLinksResultsFlags();
    config()->set('otp.p0a.secure_links.max_opens', $maxOpens);
    storeFakePdf($path, '%PDF-1.4 secure-link-content');
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);

    $url = $testCase->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertCreated()
        ->assertJsonPath('data.max_opens', $maxOpens)
        ->json('data.url');

    return [$order, $grant, opaqueTokenFromSecureUrl($url)];
}

function fixedThrottleUserKey(User $user): string
{
    return sha1((string) $user->id);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 15:00:00'));
    Storage::fake();
    disableAkubicaSecureLinksResultsFlags();
});

afterEach(function () {
    Carbon::setTestNow();
    disableAkubicaSecureLinksResultsFlags();
});

test('p0b2 flags off secure-link issue returns FEATURE_DISABLED', function () {
    [$user, $token] = secureLinkCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => (string) Str::uuid()],
        authHeaders($token),
    )->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});

test('p0b2 flags off public secure download returns FEATURE_DISABLED', function () {
    $this->getJson('/api/v1/secure-downloads/'.str_repeat('ab', 32))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});

test('p0b2 flags off leave bearer result download unchanged', function () {
    [$user, $token] = secureLinkCustomerToken();
    $path = 'results/p0b2-bearer.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('p0b2 owner with valid grant generates secure link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-ready.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);

    $response = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertCreated()
        ->assertJsonPath('data.max_opens', 1);

    $data = $response->json('data');
    expect($data)->toHaveKeys(['url', 'expires_at', 'max_opens'])
        ->and($data)->not->toHaveKey('token_hash')
        ->and($data)->not->toHaveKey('grant_id')
        ->and(json_encode($data))->not->toContain($path)
        ->and($data['expires_at'])->toBe(now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'));

    $plain = opaqueTokenFromSecureUrl($data['url']);
    $link = OtpSecureDownloadLink::query()->first();
    expect($link)->not->toBeNull()
        ->and($link->token_hash)->toBe(hash('sha256', $plain))
        ->and($link->token_hash)->not->toBe($plain)
        ->and((int) $link->otp_step_up_grant_id)->toBe($grant->id)
        ->and((int) $link->user_id)->toBe($user->id)
        ->and((int) $link->resource_id)->toBe($order->id);
});

test('p0b2 third party cannot generate secure link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$owner, , $ownerTokenId] = secureLinkCustomerToken(['phone' => '5511111111']);
    [$stranger, $tokenB] = secureLinkCustomerToken(['phone' => '5522222222']);
    $path = 'results/p0b2-foreign.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($owner, ['results' => $path]);
    $grant = createStepUpResultsGrant($owner, $order->id, $ownerTokenId);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($tokenB),
    )->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

    expect(OtpSecureDownloadLink::query()->count())->toBe(0);
});

test('p0b2 nonexistent and foreign order share ORDER_NOT_FOUND without links', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$owner, , $ownerTokenId] = secureLinkCustomerToken(['phone' => '5533333333']);
    [$actor, $actorToken] = secureLinkCustomerToken(['phone' => '5544444444']);
    $path = 'results/p0b2-same404.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($owner, ['results' => $path]);
    $grant = createStepUpResultsGrant($owner, $order->id, $ownerTokenId);

    $missing = $this->postJson(
        '/api/v1/orders/999991/results/secure-link',
        ['grant_id' => $grant->public_id],
        authHeaders($actorToken),
    )->assertNotFound();

    $foreign = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($actorToken),
    )->assertNotFound();

    expect($missing->json('error.code'))->toBe('ORDER_NOT_FOUND')
        ->and($foreign->json('error.code'))->toBe('ORDER_NOT_FOUND')
        ->and(array_keys($missing->json('error')))->toEqualCanonicalizing(array_keys($foreign->json('error')))
        ->and(OtpSecureDownloadLink::query()->count())->toBe(0);
});

test('p0b2 missing grant returns STEP_UP_GRANT_INVALID', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token] = secureLinkCustomerToken();
    $path = 'results/p0b2-nogrant.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        [],
        authHeaders($token),
    )->assertUnprocessable();
});

test('p0b2 nonexistent grant returns STEP_UP_GRANT_INVALID', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token] = secureLinkCustomerToken();
    $path = 'results/p0b2-badgrant.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => (string) Str::uuid()],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b2 expired grant cannot issue link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-expgrant.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId, [
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b2 revoked grant cannot issue link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-revgrant.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId, [
        'revoked_at' => now(),
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b2 grant of another user cannot issue link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$userA, , $tokenIdA] = secureLinkCustomerToken(['phone' => '5555555555']);
    [$userB, $tokenB] = secureLinkCustomerToken(['phone' => '5566666666']);
    $path = 'results/p0b2-otheruser.pdf';
    storeFakePdf($path);
    $orderB = createAkubicaLaboratoryPurchase($userB, ['results' => $path]);
    $grantA = createStepUpResultsGrant($userA, $orderB->id, $tokenIdA);

    $this->postJson(
        "/api/v1/orders/{$orderB->id}/results/secure-link",
        ['grant_id' => $grantA->public_id],
        authHeaders($tokenB),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b2 grant of another PAT cannot issue link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $tokenA, $tokenIdA] = secureLinkCustomerToken();
    $other = $user->createToken('other-pat');
    $path = 'results/p0b2-otherpat.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenIdA);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($other->plainTextToken),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');

    unset($tokenA);
});

test('p0b2 grant for another order cannot issue link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-otherorder.pdf';
    storeFakePdf($path);
    $orderA = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $orderB = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $orderA->id, $tokenId);

    $this->postJson(
        "/api/v1/orders/{$orderB->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b2 invoices purpose grant cannot issue results link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-invpurpose.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId, [
        'purpose' => P0aOtpPurpose::StepUpInvoices->value,
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0b2 login or register purpose grant cannot issue results link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-loginpurpose.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    foreach ([P0aOtpPurpose::AkubicaLogin->value, P0aOtpPurpose::AkubicaRegister->value] as $purpose) {
        $grant = createStepUpResultsGrant($user, $order->id, $tokenId, [
            'purpose' => $purpose,
            'public_id' => (string) Str::uuid(),
        ]);

        $this->postJson(
            "/api/v1/orders/{$order->id}/results/secure-link",
            ['grant_id' => $grant->public_id],
            authHeaders($token),
        )->assertUnprocessable()
            ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
    }
});

test('p0b2 result not ready does not generate link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertStatus(409)
        ->assertJsonPath('error.code', 'RESULT_NOT_READY');

    expect(OtpSecureDownloadLink::query()->count())->toBe(0);
});

test('p0b2 valid link downloads pdf without bearer', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-download.pdf';
    storeFakePdf($path, '%PDF-1.4 secure-link-content');
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);

    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');

    $plain = opaqueTokenFromSecureUrl($url);

    $response = $this->get('/api/v1/secure-downloads/'.$plain);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
    expect($response->getContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('resultado-'.$order->id.'.pdf')
        ->and($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('no-cache')
        ->and($cacheControl)->toContain('must-revalidate');
});

test('p0b2 secure link with max opens three allows exactly three successful consumes', function () {
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    [, , $plain] = issueResultsSecureLink($this, $user, $token, $tokenId, 'results/p0b2-three-opens.pdf', 3);

    foreach ([1, 2, 3] as $openNumber) {
        $this->get('/api/v1/secure-downloads/'.$plain)->assertOk();

        $link = OtpSecureDownloadLink::query()->first();
        expect((int) $link->open_count)->toBe($openNumber);

        if ($openNumber < 3) {
            expect($link->consumed_at)->toBeNull();
        } else {
            expect($link->consumed_at)->not->toBeNull();
        }
    }

    $this->getJson('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'SECURE_LINK_CONSUMED');

    expect((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(3);
});

test('p0b2 HEAD secure download follows GET route and consumes without response body', function () {
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    [, , $plain] = issueResultsSecureLink($this, $user, $token, $tokenId, 'results/p0b2-head.pdf', 3);

    $before = OtpSecureDownloadLink::query()->first();
    expect((int) $before->open_count)->toBe(0)
        ->and($before->consumed_at)->toBeNull();

    $response = $this->call('HEAD', '/api/v1/secure-downloads/'.$plain);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $after = OtpSecureDownloadLink::query()->first();
    expect($response->getContent())->toBe('')
        ->and((int) $after->open_count)->toBe(1)
        ->and($after->consumed_at)->toBeNull();
});

test('p0b2 Range secure download is currently ignored and consumes a full inline pdf response', function () {
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    [, , $plain] = issueResultsSecureLink($this, $user, $token, $tokenId, 'results/p0b2-range.pdf', 3);

    $response = $this->get('/api/v1/secure-downloads/'.$plain, ['Range' => 'bytes=0-99']);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Range'))->toBeNull()
        ->and($response->headers->get('Accept-Ranges'))->toBeNull()
        ->and(strlen($response->getContent()))->toBe(strlen('%PDF-1.4 secure-link-content'))
        ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(1);
});

test('p0b2 secure download preview semantics are inline pdf and consume on response creation', function () {
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    [$order, , $plain] = issueResultsSecureLink($this, $user, $token, $tokenId, 'results/p0b2-inline-preview.pdf', 3);

    $response = $this->get('/api/v1/secure-downloads/'.$plain);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('inline')
        ->toContain('resultado-'.$order->id.'.pdf')
        ->and($response->getContent())->toStartWith('%PDF')
        ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(1);
});

test('p0b2 unknown token returns SECURE_LINK_NOT_FOUND', function () {
    enableAkubicaSecureLinksResultsFlags();

    $this->getJson('/api/v1/secure-downloads/'.str_repeat('cd', 32))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'SECURE_LINK_NOT_FOUND');
});

test('p0b2 expired link returns SECURE_LINK_EXPIRED', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-expired.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);
    $url = $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url');
    $plain = opaqueTokenFromSecureUrl($url);

    OtpSecureDownloadLink::query()->update(['expires_at' => now()->subMinute()]);

    $this->getJson('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'SECURE_LINK_EXPIRED');
});

test('p0b2 sequential opens with max_opens 1 only first succeeds', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-once.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);
    $plain = opaqueTokenFromSecureUrl($this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url'));

    $this->get('/api/v1/secure-downloads/'.$plain)->assertOk();

    $this->getJson('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'SECURE_LINK_CONSUMED');

    $link = OtpSecureDownloadLink::query()->first();
    expect((int) $link->open_count)->toBe(1)
        ->and($link->consumed_at)->not->toBeNull();
});

test('p0b2 revoked link returns SECURE_LINK_REVOKED', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-revlink.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);
    $plain = opaqueTokenFromSecureUrl($this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url'));

    app(OtpStepUpGrantService::class)->revoke($grant->fresh());

    $this->getJson('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'SECURE_LINK_REVOKED');
});

test('p0b2 grant expiry after issue does not break link within its ttl', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-grant-ttl.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);
    $plain = opaqueTokenFromSecureUrl($this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url'));

    $grant->update(['expires_at' => now()->subMinute()]);

    $this->get('/api/v1/secure-downloads/'.$plain)->assertOk();
});

test('p0b2 missing file returns RESULT_NOT_READY without consuming link', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-missing.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);
    $plain = opaqueTokenFromSecureUrl($this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url'));

    Storage::delete($path);

    $this->getJson('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RESULT_NOT_READY');

    $link = OtpSecureDownloadLink::query()->first();
    expect((int) $link->open_count)->toBe(0)
        ->and($link->consumed_at)->toBeNull();
});

test('p0b2 concurrent consume with max_opens 1 allows only one success', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-race.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);
    $plain = opaqueTokenFromSecureUrl($this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->json('data.url'));

    $service = app(\App\Services\Otp\StepUp\OtpSecureDownloadLinkService::class);
    $ok = 0;
    $consumedErrors = 0;

    foreach ([1, 2] as $_) {
        try {
            $service->consumeAndResolvePdf($plain);
            $ok++;
        } catch (\App\Exceptions\Otp\SecureDownloadLinkException $e) {
            if ($e->errorCode === 'SECURE_LINK_CONSUMED') {
                $consumedErrors++;
            } else {
                throw $e;
            }
        }
    }

    expect($ok)->toBe(1)
        ->and($consumedErrors)->toBe(1)
        ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(1);
});

test('p0b2 concurrent consume with max_opens 3 allows only three successes', function () {
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    [, , $plain] = issueResultsSecureLink($this, $user, $token, $tokenId, 'results/p0b2-race-three.pdf', 3);

    $service = app(\App\Services\Otp\StepUp\OtpSecureDownloadLinkService::class);
    $ok = 0;
    $consumedErrors = 0;

    foreach ([1, 2, 3, 4, 5] as $_) {
        try {
            $service->consumeAndResolvePdf($plain);
            $ok++;
        } catch (\App\Exceptions\Otp\SecureDownloadLinkException $e) {
            if ($e->errorCode === 'SECURE_LINK_CONSUMED') {
                $consumedErrors++;
            } else {
                throw $e;
            }
        }
    }

    expect($ok)->toBe(3)
        ->and($consumedErrors)->toBe(2)
        ->and((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(3)
        ->and(OtpSecureDownloadLink::query()->value('consumed_at'))->not->toBeNull();
});

test('p0b2 secure link issue throttle returns 429 before controller side effects', function () {
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    $path = 'results/p0b2-issue-throttle.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);
    $key = fixedThrottleUserKey($user);

    RateLimiter::clear($key);
    foreach (range(1, 60) as $_) {
        RateLimiter::hit($key, 60);
    }

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertStatus(429);

    expect(OtpSecureDownloadLink::query()->count())->toBe(0);
    RateLimiter::clear($key);
});

test('p0b2 secure download throttle returns 429 without consuming link', function () {
    [$user, $token, $tokenId] = secureLinkCustomerToken();
    [, , $plain] = issueResultsSecureLink($this, $user, $token, $tokenId, 'results/p0b2-download-throttle.pdf', 3);

    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/secure-downloads/'.str_repeat('aa', 32));
    }

    $this->get('/api/v1/secure-downloads/'.$plain)
        ->assertStatus(429);

    expect((int) OtpSecureDownloadLink::query()->value('open_count'))->toBe(0)
        ->and(OtpSecureDownloadLink::query()->value('consumed_at'))->toBeNull();
});

test('p0b2 issue response and db never expose token_hash path or pii', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token, $tokenId] = secureLinkCustomerToken(['email' => 'oculto@ejemplo.com']);
    $path = 'results/secret-internal-p0b2.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $grant = createStepUpResultsGrant($user, $order->id, $tokenId);

    $body = json_encode($this->postJson(
        "/api/v1/orders/{$order->id}/results/secure-link",
        ['grant_id' => $grant->public_id],
        authHeaders($token),
    )->assertCreated()->json());

    $link = OtpSecureDownloadLink::query()->first();
    expect($body)->not->toContain('token_hash')
        ->and($body)->not->toContain($link->token_hash)
        ->and($body)->not->toContain($path)
        ->and($body)->not->toContain('oculto@ejemplo.com')
        ->and($body)->not->toContain('5512345678')
        ->and($link->getHidden())->toContain('token_hash');
});

test('p0b2 results detail metadata is additive when flags on', function () {
    enableAkubicaSecureLinksResultsFlags();
    [$user, $token] = secureLinkCustomerToken();
    $path = 'results/p0b2-meta.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->getJson("/api/v1/orders/{$order->id}/results", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.requires_step_up', true)
        ->assertJsonPath('data.secure_link_supported', true)
        ->assertJsonPath('data.download.type', 'bearer');
});
