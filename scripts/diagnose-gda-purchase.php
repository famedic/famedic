<?php

use App\Actions\Laboratories\CreateReplacementGdaLaboratoryPurchaseAction;
use App\Actions\Laboratories\RecoverUncertainGdaLaboratoryPurchaseAction;
use App\Models\CartEvent;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$purchaseId = (int) ($argv[1] ?? 2472);

$purchase = LaboratoryPurchase::withTrashed()->with([
    'transactions',
    'laboratoryPurchaseItems',
    'customer.user',
    'laboratoryAppointment.laboratoryStore',
    'replacementLaboratoryPurchase',
])->find($purchaseId);

if ($purchase === null) {
    echo json_encode(['error' => 'purchase_not_found', 'id' => $purchaseId], JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}

$recover = app(RecoverUncertainGdaLaboratoryPurchaseAction::class);
$replace = app(CreateReplacementGdaLaboratoryPurchaseAction::class);
$resolvedAppointment = $recover->resolveSourceAppointmentForAdmin($purchase);

$replacementAttempts = LaboratoryPurchase::query()
    ->where('replaces_laboratory_purchase_id', $purchaseId)
    ->orderByDesc('id')
    ->get()
    ->map(fn (LaboratoryPurchase $p) => [
        'id' => $p->id,
        'gda_status' => $p->gda_status?->value,
        'gda_order_id' => $p->gda_order_id,
        'gda_consecutivo' => $p->gda_consecutivo,
        'gda_description' => $p->gda_description,
        'gda_mensaje' => $p->gda_mensaje,
        'gda_code_http' => $p->gda_code_http,
        'created_at' => optional($p->created_at)->toDateTimeString(),
    ])
    ->values()
    ->all();

$customerAppointments = [];
if ($purchase->customer_id) {
    $customerAppointments = LaboratoryAppointment::withTrashed()
        ->with('laboratoryStore')
        ->where('customer_id', $purchase->customer_id)
        ->where('brand', $purchase->brand->value)
        ->orderByDesc('id')
        ->limit(5)
        ->get()
        ->map(fn (LaboratoryAppointment $a) => [
            'id' => $a->id,
            'laboratory_purchase_id' => $a->laboratory_purchase_id,
            'cart_id' => $a->cart_id,
            'store' => $a->laboratoryStore?->name,
            'date' => $a->formatted_appointment_date,
            'confirmed_at' => optional($a->confirmed_at)->toDateTimeString(),
            'deleted_at' => optional($a->deleted_at)->toDateTimeString(),
        ])
        ->values()
        ->all();
}

$cartEvents = [];
if (Schema::hasTable('cart_events')) {
    $cartId = $purchase->cart_id;
    if ($cartId) {
        $cartEvents = CartEvent::query()
            ->where('cart_id', $cartId)
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (CartEvent $e) => [
                'id' => $e->id,
                'event' => $e->event?->value ?? (string) $e->event,
                'occurred_at' => optional($e->occurred_at)->toDateTimeString(),
                'metadata' => $e->metadata,
            ])
            ->values()
            ->all();
    }
}

echo json_encode([
    'environment' => [
        'app_env' => config('app.env'),
        'gda_url' => config('services.gda.url'),
        'gda_api_path' => config('services.gda.api_path'),
        'gda_force_real_api' => config('services.gda.force_real_api'),
        'should_simulate_orders' => \App\Support\GDA\GdaApiUrl::shouldSimulateOrders(),
    ],
    'purchase' => [
        'id' => $purchase->id,
        'customer_id' => $purchase->customer_id,
        'user_email' => $purchase->customer?->user?->email,
        'gda_status' => $purchase->gda_status?->value,
        'gda_order_id' => $purchase->gda_order_id,
        'gda_consecutivo' => $purchase->gda_consecutivo,
        'gda_description' => $purchase->gda_description,
        'gda_mensaje' => $purchase->gda_mensaje,
        'gda_code_http' => $purchase->gda_code_http,
        'gda_warning_message' => $purchase->gda_warning_message,
        'has_gda_warning' => $purchase->has_gda_warning,
        'cart_id' => $purchase->cart_id ?? null,
        'replacement_laboratory_purchase_id' => $purchase->replacement_laboratory_purchase_id,
        'total_cents' => $purchase->total_cents,
        'created_at' => optional($purchase->created_at)->toDateTimeString(),
        'items' => $purchase->laboratoryPurchaseItems->map(fn ($i) => [
            'name' => $i->name,
            'gda_id' => $i->gda_id,
            'price_cents' => $i->price_cents,
        ])->values()->all(),
        'transaction' => $purchase->transactions->first()?->only([
            'id', 'payment_method', 'payment_status', 'gateway_status', 'transaction_amount_cents',
        ]),
    ],
    'appointment' => [
        'direct' => $purchase->laboratoryAppointment ? [
            'id' => $purchase->laboratoryAppointment->id,
            'store' => $purchase->laboratoryAppointment->laboratoryStore?->name,
            'date' => $purchase->laboratoryAppointment->formatted_appointment_date,
        ] : null,
        'resolved_for_clone' => $resolvedAppointment ? [
            'id' => $resolvedAppointment->id,
            'laboratory_purchase_id' => $resolvedAppointment->laboratory_purchase_id,
            'cart_id' => $resolvedAppointment->cart_id,
            'store' => $resolvedAppointment->laboratoryStore?->name,
            'date' => $resolvedAppointment->formatted_appointment_date,
        ] : null,
        'requires_appointment' => $recover->purchaseRequiresAppointment($purchase),
        'recent_customer_appointments' => $customerAppointments,
    ],
    'eligibility' => [
        'can_recover' => $recover->canRecover($purchase),
        'can_replace' => $replace->canReplace($purchase),
        'block_reasons' => $recover->recoverBlockReasons($purchase),
        'replace_preview_eligible' => ($replace->preview($purchase)['eligible'] ?? false),
    ],
    'replacement_attempts' => $replacementAttempts,
    'cart_events' => $cartEvents,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
