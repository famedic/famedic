<?php
// Execute pure serializers/services with in-memory models. No app bootstrap/DB.
require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $container = new Illuminate\Container\Container;
    Illuminate\Container\Container::setInstance($container);
    $container->instance('config', new Illuminate\Config\Repository([
        'api_v1' => ['pricing' => ['source_available' => true]],
    ]));
    $container->bind('db', static function () { throw new RuntimeException('DB_FORBIDDEN'); });
    $model = static function (string $class, array $attributes) {
        // Avoid booting observers; only exercise the serializer's attribute reads.
        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $instance->setRawAttributes($attributes);
        return $instance;
    };
    $test = $model(App\Models\LaboratoryTest::class, ['famedic_price_cents' => 13700, 'public_price_cents' => null]);
    $pricing = (new App\Services\Api\V1\LaboratoryPricingService)->for($test);
    $result = ['pricing_nulls' => []];
    foreach (['public_price_cents','savings_cents','savings_percentage','tax_included','effective_at','valid_until'] as $key) {
        $result['pricing_nulls'][$key] = array_key_exists($key, $pricing) && $pricing[$key] === null;
    }
    $user = $model(App\Models\User::class, ['name' => '']);
    $lookup = App\Support\Api\V1\AtixPhoneLookupRecognitionPayload::recognized($user, true);
    $result['lookup_omits_unavailable_first_name'] = !array_key_exists('first_name', $lookup['personalization']);
    $order = $model(App\Models\LaboratoryPurchase::class, ['id' => 1, 'total_cents' => 100]);
    $invoiceRequest = $model(App\Models\InvoiceRequest::class, ['id' => 1]);
    $invoice = (new App\Http\Resources\Api\V1\OrderInvoiceResource(null, $order, null, $invoiceRequest, false))
        ->toArray(new Illuminate\Http\Request);
    $result['invoice_uuid_is_null'] = $invoice['uuid'] === null;
    $result['invoice_issued_at_is_null_when_pending'] = $invoice['issued_at'] === null;
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: pure serializer harness; values omitted\n");
    exit(1);
}
