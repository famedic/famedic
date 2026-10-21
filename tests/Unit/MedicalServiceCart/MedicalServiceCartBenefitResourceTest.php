<?php

use App\Http\Resources\Api\V1\MedicalServiceCartBenefitResource;
use App\Models\Coupon;
use App\Models\MedicalServiceCartBenefit;
use Illuminate\Http\Request;

test('medical benefit resource exposes only the applied amount', function () {
    $coupon = new Coupon([
        'id' => 44,
        'code' => 'M2C-UNIT',
        'description' => 'Beneficio de prueba',
        'remaining_cents' => 90000,
    ]);

    $benefit = new MedicalServiceCartBenefit([
        'id' => 7,
        'coupon_id' => 44,
        'benefit_type' => 'credit',
        'applied_cents' => 30000,
    ]);
    $benefit->setRelation('coupon', $coupon);

    $payload = (new MedicalServiceCartBenefitResource($benefit))->toArray(Request::create('/'));

    expect($payload)->toMatchArray([
        'id' => 7,
        'type' => 'credit',
        'coupon_id' => 44,
        'code' => 'M2C-UNIT',
        'description' => 'Beneficio de prueba',
        'applied_cents' => 30000,
        'currency' => 'MXN',
    ])->and($payload)->not->toHaveKey('remaining_cents');
});
