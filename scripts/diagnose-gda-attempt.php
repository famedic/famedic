<?php

use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\LaboratoryPurchase;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$purchaseId = (int) ($argv[1] ?? 2472);

$purchase = LaboratoryPurchase::withTrashed()->find($purchaseId);
$userId = $purchase?->customer?->user_id;

$coupons = $userId
    ? CouponUser::query()->where('user_id', $userId)->with('coupon')->get()
    : collect();

echo json_encode([
    'gda_config' => [
        'url' => config('services.gda.url'),
        'api_path' => config('services.gda.api_path'),
        'olab_agreement_id' => config('services.gda.brands.olab.brand_agreement_id'),
        'olab_brand_id' => config('services.gda.brands.olab.brand_id'),
        'force_real_api' => config('services.gda.force_real_api'),
    ],
    'purchase' => $purchase ? [
        'id' => $purchase->id,
        'gda_status' => $purchase->gda_status?->value,
        'gda_order_id' => $purchase->gda_order_id,
        'gda_consecutivo' => $purchase->gda_consecutivo,
        'gda_description' => $purchase->gda_description,
        'gda_mensaje' => $purchase->gda_mensaje,
        'coupon_discount_cents' => $purchase->coupon_discount_cents,
        'replacement_id' => $purchase->replacement_laboratory_purchase_id,
    ] : null,
    'orphan_replacements' => LaboratoryPurchase::query()
        ->where('replaces_laboratory_purchase_id', $purchaseId)
        ->get(['id', 'gda_status', 'gda_order_id', 'created_at'])
        ->all(),
    'user_coupons' => $coupons->map(fn ($cu) => [
        'coupon_id' => $cu->coupon_id,
        'remaining_cents' => $cu->coupon?->remaining_cents,
        'amount_cents' => $cu->coupon?->amount_cents,
        'concept' => $cu->coupon?->concept,
    ])->values()->all(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
