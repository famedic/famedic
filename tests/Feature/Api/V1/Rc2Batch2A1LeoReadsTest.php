<?php

// RC2 Batch 2 · A1 (contract rev7 2b6672e6): Leo reads M07 (L07 status/products), M08 (L08 family), M09 (L09 results
// metadata), M11 (L12 invoices metadata), M14 (D-1/L13b tax profiles) and M19 (L18 appointments denied).
// LOCAL ONLY (MySQL harness). This is not STAGING_PASS and certifies nothing.
use App\Enums\Kinship;
use App\Models\FamilyAccount;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Api\V1\Leo\LeoOpaqueIdentifiers;
use Illuminate\Support\Str;
use Tests\Support\Api\V1\Contract\Rev7Schema;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture;

const A1_STATUS = 'api:operation:api:v1:orders:status';
const A1_PRODUCTS = 'api:operation:api:v1:orders:products';
const A1_FAMILY = 'api:operation:api:v1:user:family';
const A1_RESULTS_INDEX = 'api:operation:api:v1:orders:results:index';
const A1_RESULTS = 'api:operation:api:v1:orders:results';
const A1_INVOICES_INDEX = 'api:operation:api:v1:orders:invoices:index';
const A1_INVOICES = 'api:operation:api:v1:orders:invoices';
const A1_TAX = 'api:operation:api:v1:user:tax-profiles';
const A1_ALL_READS = [A1_STATUS, A1_PRODUCTS, A1_FAMILY, A1_RESULTS_INDEX, A1_RESULTS, A1_INVOICES_INDEX, A1_INVOICES, A1_TAX, 'api:operation:api:v1:orders:index'];

beforeEach(fn () => SyntheticConsumerRegistryFixture::reset());

function a1Headers(array $issued, bool $idempotent = false): array
{
    return ['Authorization' => 'Bearer '.$issued['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $issued['machine']->plainTextToken]
        + ($idempotent ? ['Idempotency-Key' => (string) Str::uuid()] : []);
}

function a1Leo(?User $user = null, array $caps = A1_ALL_READS): array
{
    return SyntheticConsumerRegistryFixture::issue($user ?? User::factory()->withRegularCustomer()->create(), 'leo', $caps);
}

function a1Order(User $user, array $attributes = []): LaboratoryPurchase
{
    return createAkubicaLaboratoryPurchase($user, $attributes);
}

function a1OrderWithProduct(User $user): LaboratoryPurchase
{
    $order = a1Order($user);
    $test = createOlabTest(['gda_id' => 'A1-GDA-'.bin2hex(random_bytes(4))]);
    LaboratoryPurchaseItem::query()->create(['laboratory_purchase_id' => $order->id, 'gda_id' => $test->gda_id, 'name' => 'Química sanguínea', 'indications' => 'Ayuno', 'price_cents' => 35000]);

    return $order;
}

function a1Invoice(LaboratoryPurchase $order): Invoice
{
    return Invoice::query()->create(['invoiceable_type' => LaboratoryPurchase::class, 'invoiceable_id' => $order->id, 'invoice' => 'invoices/a1-sintetica.pdf']);
}

function a1Family(User $user, array $attributes = []): FamilyAccount
{
    return FamilyAccount::query()->create(array_merge(['customer_id' => $user->customer->id, 'name' => 'Ana', 'paternal_lastname' => 'Sintetica', 'maternal_lastname' => 'Prueba', 'birth_date' => '2015-03-04', 'gender' => \App\Enums\Gender::FEMALE, 'kinship' => Kinship::CHILD], $attributes));
}

function a1Tax(User $user, array $attributes = []): TaxProfile
{
    $profile = (new TaxProfile)->forceFill(array_merge(['name' => 'Persona Sintetica', 'razon_social' => 'Persona Sintetica', 'rfc' => 'SIPA800101Q12', 'zipcode' => '01000', 'tax_regime' => '605', 'cfdi_use' => 'D01', 'domicilio_fiscal' => 'Calle Sintetica 1', 'fiscal_certificate' => 'certificates/a1-sintetica.pdf', 'is_default' => false, 'customer_id' => $user->customer->id], $attributes));
    $profile->save();

    return $profile;
}

function a1ExpectSchema(array $json, string $component): void
{
    expect(Rev7Schema::errors($json, $component))->toBe([]);
}

/** Recursively collects every key of a decoded JSON document. */
function a1Keys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $k => $v) {
        if (is_string($k)) {
            $keys[] = $k;
        }
        $keys = array_merge($keys, a1Keys($v));
    }

    return array_values(array_unique($keys));
}

