<?php

use App\Enums\CouponPurchaseType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\Transaction;
use App\Models\User;

/**
 * @return array{user: User, purchase: LaboratoryPurchase, coupon: Coupon, couponTransaction: CouponTransaction}
 */
if (! function_exists('createConsumedCouponLabPurchase')) {
function createConsumedCouponLabPurchase(
    int $totalCents = 100_000,
    int $discountCents = 50_000,
    ?string $paymentMethod = 'coupon_balance',
): array {
    $user = User::factory()->withRegularCustomer()->create();
    $customer = $user->customer;

    $coupon = Coupon::factory()->create([
        'amount_cents' => $discountCents,
        'remaining_cents' => 0,
    ]);

    CouponUser::create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'assigned_at' => now(),
        'used_at' => now(),
    ]);

    $purchase = LaboratoryPurchase::create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
        'maternal_lastname' => 'Test',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE->value,
        'street' => 'Calle Test',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'NL',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => $totalCents,
        'coupon_discount_cents' => $discountCents,
        'customer_id' => $customer->id,
    ]);

    $couponTransaction = CouponTransaction::create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'purchase_type' => CouponPurchaseType::Lab,
        'purchase_id' => $purchase->id,
        'amount_used_cents' => $discountCents,
    ]);

    if ($paymentMethod !== null) {
        $chargedCents = max(0, $totalCents - $discountCents);
        $transaction = Transaction::create([
            'transaction_amount_cents' => $chargedCents,
            'payment_method' => $paymentMethod,
            'gateway' => $paymentMethod,
            'reference_id' => 'TEST-'.$purchase->id,
            'gateway_status' => 'completed',
            'details' => [
                'coupon_id' => $coupon->id,
                'coupon_amount_cents' => $discountCents,
                'original_total_cents' => $totalCents,
                'amount_charged_cents' => $chargedCents,
                'customer_id' => $customer->id,
            ],
        ]);

        $purchase->transactions()->attach($transaction);
    }

    LaboratoryPurchaseItem::create([
        'laboratory_purchase_id' => $purchase->id,
        'name' => 'Estudio',
        'indications' => 'N/A',
        'gda_id' => 'GDA-1',
        'price_cents' => $totalCents,
    ]);

    return compact('user', 'purchase', 'coupon', 'couponTransaction');
}
}
