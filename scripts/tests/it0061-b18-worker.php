<?php

declare(strict_types=1);

if (getenv('APP_ENV') !== 'testing' || getenv('FAMEDIC_RC2_LOCAL_MYSQL') !== '1' || getenv('FAMEDIC_V2_PREPARE_SCHEMA') !== '0') {
    throw new RuntimeException('Unsafe IT-0061 B18 worker environment');
}

$base = getenv('APP_BASE_PATH');
if (! is_string($base) || $base === '') {
    throw new RuntimeException('APP_BASE_PATH required');
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->bind(\App\Contracts\Api\V1\Auth\BackendCredentialSource::class, \Tests\Support\Api\V1\Identity\LocalMachineCredentialSource::class);

$input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (! is_array($input) || ! isset($input['ready'], $input['go'], $input['user_id'], $input['bearer'], $input['machine'])) {
    throw new RuntimeException('Invalid worker input');
}
file_put_contents($input['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($input['go'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('B18 barrier timeout');
    }
    usleep(20000);
}

$request = \Illuminate\Http\Request::create(
    '/api/v1/cart/items',
    'POST',
    [],
    [],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['bearer'],
        'HTTP_X_SYNTHETIC_MACHINE_CREDENTIAL' => $input['machine'],
        'HTTP_IDEMPOTENCY_KEY' => 'it0061-b18-'.$input['worker'],
    ],
    json_encode(['service_line' => 'medical_services', 'item_type' => 'membership', 'membership_plan_code' => 'basic_annual', 'quantity' => 1], JSON_THROW_ON_ERROR),
);
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$payload = json_decode($response->getContent(), true);
echo json_encode([
    'status' => $response->getStatusCode(),
    'error_code' => is_array($payload) ? ($payload['error']['code'] ?? null) : null,
    'has_item' => is_array($payload) && isset($payload['data']['items']),
], JSON_THROW_ON_ERROR)."\n";
$kernel->terminate($request, $response);