const A1_FORBIDDEN_KEYS = ['download_url', 'download', 'url', 'birth_date', 'gender', 'full_name', 'paternal_lastname', 'maternal_lastname', 'rfc', 'postal_code', 'zipcode', 'domicilio_fiscal', 'fiscal_certificate', 'certificate_url', 'email', 'requires_step_up', 'secure_link_supported', 'order_study_name'];

// ── M07 · L07 ────────────────────────────────────────────────────────

test('M07 leo reads the status of its own order with the rev7 LeoOrderStatus shape', function () {
    $leo = a1Leo();
    $order = a1Order($leo['user']);

    $json = $this->getJson("/api/v1/orders/{$order->id}/status", a1Headers($leo))->assertOk()->json();

    a1ExpectSchema($json, 'LeoOrderStatusResponse');
    expect($json['data']['order_id'])->toBe($order->id);
});

test('M07 leo reads the products of its own order with the rev7 LeoOrderProduct shape', function () {
    $leo = a1Leo();
    $order = a1OrderWithProduct($leo['user']);

    $json = $this->getJson("/api/v1/orders/{$order->id}/products", a1Headers($leo))->assertOk()->json();

    a1ExpectSchema($json, 'LeoOrderProductsResponse');
    expect($json['data']['products'])->toHaveCount(1)
        ->and(array_intersect(a1Keys($json), A1_FORBIDDEN_KEYS))->toBe([]);
});

test('M07 a foreign order is soft-denied with 404 ORDER_NOT_FOUND for status and products', function () {
    $foreign = a1Order(User::factory()->withRegularCustomer()->create());
    $leo = a1Leo();

    foreach (['status', 'products'] as $op) {
        $this->getJson("/api/v1/orders/{$foreign->id}/{$op}", a1Headers($leo))->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    }
});

test('M07 status and products without their literal grant, or with a revoked delegation, are 403', function () {
    $leo = a1Leo(null, ['api:operation:api:v1:orders:index']);
    $order = a1Order($leo['user']);
    foreach (['status', 'products'] as $op) {
        $this->getJson("/api/v1/orders/{$order->id}/{$op}", a1Headers($leo))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    }

    $revoked = a1Leo();
    $own = a1Order($revoked['user']);
    $revoked['delegation']->forceFill(['enabled' => false])->save();
    $this->getJson("/api/v1/orders/{$own->id}/status", a1Headers($revoked))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});

test('M07 without Bearer the status route stays 401', function () {
    $order = a1Order(User::factory()->withRegularCustomer()->create());
    $this->getJson("/api/v1/orders/{$order->id}/status")->assertUnauthorized();
});

test('every A1 route and its variants stays 401 without Bearer, including the appointment operations denied to leo', function (string $method, string $uri) {
    $order = a1OrderWithProduct(User::factory()->withRegularCustomer()->create());
    $invoice = a1Invoice($order);
    $uri = str_replace(['{order}', '{invoice}'], [(string) $order->id, (string) $invoice->id], $uri);
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    'M07 status' => ['GET', '/api/v1/orders/{order}/status'],
    'M07 products' => ['GET', '/api/v1/orders/{order}/products'],
    'M07 index' => ['GET', '/api/v1/orders'],
    'M08 family' => ['GET', '/api/v1/user/family'],
    'M09 results index' => ['GET', '/api/v1/orders/results'],
    'M09 results detail' => ['GET', '/api/v1/orders/{order}/results'],
    'M11 invoices index' => ['GET', '/api/v1/orders/invoices'],
    'M11 invoices of order' => ['GET', '/api/v1/orders/{order}/invoices'],
    'M14 tax profiles' => ['GET', '/api/v1/user/tax-profiles'],
    'M14 tax profile create' => ['POST', '/api/v1/user/tax-profiles'],
    'M19 appointments requirements' => ['GET', '/api/v1/laboratory-appointments/requirements'],
    'M19 appointments index' => ['GET', '/api/v1/laboratory-appointments'],
    'M19 appointments store' => ['POST', '/api/v1/laboratory-appointments'],
    'M19 appointments destroy' => ['DELETE', '/api/v1/laboratory-appointments/1'],
    'download results' => ['GET', '/api/v1/orders/{order}/results/download'],
    'download invoice' => ['GET', '/api/v1/orders/{order}/invoices/{invoice}/download'],
]);

