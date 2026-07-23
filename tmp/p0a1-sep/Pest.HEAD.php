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
    $token = $user->createToken('akubica-test')->plainTextToken;

    return [$user, $token];
}

function authHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

function createOlabTest(array $attributes = []): \App\Models\LaboratoryTest
{
    return \App\Models\LaboratoryTest::factory()->create(array_merge([
        'brand' => \App\Enums\LaboratoryBrand::OLAB,
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
    return \App\Models\LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => \App\Enums\LaboratoryBrand::OLAB,
        'gda_order_id' => 'GDA-'.fake()->unique()->numerify('######'),
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

    return \App\Models\LaboratoryNotification::query()->create([
        'notification_type' => \App\Models\LaboratoryNotification::TYPE_RESULTS,
        'lineanegocio' => \App\Models\LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
        'laboratory_purchase_id' => $order->id,
        'gda_order_id' => $order->gda_order_id,
        'gda_consecutivo' => (string) ($order->gda_consecutivo ?? $order->gda_order_id),
        'status' => \App\Models\LaboratoryNotification::STATUS_PROCESSED,
        'payload' => [],
        'results_received_at' => now(),
        'results_pdf_base64' => base64_encode($pdfContent),
    ]);
}
