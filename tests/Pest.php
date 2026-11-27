<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

uses(
    DuskTestCase::class,
    DatabaseTruncation::class,
)->in('Browser');

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

uses(TestCase::class)->in('Unit');

require_once __DIR__.'/Support/Otp/akubica_otp_testing_helpers.php';

/*
|--------------------------------------------------------------------------
| P0-T1 — Reset Akubica OTP flags before each API V1 feature test
|--------------------------------------------------------------------------
| phpunit.xml already forces legacy-safe env defaults. This beforeEach also
| clears in-process config()->set leaks between suites in the same process.
*/
uses()->beforeEach(function () {
    disableAllAkubicaOtpFeatures();

    if (getenv('FAMEDIC_RC2_ACTIVE_CAMPAIGN_FAKE') === '1') {
        \Illuminate\Support\Facades\Http::fake([
            'https://activecampaign.invalid/*' => \Illuminate\Support\Facades\Http::response([
                'contact' => ['id' => 'rc2-synthetic-contact'],
            ], 200),
        ]);
    }
})->in('Feature/Api/V1');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function akubicaCustomerToken(?\App\Models\User $user = null): array
{
    $user ??= \App\Models\User::factory()->withRegularCustomer()->create();
    if (getenv('FAMEDIC_REGISTERED_NATIVE_FIXTURES') === '1') {
        $issued = \Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture::issue($user);
        return [$user, $issued['token']->plainTextToken];
    }
    $token = $user->createToken('akubica-test')->plainTextToken;

    return [$user, $token];
}

/** INT-031 (harness only): every named api.v1.* route, used to derive literal per-route capabilities. */
function akubicaApiV1RouteNames(): array
{
    return collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'api.v1.'))
        ->values()->all();
}

/** INT-031 (harness only): see OtpEnrollmentFixture::baseline(). */
function akubicaHarnessBaseline(string $table): int
{
    return \Tests\Support\Api\V1\Identity\OtpEnrollmentFixture::baseline($table);
}

/**
 * INT-031 (harness only): issue the Sanctum token of a legacy Akubica test through the CURRENT registered-consumer
 * contract (consumer + channel + machine credential + delegation + binding) when FAMEDIC_REGISTERED_NATIVE_FIXTURES=1.
 * Replaces the pre-RC2 bare `$user->createToken('akubica-test')`, which the API now rejects by design.
 * Capabilities are LITERAL: the fixture default set plus each route's own capability derived with the application's
 * rules (SyntheticConsumerRegistryFixture::capabilitiesFor). Never '*'. When $routeNames is null every named
 * api.v1.* route is listed (the old token had '*'); ConsumerCapabilityPolicy, ownership checks and Leo denials still
 * decide. The machine credential travels through authHeaders(). Production code is not touched.
 */
function akubicaRegisteredToken(\App\Models\User $user, ?array $routeNames = null, string $kind = 'native', array $laboratoryBrands = []): \Laravel\Sanctum\NewAccessToken
{
    if (getenv('FAMEDIC_REGISTERED_NATIVE_FIXTURES') !== '1') {
        return $user->createToken('akubica-test');
    }
    if ($user->customer === null) {
        throw new \LogicException('akubicaRegisteredToken() requires a user with a customer (delegations are customer-bound).');
    }
    $routeNames ??= akubicaApiV1RouteNames();
    $caps = \Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture::capabilitiesFor($kind, $routeNames);

    return \Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture::issue($user, $kind, $caps, $laboratoryBrands)['token'];
}

/**
 * INT-031 (harness only): like akubicaCustomerToken() but with the literal route capabilities of
 * akubicaRegisteredToken(). akubicaCustomerToken() keeps its narrow default set for the Rc2/Batch2 tests.
 *
 * @return array{0: \App\Models\User, 1: string}
 */
function akubicaRouteCustomerToken(?\App\Models\User $user = null, ?array $routeNames = null): array
{
    $user ??= \App\Models\User::factory()->withRegularCustomer()->create();

    return [$user, akubicaRegisteredToken($user, $routeNames)->plainTextToken];
}

