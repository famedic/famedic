<?php

// Independent process harness. Never boot against a non-disposable database.
require __DIR__.'/../../vendor/autoload.php';
$test = new class('gda_p0_process') extends \Tests\TestCase {};
$app = $test->createApplication();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Notification::fake();
config(['services.laboratory_preparation.deterministic_v3_enabled' => false,
    'services.laboratory_preparation.deterministic_v3_shadow_enabled' => false]);
[$script, $operation, $transactionId, $run, $scenario] = $argv;
$transaction = \App\Models\Transaction::findOrFail($transactionId);
$audit = function (string $event) use ($run): void {
    \Illuminate\Support\Facades\DB::table('gda_p0_process_audit')->insert(['run' => $run, 'event' => $event, 'created_at' => now()]);
};
$payload = ['id' => 'CAP-'.$transactionId, 'status' => 'COMPLETED',
    'supplementary_data' => ['related_ids' => ['order_id' => $transaction->provider_order_id]]];
$paypal = \Mockery::mock(\App\Services\PayPalService::class)->makePartial();
$paypal->shouldReceive('getOrder')->andReturnUsing(function () use ($audit, $payload, $run) {
    $audit('lookup');
    return \Illuminate\Support\Facades\DB::table('gda_p0_process_audit')->where('run', $run)->where('event', 'capture')->exists()
        ? $payload : ['id' => 'ORDER-QA', 'status' => 'APPROVED'];
});
$paypal->shouldReceive('captureOrder')->andReturnUsing(function () use ($audit, $payload) {
    $audit('capture'); usleep(250000); return $payload;
});
$paypal->shouldReceive('refund')->andReturnUsing(function () use ($audit) { $audit('refund'); return []; });
$app->instance(\App\Services\PayPalService::class, $paypal);
$gda = \Mockery::mock(\App\Actions\Laboratories\CreateGDAQuotationAction::class);
$gda->shouldReceive('__invoke')->andReturnUsing(function () use ($audit, $scenario) {
    $audit('gda'); usleep(300000);
    if ($scenario === 'crash') { exit(0); }
    if ($scenario === 'timeout') { throw new \App\Exceptions\GdaOrderResultUncertainException; }
    return ['id' => 'GDA-PROCESS-QA', 'infogda_consecutivo' => 987654];
});
$app->instance(\App\Actions\Laboratories\CreateGDAQuotationAction::class, $gda);
$marketing = \Mockery::mock(\App\Actions\Marketing\RecordMarketingCampaignConversionAction::class);
$marketing->shouldReceive('__invoke')->andReturnUsing(function () use ($audit) {
    $audit('conversion');
    return new \App\DataTransferObjects\Marketing\RecordMarketingCampaignConversionResult(\App\Enums\MarketingCampaignConversionStatus::NotAttributed);
});
$app->instance(\App\Actions\Marketing\RecordMarketingCampaignConversionAction::class, $marketing);
$automation = \Mockery::mock(\App\Services\Orders\OrderAutomationService::class)->makePartial();
$automation->shouldReceive('contextForLaboratory')->andReturnUsing(function () use ($audit) {
    $audit('automation'); throw new \RuntimeException('QA automation intentionally stopped before all drivers');
});
$app->instance(\App\Services\Orders\OrderAutomationService::class, $automation);
if ($scenario === 'capture_persistence') {
    \App\Models\Transaction::updating(function ($transaction) {
        if ($transaction->payment_status === 'captured') { throw new \RuntimeException('AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML'); }
    });
}
if ($scenario === 'persistence') {
    \App\Models\LaboratoryPurchase::updating(function ($purchase) {
        if ($purchase->gda_status === \App\Enums\GdaOrderStatus::Confirmed) {
            throw new \RuntimeException('AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML');
        }
    });
}
$charge = \Mockery::mock(\App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction::class);
$charge->shouldReceive('__invoke')->andReturnUsing(function () use ($audit, $transaction) {
    $audit('charge'); usleep(250000);
    $transaction->update(['payment_method' => 'efevoopay', 'payment_status' => 'completed',
        'gateway_status' => 'completed', 'gateway_transaction_id' => 'QA-CAPTURED-'.$transaction->id]);
    return $transaction->fresh();
});
$app->instance(\App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction::class, $charge);
// Every child is fully booted before the parent opens the barrier.
$audit('ready');
$deadline = microtime(true) + 15;
while (! \Illuminate\Support\Facades\DB::table('gda_p0_process_audit')->where('run', $run)->where('event', 'start')->exists()) {
    if (microtime(true) > $deadline) { throw new \RuntimeException('QA process barrier timed out'); }
    usleep(20000);
}
try {
    if ($operation === 'checkout') {
        $details = $transaction->details;
        $app->make(\App\Actions\Laboratories\OrderAction::class)(
            \App\Models\Customer::findOrFail($details['customer_id']),
            \App\Models\Address::findOrFail($details['address_id']), \App\Models\Contact::findOrFail($details['contact_id']),
            'QA-PAYMENT', \App\Enums\LaboratoryBrand::OLAB, 39900);
    } elseif ($operation === 'webhook') {
        $app->make(\App\Actions\PayPal\HandlePayPalWebhookAction::class)(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $payload]);
    } elseif ($operation === 'callback') {
        $customer = \App\Models\Customer::findOrFail($transaction->details['customer_id']);
        $app->make(\App\Actions\PayPal\CapturePayPalOrderAction::class)($transaction->provider_order_id, $customer);
    } else {
        $app->make(\App\Actions\PayPal\FinalizeLaboratoryPayPalPaymentAction::class)($transaction, $payload);
    }
    $audit('done');
} catch (\App\Exceptions\GdaOrderResultUncertainException) {
    $audit('uncertain');
}
if (in_array($scenario, ['timeout', 'persistence', 'capture_persistence'], true)) {
    \Illuminate\Support\Facades\Notification::assertNothingSent();
}


$customer = \App\Models\Customer::findOrFail($transaction->details['customer_id']);
foreach (\Illuminate\Support\Facades\Notification::sent($customer->user, \App\Notifications\LaboratoryPurchaseCreated::class) as $sent) {
    $audit('notification');
}
