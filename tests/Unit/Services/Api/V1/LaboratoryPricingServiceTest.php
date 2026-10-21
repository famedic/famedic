<?php

use App\Models\LaboratoryTest;
use App\Services\Api\V1\LaboratoryPricingService;

test('laboratory pricing marks null public price as unavailable without savings', function () {
    $test = new LaboratoryTest([
        'public_price_cents' => null,
        'famedic_price_cents' => 35000,
    ]);

    $pricing = app(LaboratoryPricingService::class)->for($test);

    expect($pricing['comparison_status'])->toBe('public_price_unavailable')
        ->and($pricing['savings_cents'])->toBeNull()
        ->and($pricing['savings_percentage'])->toBeNull();
});
