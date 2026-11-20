<?php

// RC2 Batch 2 · A3 (contract rev7 2b6672e6): Leo step-up for results (M10 · L10) and invoices (M12 · L14), secure links
// after step-up, and invoice request/status with the opaque fiscal id (M13 · L13, L13b). Reuses OtpStepUpGrantService,
// OtpSecureDownloadLinkService and the A1 opaque identifiers. OTP delivery is the in-memory fake (no real sends).
// LOCAL ONLY (MySQL harness). This is not STAGING_PASS and certifies nothing.
use App\Contracts\Otp\OtpCodeGenerator;
use App\Models\ApiConsumerDelegation;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\OtpChallenge;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Api\V1\Leo\LeoOpaqueIdentifiers;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Contract\Rev7Schema;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;
use Tests\Support\Otp\FakeOtpCodeGenerator;

const A3_RES_REQ = 'api:operation:api:v1:orders:results:step-up:request';
const A3_RES_VER = 'api:operation:api:v1:orders:results:step-up:verify';
const A3_RES_LINK = 'api:operation:api:v1:orders:results:secure-link';
const A3_INV_REQ = 'api:operation:api:v1:orders:invoices:step-up:request';
const A3_INV_VER = 'api:operation:api:v1:orders:invoices:step-up:verify';
const A3_INV_LINK = 'api:operation:api:v1:orders:invoices:secure-link';
const A3_IR_STATUS = 'api:operation:api:v1:orders:invoice-request:status';
const A3_IR_STORE = 'api:operation:api:v1:orders:invoice-request:store';
const A3_TAX = 'api:operation:api:v1:user:tax-profiles';
const A3_ALL = [A3_RES_REQ, A3_RES_VER, A3_RES_LINK, A3_INV_REQ, A3_INV_VER, A3_INV_LINK, A3_IR_STATUS, A3_IR_STORE, A3_TAX];

beforeEach(function () {
    SyntheticConsumerRegistryFixture::reset();
    Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00'));
    Storage::fake();
    disableAllAkubicaOtpFeatures();
});

afterEach(function () {
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
});

/** Step-up and secure links enabled with the fake OTP delivery and the code defaults of config/otp.php (60 min, 5 opens). */
function a3Enable(): void
{
    enableResultsStepUpWithFakeDelivery();
    enableInvoiceStepUpWithFakeDelivery();
    config()->set('otp.p0a.flags.secure_links_results_enabled', true);
    config()->set('otp.p0a.flags.secure_links_invoices_enabled', true);
    config()->set('otp.p0a.secure_links.ttl_minutes', 60);
    config()->set('otp.p0a.secure_links.max_opens', 5);
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));
}

function a3User(?string $phone = null): User
{
    $phone ??= '55'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

    return User::factory()->withRegularCustomer()->create(['phone' => $phone, 'phone_country' => 'MX', 'phone_verified_at' => now()]);
}

function a3Leo(?User $user = null, array $caps = A3_ALL): array
{
    return SyntheticConsumerRegistryFixture::issue($user ?? a3User(), 'leo', $caps);
}

