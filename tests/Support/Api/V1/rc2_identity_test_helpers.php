<?php

if (! function_exists('createOlabTest')) {
    function createOlabTest(array $attributes = []): \App\Models\LaboratoryTest
    {
        return \App\Models\LaboratoryTest::factory()->create(array_merge([
            'brand' => \App\Enums\LaboratoryBrand::OLAB,
            'gda_id' => 'RC2-TEST-'.bin2hex(random_bytes(6)),
            'famedic_price_cents' => 35000,
            'public_price_cents' => 40000,
        ], $attributes));
    }
}

if (! function_exists('addOlabCartItem')) {
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
}

if (! function_exists('addOlabCartItemReadyForPaymentLink')) {
    function addOlabCartItemReadyForPaymentLink(\App\Models\User $user): \App\Models\LaboratoryTest
    {
        return addOlabCartItem($user, createOlabTest([
            'requires_appointment' => false,
            'famedic_price_cents' => 35000,
            'public_price_cents' => 45000,
        ]));
    }
}