// ── M08 · L08 ────────────────────────────────────────────────────────

test('M08 leo family uses the rev7 LeoFamilyMember shape without birth date, gender, last names or internal id', function () {
    $leo = a1Leo();
    $member = a1Family($leo['user']);

    $json = $this->getJson('/api/v1/user/family', a1Headers($leo))->assertOk()->json();

    a1ExpectSchema($json, 'LeoFamilyResponse');
    $row = $json['data']['family'][0];
    expect($row)->toBe(['id' => $row['id'], 'display_name' => 'Ana', 'relationship' => 'child', 'is_main_profile' => false])
        ->and($row['id'])->toBeString()->not->toBe((string) $member->id)->not->toContain((string) $member->id)
        ->and(array_intersect(a1Keys($json), A1_FORBIDDEN_KEYS))->toBe([])
        ->and(json_encode($json))->not->toContain('2015-03-04')->not->toContain('Sintetica');
});

test('M08 opaque family ids are stable per customer, random, and never resolve for another customer', function () {
    $a = a1Leo();
    $b = a1Leo();
    $memberA = a1Family($a['user']);
    $memberB = a1Family($b['user'], ['name' => 'Beto']);

    $first = $this->getJson('/api/v1/user/family', a1Headers($a))->json('data.family');
    $again = $this->getJson('/api/v1/user/family', a1Headers($a))->json('data.family');
    $other = $this->getJson('/api/v1/user/family', a1Headers($b))->json('data.family');

    expect($first)->toHaveCount(1)->and($again[0]['id'])->toBe($first[0]['id'])
        ->and($other)->toHaveCount(1)->and($other[0]['display_name'])->toBe('Beto')
        ->and($other[0]['id'])->not->toBe($first[0]['id']);

    $ids = app(LeoOpaqueIdentifiers::class);
    expect($ids->resolve($a['user']->customer->id, LeoOpaqueIdentifiers::FAMILY_MEMBER, $first[0]['id']))->toBe($memberA->id)
        ->and($ids->resolve($b['user']->customer->id, LeoOpaqueIdentifiers::FAMILY_MEMBER, $first[0]['id']))->toBeNull()
        ->and($ids->resolve($a['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, $first[0]['id']))->toBeNull()
        ->and($ids->resolve($a['user']->customer->id, LeoOpaqueIdentifiers::FAMILY_MEMBER, (string) $memberA->id))->toBeNull()
        ->and($memberB->id)->toBeInt();
});

test('M08 family without its literal grant is 403', function () {
    $leo = a1Leo(null, ['api:operation:api:v1:orders:index']);
    $this->getJson('/api/v1/user/family', a1Headers($leo))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});

// ── M09 · L09 ────────────────────────────────────────────────────────

test('M09 leo reads minimal results metadata (index and detail) with the rev7 shapes and no download fields', function () {
    $leo = a1Leo();
    $order = a1OrderWithProduct($leo['user']);
    createAkubicaResultsNotification($order);

    $index = $this->getJson('/api/v1/orders/results', a1Headers($leo))->assertOk()->json();
    $detail = $this->getJson("/api/v1/orders/{$order->id}/results", a1Headers($leo))->assertOk()->json();

    a1ExpectSchema($index, 'LeoResultsIndexResponse');
    a1ExpectSchema($detail, 'LeoOrderResultsResponse');
    expect($index['data']['results'][0]['order_id'])->toBe($order->id)
        ->and($detail['data']['has_pdf'])->toBeTrue()
        ->and(array_intersect(a1Keys($index), A1_FORBIDDEN_KEYS))->toBe([])
        ->and(array_intersect(a1Keys($detail), A1_FORBIDDEN_KEYS))->toBe([])
        ->and(json_encode($detail))->not->toContain('base64')->not->toContain('%PDF');
});

test('M09 foreign results are 404 and results without the literal grant are 403', function () {
    $foreign = a1Order(User::factory()->withRegularCustomer()->create());
    createAkubicaResultsNotification($foreign);
    $leo = a1Leo();
    $this->getJson("/api/v1/orders/{$foreign->id}/results", a1Headers($leo))->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    expect($this->getJson('/api/v1/orders/results', a1Headers($leo))->json('data.results'))->toBe([]);

    $noGrant = a1Leo(null, [A1_FAMILY]);
    $this->getJson('/api/v1/orders/results', a1Headers($noGrant))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    $own = a1Order($noGrant['user']);
    $this->getJson("/api/v1/orders/{$own->id}/results", a1Headers($noGrant))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});

// ── M11 · L12 ────────────────────────────────────────────────────────

test('M11 leo reads minimal invoice metadata (index and detail) with the rev7 shapes and no download fields', function () {
    $leo = a1Leo();
    $order = a1OrderWithProduct($leo['user']);
    $invoice = a1Invoice($order);

    $index = $this->getJson('/api/v1/orders/invoices', a1Headers($leo))->assertOk()->json();
    $detail = $this->getJson("/api/v1/orders/{$order->id}/invoices", a1Headers($leo))->assertOk()->json();

    a1ExpectSchema($index, 'LeoInvoicesIndexResponse');
    a1ExpectSchema($detail, 'LeoOrderInvoicesResponse');
    expect($detail['data']['invoices'][0]['id'])->toBe($invoice->id)
        ->and($detail['data']['invoices'][0]['status'])->toBe('issued')
        ->and(array_intersect(a1Keys($index), A1_FORBIDDEN_KEYS))->toBe([])
        ->and(array_intersect(a1Keys($detail), A1_FORBIDDEN_KEYS))->toBe([])
        ->and(json_encode($detail))->not->toContain('a1-sintetica.pdf')->not->toContain('/download');
});

test('M11 foreign invoices are 404 and invoices without the literal grant are 403', function () {
    $foreign = a1Order(User::factory()->withRegularCustomer()->create());
    a1Invoice($foreign);
    $leo = a1Leo();
    $this->getJson("/api/v1/orders/{$foreign->id}/invoices", a1Headers($leo))->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
    expect($this->getJson('/api/v1/orders/invoices', a1Headers($leo))->json('data.invoices'))->toBe([]);

    $noGrant = a1Leo(null, [A1_FAMILY]);
    $this->getJson('/api/v1/orders/invoices', a1Headers($noGrant))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});

// ── M14 · D-1 / L13b ─────────────────────────────────────────────────

test('M14 leo tax profiles are masked with an opaque id and never expose RFC, postal code, address or CSF', function () {
    $leo = a1Leo();
    $profile = a1Tax($leo['user'], ['is_default' => true]);

    $json = $this->getJson('/api/v1/user/tax-profiles', a1Headers($leo))->assertOk()->json();

    a1ExpectSchema($json, 'LeoTaxProfilesResponse');
    $row = $json['data']['tax_profiles'][0];
    expect($row['rfc_masked'])->toBe('**********Q12')
        ->and($row['business_name'])->toBe('Persona Sintetica')
        ->and($row['tax_regime'])->toBe('605')->and($row['cfdi_use'])->toBe('D01')
        ->and($row['is_default'])->toBeTrue()
        ->and($row['id'])->toBeString()->not->toBe((string) $profile->id)
        ->and(array_intersect(a1Keys($json), A1_FORBIDDEN_KEYS))->toBe([])
        ->and(json_encode($json))->not->toContain('SIPA800101')->not->toContain('01000')->not->toContain('Calle Sintetica')->not->toContain('certificates/');
});

test('M14 tax profile opaque ids are scoped to the customer; foreign profiles are never listed', function () {
    $a = a1Leo();
    $b = a1Leo();
    $profileA = a1Tax($a['user']);
    a1Tax($b['user'], ['rfc' => 'XEXX010101000', 'razon_social' => 'Otra Sintetica']);

    $listA = $this->getJson('/api/v1/user/tax-profiles', a1Headers($a))->json('data.tax_profiles');
    $listB = $this->getJson('/api/v1/user/tax-profiles', a1Headers($b))->json('data.tax_profiles');

    expect($listA)->toHaveCount(1)->and($listB)->toHaveCount(1)
        ->and($listB[0]['business_name'])->toBe('Otra Sintetica')
        ->and($listB[0]['id'])->not->toBe($listA[0]['id']);
    $ids = app(LeoOpaqueIdentifiers::class);
    expect($ids->resolve($a['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, $listA[0]['id']))->toBe($profileA->id)
        ->and($ids->resolve($b['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, $listA[0]['id']))->toBeNull()
        ->and($ids->resolve($a['user']->customer->id, LeoOpaqueIdentifiers::TAX_PROFILE, 'tax_desconocido'))->toBeNull();
});

test('M14 a profile without a valid RFC is not listed to leo (no invented mask)', function () {
    $leo = a1Leo();
    a1Tax($leo['user'], ['rfc' => 'NO-ES-RFC']);

    expect($this->getJson('/api/v1/user/tax-profiles', a1Headers($leo))->assertOk()->json('data.tax_profiles'))->toBe([]);
});

test('M14 tax profiles without the literal grant are 403 and tax profile writes stay denied to leo (L20)', function () {
    $noGrant = a1Leo(null, [A1_FAMILY]);
    $this->getJson('/api/v1/user/tax-profiles', a1Headers($noGrant))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');

    $leo = a1Leo(null, [A1_TAX, 'api:operation:api:v1:user:tax-profiles:store', 'api:operation:api:v1:user:tax-profiles:update', 'api:operation:api:v1:user:tax-profiles:destroy']);
    $profile = a1Tax($leo['user']);
    $this->postJson('/api/v1/user/tax-profiles', ['rfc' => 'SIPA800101Q12'], a1Headers($leo, true))->assertForbidden();
    $this->putJson("/api/v1/user/tax-profiles/{$profile->id}", ['rfc' => 'SIPA800101Q12'], a1Headers($leo, true))->assertForbidden();
    $this->deleteJson("/api/v1/user/tax-profiles/{$profile->id}", [], a1Headers($leo, true))->assertForbidden();
    expect(TaxProfile::query()->whereKey($profile->id)->exists())->toBeTrue();
});

// ── M19 · L18 y restricciones de Leo intactas ────────────────────────

test('M19 leo holding literal appointment grants is denied every appointment operation', function () {
    $leo = a1Leo(null, ['api:operation:api:v1:laboratory-appointments:requirements', 'api:operation:api:v1:laboratory-appointments:index', 'api:operation:api:v1:laboratory-appointments:store', 'api:operation:api:v1:laboratory-appointments:destroy']);

    $this->getJson('/api/v1/laboratory-appointments/requirements?brand=olab', a1Headers($leo))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    $this->getJson('/api/v1/laboratory-appointments', a1Headers($leo))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    $this->postJson('/api/v1/laboratory-appointments', [], a1Headers($leo, true))->assertForbidden();
    $this->deleteJson('/api/v1/laboratory-appointments/1', [], a1Headers($leo, true))->assertForbidden();
});

test('leo restrictions stay intact: direct downloads, cancel, payments, payment link, coupons and other private surface (L11, L15-L17, L20)', function () {
    $caps = array_merge(A1_ALL_READS, [
        'api:operation:api:v1:orders:results:download', 'api:operation:api:v1:orders:invoices:download', 'api:operation:api:v1:orders:cancel',
        'api:operation:api:v1:user:addresses', 'api:operation:api:v1:user:contacts:index', 'api:operation:api:v1:user:payment-methods',
        'payments:draft', \App\Services\Api\V1\Auth\PaymentLinkAccessPolicy::CAPABILITY, 'legacy-benefits:read',
    ]);
    $leo = a1Leo(null, $caps);
    $order = a1OrderWithProduct($leo['user']);
    createAkubicaResultsNotification($order);
    $invoice = a1Invoice($order);
    $h = a1Headers($leo);

    $this->getJson("/api/v1/orders/{$order->id}/results/download", $h)->assertForbidden();
    $this->getJson("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", $h)->assertForbidden();
    $this->putJson("/api/v1/orders/{$order->id}/cancel", [], a1Headers($leo, true))->assertForbidden();
    $this->getJson('/api/v1/user/addresses', $h)->assertForbidden();
    $this->getJson('/api/v1/user/contacts', $h)->assertForbidden();
    $this->getJson('/api/v1/user/payment-methods', $h)->assertForbidden();
    $this->postJson('/api/v1/checkout/draft', ['brand' => 'olab'], a1Headers($leo, true))->assertForbidden();
    // rev7 POST /checkout/payment-link: "Leo: siempre 403" (exact status, no 401/403 range).
    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], a1Headers($leo, true))->assertForbidden();
    $this->getJson('/api/v1/cart/coupon', $h)->assertForbidden();
    $this->getJson('/api/v1/cart/medical-benefit', $h)->assertForbidden();
    // Step-up and secure links stay out of A1 (A3): still 403 for leo.
    $this->postJson("/api/v1/orders/{$order->id}/results/step-up/request", [], a1Headers($leo, true))->assertForbidden();
    $this->postJson("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/secure-link", [], a1Headers($leo, true))->assertForbidden();
    $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", $h)->assertForbidden();
});

test('leo pharmacy purchases stay suspended with 503 PHARMACY_SUSPENDED (L19)', function () {
    $leo = a1Leo(null, ['cart:read', 'cart:membership:write']);
    $this->postJson('/api/v1/cart/items', ['service_line' => 'pharmacy', 'item_type' => 'medication', 'quantity' => 1], a1Headers($leo, true))
        ->assertStatus(503)->assertJsonPath('error.code', 'PHARMACY_SUSPENDED');
});

// ── Regresión native ─────────────────────────────────────────────────

test('native responses are unchanged: family, tax profiles, results and invoices keep their native resources', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $native = SyntheticConsumerRegistryFixture::issue($user, 'native', array_merge(A1_ALL_READS, ['api:operation:api:v1:orders:index']));
    $member = a1Family($user);
    $profile = a1Tax($user);
    $order = a1OrderWithProduct($user);
    createAkubicaResultsNotification($order);
    a1Invoice($order);
    $h = a1Headers($native);

    $family = $this->getJson('/api/v1/user/family', $h)->assertOk()->json('data.family.0');
    expect($family['id'])->toBe($member->id)->and($family)->toHaveKeys(['full_name', 'birth_date', 'gender']);

    $tax = $this->getJson('/api/v1/user/tax-profiles', $h)->assertOk()->json('data.tax_profiles.0');
    expect($tax)->toBe(['id' => $profile->id, 'rfc' => 'SIPA800101Q12', 'business_name' => 'Persona Sintetica', 'tax_regime' => '605', 'cfdi_use' => 'D01', 'postal_code' => '01000']);

    $detail = $this->getJson("/api/v1/orders/{$order->id}/results", $h)->assertOk()->json('data');
    expect(a1Keys($detail))->toContain('download');

    $invoice = $this->getJson("/api/v1/orders/{$order->id}/invoices", $h)->assertOk()->json('data.invoices.0');
    expect($invoice)->toHaveKeys(['download_url', 'download'])
        ->and(Rev7Schema::errors(['success' => true, 'data' => ['order_id' => $order->id, 'invoices' => [$invoice]]], 'LeoOrderInvoicesResponse'))->not->toBe([]);

    $this->getJson("/api/v1/orders/{$order->id}/status", $h)->assertOk()->assertJsonPath('data.order_id', $order->id);
    $this->getJson("/api/v1/orders/{$order->id}/products", $h)->assertOk()->assertJsonPath('data.order_id', $order->id);
});

test('native without the tax-profiles literal grant is still 403 (the route keeps its literal capability)', function () {
    $native = SyntheticConsumerRegistryFixture::issue(User::factory()->withRegularCustomer()->create(), 'native', ['api:operation:api:v1:user:family']);
    $this->getJson('/api/v1/user/tax-profiles', a1Headers($native))->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
});