function a3H(array $issued, bool $idempotent = true): array
{
    return ['Authorization' => 'Bearer '.$issued['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken]
        + ($idempotent ? ['Idempotency-Key' => (string) Str::uuid()] : []);
}

function a3OrderWithResults(User $user): LaboratoryPurchase
{
    $path = 'results/a3-'.Str::random(8).'.pdf';
    storeFakePdf($path, '%PDF-1.4 a3 resultados sinteticos');

    return createAkubicaLaboratoryPurchase($user, ['results' => $path]);
}

function a3Tax(User $user, array $attributes = []): TaxProfile
{
    storeFakePdf('certificates/a3-sintetica.pdf', '%PDF-1.4 constancia sintetica');
    $profile = (new TaxProfile)->forceFill(array_merge(['name' => 'Persona Sintetica', 'razon_social' => 'Persona Sintetica', 'rfc' => 'SIPA800101Q12', 'zipcode' => '01000', 'tax_regime' => '605', 'cfdi_use' => 'D01', 'domicilio_fiscal' => 'Calle Sintetica 1', 'fiscal_certificate' => 'certificates/a3-sintetica.pdf', 'is_default' => false, 'customer_id' => $user->customer->id], $attributes));
    $profile->save();

    return $profile;
}

function a3Schema(mixed $value, string $component): void
{
    expect(Rev7Schema::errors($value, $component))->toBe([]);
}

/** Full step-up (request + verify with the fake code) for a results or invoice resource; returns the verified grant id. */
function a3StepUp(\Tests\TestCase $t, array $leo, string $base): string
{
    $challenge = $t->postJson("{$base}/step-up/request", [], a3H($leo))->assertStatus(202)->json('data');

    return $t->postJson("{$base}/step-up/verify", ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'], a3H($leo, false))
        ->assertOk()->json('data.grant_id');
}

function a3Token(string $url): string
{
    $token = basename((string) parse_url($url, PHP_URL_PATH));
    expect($token)->toMatch('/^[A-Fa-f0-9]{64}$/');

    return $token;
}

// ── M10 · L10: results step-up and secure link ───────────────────────

test('M10 leo completes the results step-up and gets a secure link with the rev7 shapes (60 min, at most 3 opens)', function () {
    a3Enable();
    $leo = a3Leo();
    $order = a3OrderWithResults($leo['user']);

    $challenge = $this->postJson("/api/v1/orders/{$order->id}/results/step-up/request", [], a3H($leo))->assertStatus(202)->json();
    a3Schema($challenge, 'ApiSuccessResponse');
    a3Schema($challenge['data'], 'OtpChallenge');
    expect($challenge['data']['purpose'])->toBe('step_up_results')->and($challenge['data']['resource_id'])->toBe($order->id)
        ->and(json_encode($challenge))->not->toContain('123456')->not->toContain((string) $leo['user']->phone)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(1);

    $grant = $this->postJson("/api/v1/orders/{$order->id}/results/step-up/verify", ['challenge_id' => $challenge['data']['challenge_id'], 'code' => '123456'], a3H($leo, false))->assertOk()->json();
    a3Schema($grant['data'], 'OtpGrant');
    expect($grant['data']['purpose'])->toBe('step_up_results')->and($grant['data']['resource_id'])->toBe($order->id);
    $row = OtpStepUpGrant::query()->where('public_id', $grant['data']['grant_id'])->sole();
    expect((int) $row->user_id)->toBe($leo['user']->id)->and((int) $row->personal_access_token_id)->toBe((int) $leo['token']->accessToken->id);

    $link = $this->postJson("/api/v1/orders/{$order->id}/results/secure-link", ['grant_id' => $grant['data']['grant_id']], a3H($leo))->assertCreated()->json();
    a3Schema($link['data'], 'SecureLink');
    expect($link['data']['max_opens'])->toBe(3)
        ->and(Carbon::parse($link['data']['expires_at'])->equalTo(now()->addMinutes(60)))->toBeTrue()
        ->and($link['data'])->not->toHaveKey('grant_id');

    $token = a3Token($link['data']['url']);
    foreach ([1, 2, 3] as $open) {
        $this->get('/api/v1/secure-downloads/'.$token)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
    $this->get('/api/v1/secure-downloads/'.$token)->assertStatus(410)->assertJsonPath('error.code', 'SECURE_LINK_CONSUMED');
    expect(OtpSecureDownloadLink::query()->sole()->open_count)->toBe(3);
});

test('M10 a leo link is reusable within its TTL and expires after 60 minutes (not single-use)', function () {
    a3Enable();
    $leo = a3Leo();
    $order = a3OrderWithResults($leo['user']);
    $grant = a3StepUp($this, $leo, "/api/v1/orders/{$order->id}/results");
    $token = a3Token($this->postJson("/api/v1/orders/{$order->id}/results/secure-link", ['grant_id' => $grant], a3H($leo))->assertCreated()->json('data.url'));

    Carbon::setTestNow(now()->addMinutes(59));
    $this->get('/api/v1/secure-downloads/'.$token)->assertOk();
    Carbon::setTestNow(now()->addMinutes(2));
    $this->get('/api/v1/secure-downloads/'.$token)->assertStatus(410)->assertJsonPath('error.code', 'SECURE_LINK_EXPIRED');
});

test('M10 a stricter environment setting still wins for leo, and native keeps the configured limits', function () {
    a3Enable();
    config()->set('otp.p0a.secure_links.ttl_minutes', 5);
    config()->set('otp.p0a.secure_links.max_opens', 1);
    $leo = a3Leo();
    $order = a3OrderWithResults($leo['user']);
    $grant = a3StepUp($this, $leo, "/api/v1/orders/{$order->id}/results");
    $link = $this->postJson("/api/v1/orders/{$order->id}/results/secure-link", ['grant_id' => $grant], a3H($leo))->assertCreated()->json('data');
    expect($link['max_opens'])->toBe(1)->and(Carbon::parse($link['expires_at'])->equalTo(now()->addMinutes(5)))->toBeTrue();

    // Native regression: the configured value is not capped (code default 5 opens).
    config()->set('otp.p0a.secure_links.ttl_minutes', 60);
    config()->set('otp.p0a.secure_links.max_opens', 5);
    $user = a3User('5598765432');
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native', SyntheticConsumerRegistryFixture::capabilitiesFor('native', ['api.v1.orders.results.step-up.request', 'api.v1.orders.results.step-up.verify', 'api.v1.orders.results.secure-link']));
    $nOrder = a3OrderWithResults($user);
    $nGrant = a3StepUp($this, $native, "/api/v1/orders/{$nOrder->id}/results");
    $this->postJson("/api/v1/orders/{$nOrder->id}/results/secure-link", ['grant_id' => $nGrant], a3H($native))->assertCreated()->assertJsonPath('data.max_opens', 5);
});

// ── M12 · L14: invoices step-up and secure link (PDF only) ───────────

test('M12 leo completes the invoice step-up and gets a PDF secure link with the rev7 shapes', function () {
    a3Enable();
    $leo = a3Leo();
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $base = "/api/v1/orders/{$order->id}/invoices/{$invoice->id}";

    $challenge = $this->postJson("{$base}/step-up/request", [], a3H($leo))->assertStatus(202)->json('data');
    a3Schema($challenge, 'OtpChallenge');
    expect($challenge['purpose'])->toBe('step_up_invoices')->and($challenge['resource_id'])->toBe($invoice->id);
    $grant = $this->postJson("{$base}/step-up/verify", ['challenge_id' => $challenge['challenge_id'], 'code' => '123456'], a3H($leo, false))->assertOk()->json('data');
    a3Schema($grant, 'OtpGrant');
    expect($grant['purpose'])->toBe('step_up_invoices');

    $link = $this->postJson("{$base}/secure-link", ['grant_id' => $grant['grant_id']], a3H($leo))->assertCreated()->json('data');
    a3Schema($link, 'SecureLink');
    expect($link['max_opens'])->toBe(3);
    $this->get('/api/v1/secure-downloads/'.a3Token($link['url']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('M12 XML stays outside leo: no format field on the secure link and direct downloads are 403', function () {
    a3Enable();
    $leo = a3Leo(null, array_merge(A3_ALL, ['api:operation:api:v1:orders:invoices:download', 'api:operation:api:v1:orders:results:download']));
    $order = a3OrderWithResults($leo['user']);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $grant = a3StepUp($this, $leo, "/api/v1/orders/{$order->id}/invoices/{$invoice->id}");

    $this->postJson("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link", ['grant_id' => $grant, 'format' => 'xml'], a3H($leo))
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonPath('error.fields.format.0', 'El campo no está permitido.');
    expect(OtpSecureDownloadLink::query()->count())->toBe(0);

    $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download?format=xml", a3H($leo, false))->assertForbidden();
    $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", a3H($leo, false))->assertForbidden();
    $this->get("/api/v1/orders/{$order->id}/results/download", a3H($leo, false))->assertForbidden();
});

// ── Authentication, capability, delegation ───────────────────────────

dataset('a3 routes', [
    'results step-up request' => ['POST', '/results/step-up/request'],
    'results step-up verify' => ['POST', '/results/step-up/verify'],
    'results secure link' => ['POST', '/results/secure-link'],
    'invoices step-up request' => ['POST', '/invoices/{invoice}/step-up/request'],
    'invoices step-up verify' => ['POST', '/invoices/{invoice}/step-up/verify'],
    'invoices secure link' => ['POST', '/invoices/{invoice}/secure-link'],
    'invoice request status' => ['GET', '/invoice-request/status'],
    'invoice request store' => ['POST', '/invoice-request'],
]);

function a3Call(\Tests\TestCase $t, string $method, string $suffix, LaboratoryPurchase $order, int $invoiceId, array $headers): \Illuminate\Testing\TestResponse
{
    $uri = "/api/v1/orders/{$order->id}".str_replace('{invoice}', (string) $invoiceId, $suffix);

    return $method === 'GET' ? $t->getJson($uri, $headers) : $t->postJson($uri, [], $headers);
}

test('A3 without authentication every operation is 401', function (string $method, string $suffix) {
    a3Enable();
    $user = a3User();
    $order = createAkubicaLaboratoryPurchase($user);
    a3Call($this, $method, $suffix, $order, 1, ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    expect(OtpChallenge::query()->count())->toBe(0);
})->with('a3 routes');

test('A3 leo without the literal capability, or without its machine credential, is 403 with no effect', function (string $method, string $suffix) {
    a3Enable();
    $leo = a3Leo(null, ['api:operation:api:v1:orders:index', 'api:operation:api:v1:orders:invoices']);
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $invoice = createAkubicaLaboratoryInvoice($order);
    a3Call($this, $method, $suffix, $order, $invoice->id, a3H($leo))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');

    $full = a3Leo();
    $own = createAkubicaLaboratoryPurchase($full['user']);
    $ownInvoice = createAkubicaLaboratoryInvoice($own);
    a3Call($this, $method, $suffix, $own, $ownInvoice->id, ['Authorization' => 'Bearer '.$full['token']->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
    expect(OtpChallenge::query()->count())->toBe(0)->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0)
        ->and(InvoiceRequest::query()->count())->toBe(0);
})->with('a3 routes');

test('A3 the delegation must grant the capability and be active', function (string $method, string $suffix) {
    a3Enable();
    $leo = a3Leo();
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $invoice = createAkubicaLaboratoryInvoice($order);
    ApiConsumerDelegation::query()->whereKey($leo['delegation']->id)->update(['capabilities' => json_encode([A3_TAX])]);
    a3Call($this, $method, $suffix, $order, $invoice->id, a3H($leo))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    ApiConsumerDelegation::query()->whereKey($leo['delegation']->id)->update(['capabilities' => json_encode(A3_ALL), 'enabled' => false]);
    a3Call($this, $method, $suffix, $order, $invoice->id, a3H($leo))->assertForbidden();
    expect(OtpChallenge::query()->count())->toBe(0)->and(InvoiceRequest::query()->count())->toBe(0);
})->with('a3 routes');

// ── Cross-account and grant binding ──────────────────────────────────

test('A3 another account\'s order is 404 for leo on every operation, with no OTP and no effect', function (string $method, string $suffix) {
    a3Enable();
    $owner = a3User('5511111111');
    $order = createAkubicaLaboratoryPurchase($owner);
    $invoice = createAkubicaLaboratoryInvoice($order);
    $leo = a3Leo(a3User('5522222222'));
    $body = match (true) {
        str_ends_with($suffix, 'verify') => ['challenge_id' => (string) Str::uuid(), 'code' => '123456'],
        str_ends_with($suffix, 'secure-link') => ['grant_id' => (string) Str::uuid()],
        $suffix === '/invoice-request' => ['tax_profile_id' => app(LeoOpaqueIdentifiers::class)->forSubject($leo['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, a3Tax($leo['user'])->id)],
        default => [],
    };
    $uri = "/api/v1/orders/{$order->id}".str_replace('{invoice}', (string) $invoice->id, $suffix);
    $response = $method === 'GET' ? $this->getJson($uri, a3H($leo, false)) : $this->postJson($uri, $body, a3H($leo, ! str_ends_with($suffix, 'verify')));
    $response->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    expect(OtpChallenge::query()->count())->toBe(0)->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0)
        ->and(InvoiceRequest::query()->count())->toBe(0)->and(OtpSecureDownloadLink::query()->count())->toBe(0);
})->with('a3 routes');

test('A3 a grant only issues a link for its own user, token, purpose and resource', function () {
    a3Enable();
    $user = a3User();
    $leo = a3Leo($user);
    $orderA = a3OrderWithResults($user);
    $orderB = a3OrderWithResults($user);
    $invoiceA = createAkubicaLaboratoryInvoice($orderA);
    $grant = a3StepUp($this, $leo, "/api/v1/orders/{$orderA->id}/results");

    // Another token of the same user (another Leo binding).
    $otherToken = a3Leo($user);
    $this->postJson("/api/v1/orders/{$orderA->id}/results/secure-link", ['grant_id' => $grant], a3H($otherToken))
        ->assertStatus(422)->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
    // Another resource (own order B) and another purpose (invoice of order A).
    $this->postJson("/api/v1/orders/{$orderB->id}/results/secure-link", ['grant_id' => $grant], a3H($leo))
        ->assertStatus(422)->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
    $this->postJson("/api/v1/orders/{$orderA->id}/invoices/{$invoiceA->id}/secure-link", ['grant_id' => $grant], a3H($leo))
        ->assertStatus(422)->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
    // Another user: their own order with this grant id.
    $stranger = a3Leo(a3User('5533333333'));
    $strangerOrder = a3OrderWithResults($stranger['user']);
    $this->postJson("/api/v1/orders/{$strangerOrder->id}/results/secure-link", ['grant_id' => $grant], a3H($stranger))
        ->assertStatus(422)->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
    expect(OtpSecureDownloadLink::query()->count())->toBe(0);

    // The owner with the right token, purpose and resource still gets the link.
    $this->postJson("/api/v1/orders/{$orderA->id}/results/secure-link", ['grant_id' => $grant], a3H($leo))->assertCreated();
});

test('A3 leo request bodies follow rev7 additionalProperties=false before any effect', function () {
    a3Enable();
    $leo = a3Leo();
    $order = a3OrderWithResults($leo['user']);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->postJson("/api/v1/orders/{$order->id}/results/step-up/request", ['phone' => '5599999999'], a3H($leo))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    $this->postJson("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request", ['channel' => 'email'], a3H($leo))->assertStatus(422);
    $this->postJson("/api/v1/orders/{$order->id}/results/secure-link", ['grant_id' => (string) Str::uuid(), 'max_opens' => 99], a3H($leo))->assertStatus(422);
    expect(OtpChallenge::query()->count())->toBe(0)->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0);
});

// ── M13 · L13: invoice request and status with the opaque fiscal id ──

test('M13 leo requests an invoice with the opaque tax profile id and receives exactly LeoInvoiceRequestStatusResponse', function () {
    $leo = a3Leo();
    $profile = a3Tax($leo['user']);
    $order = createAkubicaLaboratoryPurchase($leo['user']);

    $taxId = $this->getJson('/api/v1/user/tax-profiles', a3H($leo, false))->assertOk()->json('data.tax_profiles.0.id');
    expect($taxId)->toBeString();

    $before = $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", a3H($leo, false))->assertOk()->json();
    a3Schema($before, 'LeoInvoiceRequestStatusResponse');
    expect($before['data']['invoice_status'])->toBe('not_requested');

    $json = $this->postJson("/api/v1/orders/{$order->id}/invoice-request", ['tax_profile_id' => $taxId, 'cfdi_use' => 'G03'], a3H($leo))->assertCreated()->json();
    a3Schema($json, 'LeoInvoiceRequestStatusResponse');
    $request = InvoiceRequest::query()->sole();
    expect($json['data']['invoice_status'])->toBe('pending')->and($json['data']['invoice_request']['id'])->toBe($request->id)
        ->and(json_encode($json))->not->toContain('SIPA800101Q12')->not->toContain('tax_profile_id')->not->toContain('download')
        ->and($request->rfc)->toBe('SIPA800101Q12')->and($request->cfdi_use)->toBe('G03')
        // Leo never writes tax profiles (rev7 L20): the profile keeps its cfdi_use.
        ->and($profile->fresh()->cfdi_use)->toBe('D01');

    $after = $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", a3H($leo, false))->assertOk()->json();
    a3Schema($after, 'LeoInvoiceRequestStatusResponse');
    expect($after['data']['invoice_request']['id'])->toBe($request->id);
});

test('M13 without cfdi_use the profile cfdi_use is used', function () {
    $leo = a3Leo();
    $profile = a3Tax($leo['user'], ['cfdi_use' => 'S01']);
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $taxId = app(LeoOpaqueIdentifiers::class)->forSubject($leo['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, $profile->id);

    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", ['tax_profile_id' => $taxId], a3H($leo))->assertCreated();
    expect(InvoiceRequest::query()->sole()->cfdi_use)->toBe('S01');
});

test('M13 an integer, unknown, malformed, foreign or deleted fiscal id is 422 VALIDATION_ERROR alike, without effect', function () {
    $leo = a3Leo();
    $own = a3Tax($leo['user']);
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $ids = app(LeoOpaqueIdentifiers::class);
    $other = a3User('5544444444');
    $foreign = a3Tax($other);
    $foreignRef = $ids->forSubject($other->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, $foreign->id);
    $deleted = a3Tax($leo['user'], ['rfc' => 'SIPA800101Q13']);
    $deletedRef = $ids->forSubject($leo['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, $deleted->id);
    $deleted->delete();
    $familyRef = $ids->forSubject($leo['user']->customer->id, LeoOpaqueIdentifiers::FAMILY_MEMBER, $own->id);

    $bodies = [
        'integer own id' => ['tax_profile_id' => $own->id],
        'numeric string own id' => ['tax_profile_id' => (string) $own->id],
        'unknown' => ['tax_profile_id' => 'tax_'.Str::random(22)],
        'malformed' => ['tax_profile_id' => 'no-es-una-referencia'],
        'foreign' => ['tax_profile_id' => $foreignRef],
        'deleted' => ['tax_profile_id' => $deletedRef],
        'other subject type' => ['tax_profile_id' => $familyRef],
        'missing' => [],
    ];
    $messages = [];
    foreach ($bodies as $label => $body) {
        $json = $this->postJson("/api/v1/orders/{$order->id}/invoice-request", $body, a3H($leo))->assertStatus(422)->json();
        expect($json['error']['code'])->toBe('VALIDATION_ERROR', $label)->and(array_keys($json['error']['fields']))->toBe(['tax_profile_id'], $label);
        if ($label !== 'missing') {
            $messages[] = $json['error']['fields']['tax_profile_id'];
        }
        expect(json_encode($json))->not->toContain('SIPA800101Q12')->not->toContain((string) $foreign->rfc);
    }
    expect(collect($messages)->unique(fn ($m) => json_encode($m))->count())->toBeLessThanOrEqual(2)
        ->and(InvoiceRequest::query()->count())->toBe(0);
});

test('M13 leo cannot send free email or notes (LeoInvoiceRequestCreate)', function () {
    $leo = a3Leo();
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $taxId = app(LeoOpaqueIdentifiers::class)->forSubject($leo['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, a3Tax($leo['user'])->id);

    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", ['tax_profile_id' => $taxId, 'email' => 'facturas@example.invalid', 'notes' => 'x'], a3H($leo))
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    expect(InvoiceRequest::query()->count())->toBe(0);
});

test('M13 billing rules apply to leo: existing invoice, existing request and the same-month window are 409', function () {
    $leo = a3Leo();
    $taxId = app(LeoOpaqueIdentifiers::class)->forSubject($leo['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, a3Tax($leo['user'])->id);

    $invoiced = createAkubicaLaboratoryPurchase($leo['user']);
    createAkubicaLaboratoryInvoice($invoiced);
    $this->postJson("/api/v1/orders/{$invoiced->id}/invoice-request", ['tax_profile_id' => $taxId], a3H($leo))->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_ALREADY_EXISTS');

    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", ['tax_profile_id' => $taxId], a3H($leo))->assertCreated();
    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", ['tax_profile_id' => $taxId], a3H($leo))->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_REQUEST_ALREADY_EXISTS');

    // Purchased last month: outside the invoicing window of LaboratoryInvoiceSupport::canRequestInvoice.
    $old = createAkubicaLaboratoryPurchase($leo['user']);
    $old->forceFill(['created_at' => now()->subMonth()->startOfMonth()->addDays(3)])->save();
    $this->postJson("/api/v1/orders/{$old->id}/invoice-request", ['tax_profile_id' => $taxId], a3H($leo))->assertStatus(409)->assertJsonPath('error.code', 'ORDER_NOT_INVOICEABLE');
    expect(InvoiceRequest::query()->count())->toBe(1);
});

test('M13 the leo status of an issued invoice has no download fields', function () {
    $leo = a3Leo();
    $order = createAkubicaLaboratoryPurchase($leo['user']);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $json = $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", a3H($leo, false))->assertOk()->json();
    a3Schema($json, 'LeoInvoiceRequestStatusResponse');
    expect($json['data']['invoice_status'])->toBe('issued')->and($json['data']['invoice']['id'])->toBe($invoice->id)
        ->and(json_encode($json))->not->toContain('download');
});

// ── Restrictions and native regression ───────────────────────────────

test('A3 leo payments and payment links stay denied even with A3 grants', function () {
    $leo = a3Leo(null, array_merge(A3_ALL, ['payments:draft', \App\Services\Api\V1\Auth\PaymentLinkAccessPolicy::CAPABILITY]));
    $this->postJson('/api/v1/checkout/draft', ['brand' => 'olab'], a3H($leo))->assertForbidden();
    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], a3H($leo))->assertForbidden();
});

test('A3 native invoice request keeps the integer id, its response and the download_url in status', function () {
    $user = a3User();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native', SyntheticConsumerRegistryFixture::capabilitiesFor('native', ['api.v1.orders.invoice-request.store', 'api.v1.orders.invoice-request.status']));
    $profile = a3Tax($user);
    $order = createAkubicaLaboratoryPurchase($user);

    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", ['tax_profile_id' => $profile->id, 'cfdi_use' => 'G03'], a3H($native))
        ->assertCreated()->assertJsonPath('data.invoice_request.tax_profile_id', $profile->id)->assertJsonPath('data.invoice_request.status', 'pending');
    // Native keeps its previous behaviour: the requested cfdi_use is stored on the profile.
    expect($profile->fresh()->cfdi_use)->toBe('G03');
    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", ['tax_profile_id' => 'tax_'.Str::random(22)], a3H($native))->assertStatus(422);

    $invoiced = createAkubicaLaboratoryPurchase($user);
    createAkubicaLaboratoryInvoice($invoiced);
    expect($this->getJson("/api/v1/orders/{$invoiced->id}/invoice-request/status", a3H($native, false))->assertOk()->json('data.invoice'))->toHaveKey('download_url');
    $this->postJson("/api/v1/orders/{$order->id}/invoice-request", ['tax_profile_id' => 999999], a3H($native))->assertNotFound()->assertJsonPath('error.code', 'TAX_PROFILE_NOT_FOUND');
});
