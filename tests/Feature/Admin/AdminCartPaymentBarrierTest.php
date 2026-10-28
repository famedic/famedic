<?php

use App\Enums\CartEventType;
use App\Enums\GdaOrderStatus;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Laboratory\UncertainGdaPaymentGuard;
use App\Services\PayPalService;
use Spatie\Permission\Models\Permission;

function paymentBarrierLabCart(User $customerUser): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
    ]);

    $cart = Cart::query()->create([
        'user_id' => $customerUser->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);

    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => (string) $test->id,
        'name' => 'Biometria hematica',
        'price' => 1000.00,
        'quantity' => 1,
    ]);

    return $cart;
}

function paymentBarrierAdmin(bool $withReleasePermission = true): User
{
    Permission::findOrCreate('view cart details', 'web');
    Permission::findOrCreate('release cart payment barrier', 'web');

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');
    if ($withReleasePermission) {
        $user->administrator->givePermissionTo('release cart payment barrier');
    }

    return $user;
}

it('exposes checkout payment barriers in cart 360 detail', function () {
    $admin = paymentBarrierAdmin();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = paymentBarrierLabCart($customerUser);

    PaymentAttempt::query()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'reference' => 'EFEVOO-BLOCK-QA',
        'status' => PaymentAttempt::STATUS_ERROR,
        'processor_message' => 'Timeout',
        'processed_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.payment_barrier.blocked', true)
        ->assertJsonPath('data.payment_barrier.can_release', true)
        ->assertJsonPath('data.payment_barrier.barriers.0.code', 'payment_attempt_error');
});

it('releases a technical Efevoo payment barrier and allows checkout again', function () {
    $admin = paymentBarrierAdmin();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = paymentBarrierLabCart($customerUser);

    $attempt = PaymentAttempt::query()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'reference' => 'EFEVOO-RELEASE-QA',
        'status' => PaymentAttempt::STATUS_ERROR,
        'processor_message' => 'Timeout',
        'processed_at' => now()->subMinutes(5),
    ]);

    $guard = app(UncertainGdaPaymentGuard::class);
    expect(fn () => $guard->withCartLock($cart, fn () => 'blocked'))
        ->toThrow(\App\Exceptions\LaboratoryPaymentInProgressException::class);

    $this->actingAs($admin);

    $this->postJson(route('admin.carts.release-payment-barrier', $cart), [
        'note' => 'Sin cargo en Efevoo',
    ])
        ->assertOk()
        ->assertJsonPath('data.payment_barrier.blocked', false)
        ->assertJsonFragment(['payment_attempt:'.$attempt->id]);

    expect($attempt->fresh()->status)->toBe(PaymentAttempt::STATUS_DECLINED)
        ->and($guard->withCartLock($cart->fresh(), fn () => 'allowed'))->toBe('allowed');

    expect(CartEvent::query()
        ->where('cart_id', $cart->id)
        ->where('event', CartEventType::PaymentBarrierReleased->value)
        ->exists())->toBeTrue();
});

it('forbids releasing payment barriers without permission', function () {
    $admin = paymentBarrierAdmin(withReleasePermission: false);
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = paymentBarrierLabCart($customerUser);

    PaymentAttempt::query()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'reference' => 'EFEVOO-FORBIDDEN-QA',
        'status' => PaymentAttempt::STATUS_ERROR,
        'processed_at' => now(),
    ]);

    $this->actingAs($admin);

    $this->postJson(route('admin.carts.release-payment-barrier', $cart))
        ->assertForbidden();
});

it('does not allow releasing a paid uncertain purchase barrier', function () {
    $admin = paymentBarrierAdmin();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = paymentBarrierLabCart($customerUser);

    $purchase = LaboratoryPurchase::query()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-uncertain-qa',
        'gda_status' => GdaOrderStatus::Uncertain,
        'name' => 'Paciente',
        'paternal_lastname' => 'Compra',
        'maternal_lastname' => 'Incerta',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 100000,
    ]);

    $transaction = Transaction::query()->create([
        'transaction_amount_cents' => 100000,
        'payment_method' => 'paypal',
        'payment_status' => 'captured',
        'reference_id' => 'PAYPAL-UNCERTAIN-QA',
        'gateway_transaction_id' => 'CAP-UNCERTAIN-QA',
    ]);
    $purchase->transactions()->attach($transaction->id);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.payment_barrier.blocked', true)
        ->assertJsonPath('data.payment_barrier.can_release', false)
        ->assertJsonPath('data.payment_barrier.barriers.0.code', 'paid_uncertain_purchase');

    $this->postJson(route('admin.carts.release-payment-barrier', $cart))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cart']);
});

it('releases an interrupted PayPal capture barrier when no remote capture exists', function () {
    $admin = paymentBarrierAdmin();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = paymentBarrierLabCart($customerUser);

    $paypal = Mockery::mock(PayPalService::class);
    $paypal->shouldReceive('getOrder')->once()->with('PAYPAL-PENDING-QA')->andReturn(['status' => 'APPROVED']);
    $paypal->shouldReceive('extractCaptureInfo')->andReturn(['capture_id' => null, 'status' => 'APPROVED']);
    app()->instance(PayPalService::class, $paypal);

    Transaction::query()->create([
        'transaction_amount_cents' => 100000,
        'payment_method' => 'paypal',
        'payment_status' => 'pending',
        'reference_id' => 'PAYPAL-PENDING-QA',
        'provider_order_id' => 'PAYPAL-PENDING-QA',
        'details' => [
            'cart_id' => $cart->id,
            'customer_id' => $customerUser->customer->id,
            'laboratory_capture_started_at' => now()->subMinutes(3)->toIso8601String(),
        ],
    ]);

    $this->actingAs($admin);

    $this->postJson(route('admin.carts.release-payment-barrier', $cart))
        ->assertOk()
        ->assertJsonPath('data.payment_barrier.blocked', false);
});