/**
 * INT-031 (harness only): machine credential of an authenticated OTP backend client for the public OTP routes that
 * sit behind AuthenticateOtpBackend (config akubica_connectors.otp_enrollment enabled+certified). Enrolls a fresh
 * registered consumer per call through OtpEnrollmentFixture::existing(); pass the user that will verify so the
 * enrollment operator matches. Returns the header only; the OTP contract itself is unchanged.
 *
 * @return array<string, string>
 */
function akubicaOtpBackendHeaders(?\App\Models\User $user = null, string $kind = 'leo'): array
{
    if (getenv('FAMEDIC_REGISTERED_NATIVE_FIXTURES') !== '1') {
        return [];
    }
    $user ??= \App\Models\User::factory()->withRegularCustomer()->create();
    \Tests\Support\Api\V1\Identity\OtpEnrollmentFixture::existing($user, $kind);

    return \Tests\Support\Api\V1\Identity\OtpEnrollmentFixture::headers();
}

/** INT-031 (harness only): opt-in default OTP backend for a legacy test file; see TestCase::enableDefaultOtpBackend(). */
function akubicaDefaultOtpBackend(string $kind = 'native'): void
{
    test()->enableDefaultOtpBackend($kind);
}

/**
 * INT-031 (harness only): PersonalAccessToken count without the harness OTP backend actor's own two PATs, so the
 * legacy "no token was issued" / "exactly one token was issued" expectations keep their exact values.
 */
function akubicaNonHarnessTokenCount(): int
{
    return \Laravel\Sanctum\PersonalAccessToken::query()
        ->whereNotIn('id', \Tests\Support\Api\V1\Identity\OtpEnrollmentFixture::harnessTokenIds())->count();
}

/**
 * INT-031 (harness only): invoke IssueAkubicaTokenAction the way the API does, i.e. inside a request that carries
 * the authenticated OTP backend machine credential (RegisteredOtpConsumerEnrollment reads it from request()).
 */
function akubicaIssueTokenViaBackend(\App\Models\User $user): array
{
    $machine = \Tests\Support\Api\V1\Identity\OtpEnrollmentFixture::machine();
    if ($machine !== null) {
        request()->headers->set('X-Synthetic-Machine-Credential', $machine);
    }

    return app(\App\Actions\Api\V1\Auth\IssueAkubicaTokenAction::class)($user);
}

function authHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token] + (getenv('FAMEDIC_REGISTERED_NATIVE_FIXTURES') === '1'
        ? \Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture::headers($token) : []);
}

/**
 * Pest wrapper for Tests\TestCase::switchApiBearerToken().
 *
 * @return array{Authorization: string}
 */
function switchApiBearerToken(TestCase $test, string $token): array
{
    return $test->switchApiBearerToken($token);
}

function nextAkubicaGdaConsecutivo(): int
{
    static $next = 700000;

    return $next++;
}

function createOlabTest(array $attributes = []): \App\Models\LaboratoryTest
{
    return \App\Models\LaboratoryTest::factory()->create(array_merge([
        'brand' => \App\Enums\LaboratoryBrand::OLAB,
        'gda_id' => 'RC2-TEST-'.bin2hex(random_bytes(6)),
        'famedic_price_cents' => 35000,
        'public_price_cents' => 40000,
    ], $attributes));
}

function addOlabCartItem(\App\Models\User $user, ?\App\Models\LaboratoryTest $test = null): \App\Models\LaboratoryTest
{
    $test ??= createOlabTest([
        'famedic_price_cents' => 35000,
        'public_price_cents' => 45000,
    ]);

    \App\Models\LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    return $test;
}

/**
 * Cart item for payment-link happy paths: appointment not required.
 * Keep using addOlabCartItem() where APPOINTMENT_REQUIRED must remain random/true.
 */
function addOlabCartItemReadyForPaymentLink(\App\Models\User $user): \App\Models\LaboratoryTest
{
    return addOlabCartItem($user, createOlabTest([
        'requires_appointment' => false,
        'famedic_price_cents' => 35000,
        'public_price_cents' => 45000,
    ]));
}

/**
 * Compare Cache-Control by exact directive set (order-independent).
 * Symfony may reorder directives; production header semantics stay unchanged.
 *
 * @param  \Illuminate\Testing\TestResponse|\Illuminate\Http\Response  $response
 * @param  array<int, string>  $expectedDirectives
 */
