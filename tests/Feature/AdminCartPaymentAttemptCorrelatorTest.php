<?php

use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Carts\CartPaymentAttemptCorrelator;

it('correlates a declined Efevoo attempt by customer amount and cart window', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $customer = $user->customer;

    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1200.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);

    $attempt = new PaymentAttempt([
        'customer_id' => $customer->id,
        'amount_cents' => 120000,
        'gateway' => 'efevoopay',
        'reference' => 'LAB-'.$customer->id.'-test',
        'status' => PaymentAttempt::STATUS_DECLINED,
        'processor_code' => '87',
        'processor_message' => 'Transaccion declinada por banco',
        'processed_at' => now()->subMinutes(18),
    ]);
    $attempt->created_at = now()->subMinutes(19);
    $attempt->updated_at = now()->subMinutes(18);
    $attempt->save();

    $insights = app(CartPaymentAttemptCorrelator::class)->forCarts(
        collect([$cart->fresh('user.customer')]),
    );

    expect($insights[$cart->id]['confidence'])->toBe('legacy_high')
        ->and($insights[$cart->id]['status'])->toBe(PaymentAttempt::STATUS_DECLINED)
        ->and($insights[$cart->id]['attempts_count'])->toBe(1)
        ->and($insights[$cart->id]['last_attempt']['processor_code'])->toBe('87')
        ->and($insights[$cart->id]['should_display'])->toBeTrue();
});

it('prefers explicit cart_id payment attempts over same amount legacy ambiguity', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $customer = $user->customer;

    $firstCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 950.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);

    $secondCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 950.00,
        'created_at' => now()->subMinutes(50),
        'updated_at' => now()->subMinutes(10),
    ]);

    PaymentAttempt::query()->create([
        'customer_id' => $customer->id,
        'cart_id' => $firstCart->id,
        'amount_cents' => 95000,
        'gateway' => 'efevoopay',
        'reference' => 'LAB-'.$customer->id.'-explicit',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'processed_at' => now()->subMinutes(8),
    ]);

    $insights = app(CartPaymentAttemptCorrelator::class)->forCarts(
        collect([
            $firstCart->fresh('user.customer'),
            $secondCart->fresh('user.customer'),
        ]),
    );

    expect($insights[$firstCart->id]['confidence'])->toBe('explicit')
        ->and($insights[$firstCart->id]['status'])->toBe(PaymentAttempt::STATUS_APPROVED)
        ->and($insights[$firstCart->id]['should_display'])->toBeTrue()
        ->and($insights)->not->toHaveKey($secondCart->id);
});

it('does not assert a payment when one attempt fits multiple carts', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $customer = $user->customer;

    $firstCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 950.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);

    $secondCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 950.00,
        'created_at' => now()->subMinutes(50),
        'updated_at' => now()->subMinutes(10),
    ]);

    $attempt = new PaymentAttempt([
        'customer_id' => $customer->id,
        'amount_cents' => 95000,
        'gateway' => 'efevoopay',
        'reference' => 'LAB-'.$customer->id.'-ambiguous',
        'status' => PaymentAttempt::STATUS_ERROR,
        'processor_message' => 'Timeout',
        'processed_at' => now()->subMinutes(30),
    ]);
    $attempt->created_at = now()->subMinutes(31);
    $attempt->updated_at = now()->subMinutes(30);
    $attempt->save();

    $insights = app(CartPaymentAttemptCorrelator::class)->forCarts(
        collect([
            $firstCart->fresh('user.customer'),
            $secondCart->fresh('user.customer'),
        ]),
    );

    expect($insights[$firstCart->id]['confidence'])->toBe('ambiguous')
        ->and($insights[$firstCart->id]['should_display'])->toBeFalse()
        ->and($insights[$secondCart->id]['confidence'])->toBe('ambiguous')
        ->and($insights[$secondCart->id]['should_display'])->toBeFalse();
});
