<?php

test('servicios de cupones y notificaciones están registrados', function () {
    expect(class_exists(\App\Services\CouponService::class))->toBeTrue();
    expect(class_exists(\App\Services\CouponApplicationService::class))->toBeTrue();
    expect(class_exists(\App\Services\NotificationService::class))->toBeTrue();
});