function assertExactCacheControlDirectives($response, array $expectedDirectives): void
{
    $header = $response->headers->get('Cache-Control') ?? '';

    $normalize = static function (array $directives): array {
        return collect($directives)
            ->map(fn (string $directive) => strtolower(trim($directive)))
            ->filter(fn (string $directive) => $directive !== '')
            ->sort()
            ->values()
            ->all();
    };

    $actual = $normalize(explode(',', $header));
    $expected = $normalize($expectedDirectives);

    expect($actual)->toBe($expected);
}

function assignUserCoupon(\App\Models\User $user, \App\Models\Coupon $coupon, ?\DateTimeInterface $usedAt = null): \App\Models\CouponUser
{
    return \App\Models\CouponUser::query()->create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'assigned_at' => now(),
        'used_at' => $usedAt,
    ]);
}

function createBalanceCouponForUser(\App\Models\User $user, string $code, int $remainingCents, array $attributes = []): \App\Models\Coupon
{
    $coupon = \App\Models\Coupon::factory()->create(array_merge([
        'code' => $code,
        'remaining_cents' => $remainingCents,
        'amount_cents' => $remainingCents,
    ], $attributes));

    assignUserCoupon($user, $coupon);

    return $coupon;
}

function setupAkubicaCheckoutDraft(\App\Models\User $user, string $brand = 'olab'): array
{
    $contact = \App\Models\Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = \App\Models\Address::factory()->create(['customer_id' => $user->customer->id]);

    \App\Models\LaboratoryCheckoutDraft::query()->updateOrCreate(
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

    return [$contact, $address];
}

function createAkubicaLaboratoryPurchase(
    \App\Models\User $user,
    array $attributes = [],
): \App\Models\LaboratoryPurchase {
    $gdaConsecutivo = $attributes['gda_consecutivo'] ?? nextAkubicaGdaConsecutivo();

    return \App\Models\LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => \App\Enums\LaboratoryBrand::OLAB,
        'gda_order_id' => 'GDA-'.fake()->unique()->numerify('######'),
        'gda_consecutivo' => $gdaConsecutivo,
        'name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'López',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-15',
        'gender' => \App\Enums\Gender::MALE,
        'street' => 'Av. Principal',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 35000,
    ], $attributes));
}

function storeFakePdf(string $path, string $content = '%PDF-1.4 fake pdf content'): void
{
    \Illuminate\Support\Facades\Storage::put($path, $content);
}

function createAkubicaLaboratoryInvoice(
    \App\Models\LaboratoryPurchase $order,
    ?string $storagePath = null,
): \App\Models\Invoice {
    $storagePath ??= 'invoices/test-'.$order->id.'.pdf';
    storeFakePdf($storagePath);

    return \App\Models\Invoice::query()->create([
        'invoiceable_type' => \App\Models\LaboratoryPurchase::class,
        'invoiceable_id' => $order->id,
        'invoice' => $storagePath,
    ]);
}

function createAkubicaResultsNotification(
    \App\Models\LaboratoryPurchase $order,
    ?string $pdfContent = null,
): \App\Models\LaboratoryNotification {
    $pdfContent ??= '%PDF-1.4 notification results';
    $gdaConsecutivo = $order->gda_consecutivo ?? nextAkubicaGdaConsecutivo();

    return \App\Models\LaboratoryNotification::query()->create([
        'notification_type' => \App\Models\LaboratoryNotification::TYPE_RESULTS,
        'lineanegocio' => \App\Models\LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
        'laboratory_purchase_id' => $order->id,
        'gda_order_id' => $order->gda_order_id,
        'gda_consecutivo' => $gdaConsecutivo,
        'status' => \App\Models\LaboratoryNotification::STATUS_PROCESSED,
        'payload' => [
            'id' => $gdaConsecutivo,
        ],
        'results_received_at' => now(),
        'results_pdf_base64' => base64_encode($pdfContent),
    ]);
}

function medicalAttentionUser(array $customerAttributes = []): \App\Models\User
{
    $user = \App\Models\User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ]);

    if ($customerAttributes !== []) {
        $user->customer->update($customerAttributes);
    }

    return $user->fresh(['customer']);
}
