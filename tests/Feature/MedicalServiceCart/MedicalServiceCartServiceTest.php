<?php

use App\Models\LaboratoryCartItem;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Models\OnlinePharmacyCartItem;
use App\Models\User;
use App\Services\Api\V1\MedicalServiceCartService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function medicalServiceCartForTest(): array
{
    $user = User::factory()->withRegularCustomer()->create();

    return [$user, $user->customer, app(MedicalServiceCartService::class)];
}

test('creates one active medical services cart for a customer', function () {
    [, $customer, $service] = medicalServiceCartForTest();

    $cart = $service->activeCart($customer);

    expect($cart->service_line)->toBe('medical_services')
        ->and($cart->status)->toBe('active')
        ->and(MedicalServiceCart::query()->where('customer_id', $customer->id)->count())->toBe(1);
});

test('resolves the same active medical services cart and database constraint rejects another one', function () {
    [, $customer, $service] = medicalServiceCartForTest();

    $first = $service->activeCart($customer);
    $second = $service->activeCart($customer);

    expect($second->is($first))->toBeTrue();

    expect(fn () => MedicalServiceCart::query()->create([
        'customer_id' => $customer->id,
        'service_line' => 'medical_services',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

test('adds basic annual membership using the authoritative catalog price', function () {
    [, $customer, $service] = medicalServiceCartForTest();

    $cart = $service->addBasicPlan($customer);
    $item = $cart->items->sole();

    expect($item->membership_plan_code)->toBe('basic_annual')
        ->and($item->name)->toBe('Plan Básico')
        ->and($item->quantity)->toBe(1)
        ->and($item->unit_price_cents)->toBe(30000)
        ->and($item->subtotal_cents)->toBe(30000)
        ->and($cart->subtotal_cents)->toBe(30000)
        ->and($cart->discount_cents)->toBe(0)
        ->and($cart->credit_applied_cents)->toBe(0)
        ->and($cart->total_cents)->toBe(30000);
});

test('rejects a duplicate basic annual membership in the same cart', function () {
    [, $customer, $service] = medicalServiceCartForTest();

    $service->addBasicPlan($customer);

    expect(fn () => $service->addBasicPlan($customer))
        ->toThrow(RuntimeException::class);
});

test('rejects membership quantities different from one', function () {
    [, $customer, $service] = medicalServiceCartForTest();

    expect(fn () => $service->addBasicPlan($customer, 2))
        ->toThrow(InvalidArgumentException::class);
});

test('keeps medical service cart data isolated from laboratory and pharmacy carts', function () {
    [, $customer, $service] = medicalServiceCartForTest();
    LaboratoryCartItem::factory()->create(['customer_id' => $customer->id]);
    OnlinePharmacyCartItem::factory()->create(['customer_id' => $customer->id]);

    $cart = $service->addBasicPlan($customer);

    expect($cart->service_line)->toBe(MedicalServiceCart::SERVICE_LINE)
        ->and($cart->items)->toHaveCount(1)
        ->and($customer->laboratoryCartItems()->count())->toBe(1)
        ->and($customer->onlinePharmacyCartItems()->count())->toBe(1)
        ->and(MedicalServiceCartItem::query()->count())->toBe(1);
});

test('does not resolve or mutate another customer medical cart', function () {
    [, $owner, $service] = medicalServiceCartForTest();
    [, $other] = medicalServiceCartForTest();

    $ownerCart = $service->addBasicPlan($owner);
    $otherCart = $service->activeCart($other);

    expect($otherCart->id)->not->toBe($ownerCart->id)
        ->and($otherCart->items()->count())->toBe(0)
        ->and($ownerCart->fresh()->total_cents)->toBe(30000);
});

test('medical service migrations expose the dedicated schema and constraints', function () {
    expect(Schema::hasTable('medical_service_carts'))->toBeTrue()
        ->and(Schema::hasTable('medical_service_cart_items'))->toBeTrue()
        ->and(Schema::hasColumns('medical_service_carts', [
            'customer_id', 'service_line', 'status', 'subtotal_cents',
            'discount_cents', 'credit_applied_cents', 'total_cents',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('medical_service_cart_items', [
            'cart_id', 'item_type', 'membership_plan_code', 'name', 'quantity',
            'unit_price_cents', 'subtotal_cents', 'requires_appointment',
        ]))->toBeTrue();
});
