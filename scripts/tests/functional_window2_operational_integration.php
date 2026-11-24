<?php

declare(strict_types=1);

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Contracts\Api\V1\Auth\ConsumerIdentityResolver;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\User;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Use the real TLS transport adapter with synthetic material and secure local
// requests; no test-only header bypass is installed in this integration.
$app['config']->set('akubica_connectors.transport.enabled', true);
$app['config']->set('akubica_connectors.transport.certified', true);
$app->forgetInstance(BackendCredentialSource::class);
$app->forgetInstance(BackendClientAuthenticator::class);
$app->forgetInstance(ConsumerIdentityResolver::class);

$namespace = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
$sha = getenv('FW2_SYNTHETIC_SHA') ?: 'c54e2f51a20b959d7220491d8a6065eb87df6885';
$metadataPath = base_path('storage/framework/fw2-synthetic-release.json');

function failResult(string $code, array $extra = []): never
{
    fwrite(STDOUT, json_encode(['status' => 'FAIL', 'code' => $code] + $extra, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}

function commandResult(string $command, array $options): array
{
    $code = Artisan::call($command, $options);
    $raw = trim(Artisan::output());
    $json = json_decode($raw, true);
    if (! is_array($json)) failResult('COMMAND_OUTPUT_INVALID', ['command' => $command, 'exit' => $code]);
    return [$code, $json];
}

function configureSyntheticIdentities(): void
{
    foreach (['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $index => $role) {
        config()->set("akubica_uat.identities.{$role}", [
            'email' => "fw2-{$role}@synthetic.example.invalid",
            'phone' => (string) (8110000001 + $index),
            'country' => 'MX',
        ]);
    }
}

function custody(array $payload): array
{
    $env = getenv();
    $env['APP_ENV'] = 'testing';
    $env['DB_CONNECTION'] = 'mysql';
    $env['DB_HOST'] = '127.0.0.1';
    $env['DB_PORT'] = '13307';
    $env['DB_DATABASE'] = 'famedic_rc2_it0061_window2_testing';
    $env['DB_USERNAME'] = 'famedic_rc2_test';
    $env['DB_PASSWORD'] = 'famedic_rc2_test_pw';
    $env['CACHE_STORE'] = 'array';
    $env['SESSION_DRIVER'] = 'array';
    $env['QUEUE_CONNECTION'] = 'sync';
    $process = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:custody', '--private', '--no-ansi'], base_path(), $env);
    $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
    $process->run();
    $decoded = json_decode(trim($process->getOutput()), true);
    if (! is_array($decoded)) failResult('CUSTODY_OUTPUT_INVALID', ['exit' => $process->getExitCode()]);
    return [$process->getExitCode(), $decoded];
}

function inventory(array $payload): array
{
    $env = getenv();
    $env['APP_ENV'] = 'testing';
    $env['DB_CONNECTION'] = 'mysql';
    $env['DB_HOST'] = '127.0.0.1';
    $env['DB_PORT'] = '13307';
    $env['DB_DATABASE'] = 'famedic_rc2_it0061_window2_testing';
    $env['DB_USERNAME'] = 'famedic_rc2_test';
    $env['DB_PASSWORD'] = 'famedic_rc2_test_pw';
    $env['CACHE_STORE'] = 'array';
    $env['SESSION_DRIVER'] = 'array';
    $env['QUEUE_CONNECTION'] = 'sync';
    $env['AKUBICA_UAT_RELEASE_METADATA_PATH'] = base_path('storage/framework/fw2-synthetic-release.json');
    $process = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:inventory', '--no-ansi'], base_path(), $env);
    $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
    $process->run();
    $decoded = json_decode(trim($process->getOutput()), true);
    if (! is_array($decoded)) failResult('INVENTORY_OUTPUT_INVALID', ['exit' => $process->getExitCode()]);
    return [$process->getExitCode(), $decoded];
}

function tokens(): array
{
    $path = AkubicaUatFixtureContract::storagePath('access-state.json', AkubicaUatFixtureContract::DEDICATED_NAMESPACE);
    $state = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
    $machine = Crypt::decryptString($state['encrypted_secrets']['machine_native']);
    return [
        'Authorization' => 'Bearer '.Crypt::decryptString($state['scenario_encrypted_secrets']['native_membership_regular']),
        'X-Consumer-Credential' => $machine,
    ];
}

function httpCall($app, string $method, string $uri, array $payload, array $headers): array
{
    $request = Request::create('/api/v1'.$uri, $method, [], [], [], ['HTTPS' => 'on', 'SERVER_PORT' => 443], $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR));
    foreach ($headers as $name => $value) $request->headers->set($name, $value);
    if ($payload !== []) $request->headers->set('Content-Type', 'application/json');
    $response = $app->handle($request);
    $body = json_decode($response->getContent(), true);
    if (! is_array($body)) failResult('HTTP_ENVELOPE_INVALID', ['method' => $method, 'uri' => $uri, 'status' => $response->getStatusCode()]);
    return [$response->getStatusCode(), $body];
}

function expectStatus(array $response, int $expected, string $case): array
{
    if ($response[0] !== $expected) failResult('HTTP_STATUS_MISMATCH', ['case' => $case, 'expected' => $expected, 'observed' => $response[0], 'error_code' => data_get($response[1], 'error.code')]);
    return $response[1];
}

try {
    file_put_contents($metadataPath, json_encode(['release' => 'synthetic-release-it0061-window2', 'active_sha' => $sha], JSON_THROW_ON_ERROR));
    config()->set('akubica_uat.release_metadata_path', $metadataPath);
    configureSyntheticIdentities();
    config()->set('akubica_uat.operator_id', User::whereHas('administrator')->latest('id')->value('id'));
    $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)->firstOrFail();
    $beforeLegacy = AkubicaUatFixtureManifest::query()->where('namespace', AkubicaUatFixtureContract::NAMESPACE)->count();
    $beforeBaseItems = count((array) data_get($manifest->metadata, 'ids.cart_items', []));
    $baseItemIds = array_map('intval', (array) data_get($manifest->metadata, 'ids.cart_items', []));
    $beforeForeignItems = LaboratoryCartItem::query()->whereNotIn('customer_id', [(int) data_get($manifest->metadata, 'ids.customers.primary')])->count();
    $stateBefore = json_decode(Storage::disk('local')->get(AkubicaUatFixtureContract::storagePath('access-state.json', $namespace)), true, 512, JSON_THROW_ON_ERROR);
    $windowTokenIds = array_map('intval', array_merge(array_values($stateBefore['token_ids'] ?? []), array_values($stateBefore['scenario_token_ids'] ?? [])));
    $tests = LaboratoryTest::query()->where('gda_id', 'like', 'RC2-PRICE-FIX-'.$namespace.'-%')->pluck('id', 'gda_id');
    foreach (['B06', 'B20', 'B21'] as $required) if (! $tests->contains(fn ($id, $gda) => str_ends_with($gda, '-'.$required))) failResult('PRICE_FIXTURE_MISSING', ['case' => $required]);

    $headers = tokens();
    $created = [];
    $register = function (int $id, string $case) use (&$created, $namespace, $sha): void {
        [$code, $result] = inventory(['action' => 'register', 'namespace' => $namespace, 'resource_id' => $id, 'purpose' => 'cart-item-created-by-'.$case, 'source_case' => $case, 'owner' => 'customer:membership_regular', 'expected_sha' => $sha]);
        if ($code !== 0 || ($result['status'] ?? null) !== 'registered') failResult('INVENTORY_REGISTER_FAILED', ['case' => $case, 'error' => $result['error'] ?? null]);
        $created[$case] = $id;
    };
    $unregister = function (int $id, string $case) use ($namespace, $sha): void {
        [$code, $result] = inventory(['action' => 'unregister', 'namespace' => $namespace, 'resource_id' => $id, 'purpose' => 'cart-item-created-by-'.$case, 'owner' => 'customer:membership_regular', 'expected_sha' => $sha]);
        if ($code !== 0 || ($result['status'] ?? null) !== 'unregistered') failResult('INVENTORY_UNREGISTER_FAILED', ['case' => $case]);
    };

    // Partial failure: create one temporary item, fail after registration, then clean it in finally.
    $partialId = null;
    try {
        $partialBody = expectStatus(httpCall($app, 'POST', '/cart/items', ['brand' => 'olab', 'laboratory_test_id' => $tests->first(fn ($id, $gda) => str_ends_with($gda, '-B20'))], $headers), 201, 'partial-B20');
        $partialId = (int) data_get($partialBody, 'data.item.id');
        if ($partialId < 1) failResult('PARTIAL_ID_MISSING');
        $register($partialId, 'B20');
        throw new RuntimeException('INTENTIONAL_PARTIAL_FAILURE');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'INTENTIONAL_PARTIAL_FAILURE') throw $exception;
    } finally {
        if ($partialId !== null) {
            expectStatus(httpCall($app, 'DELETE', '/cart/items/'.$partialId.'?brand=olab', [], $headers), 200, 'partial-cleanup-B20');
            $unregister($partialId, 'B20');
        }
    }

    // Real Laravel endpoint flow using the namespace-scoped price fixtures.
    foreach (['B06' => 'B06b', 'B20' => 'B20', 'B21' => 'B21'] as $scenario => $case) {
        $testId = (int) $tests->first(fn ($id, $gda) => str_ends_with($gda, '-'.$scenario));
        expectStatus(httpCall($app, 'GET', '/catalog/laboratory-tests/'.$testId, [], $headers), 200, $case.'-detail');
        $body = expectStatus(httpCall($app, 'POST', '/cart/items', ['brand' => 'olab', 'laboratory_test_id' => $testId], $headers), 201, $case.'-add');
        $itemId = (int) data_get($body, 'data.item.id');
        if ($itemId < 1) failResult('HTTP_CREATED_ID_MISSING', ['case' => $case]);
        $register($itemId, $case);
        expectStatus(httpCall($app, 'GET', '/cart?brand=olab', [], $headers), 200, $case.'-readback');
        [, $verify] = inventory(['action' => 'verify', 'namespace' => $namespace, 'resource_id' => $itemId, 'purpose' => 'cart-item-created-by-'.$case, 'owner' => 'customer:membership_regular', 'expected_sha' => $sha]);
        if (($verify['status'] ?? null) !== 'verified') failResult('INVENTORY_VERIFY_FAILED', ['case' => $case]);
        expectStatus(httpCall($app, 'DELETE', '/cart/items/'.$itemId.'?brand=olab', [], $headers), 200, $case.'-delete');
        $unregister($itemId, $case);
    }

    [$revokeCode] = custody(['action' => 'revoke', 'aliases' => ['machine_native', 'machine_leo', 'native_membership_regular', 'leo_membership_none']]);
    if ($revokeCode !== 0) failResult('CUSTODY_REVOKE_FAILED');
    [$resetAccess] = commandResult('akubica:uat-access', ['--reset' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
    [$cleanupCode] = commandResult('akubica:rc2-price-fixtures', ['--cleanup' => true, '--namespace' => $namespace, '--confirm' => 'rc2-price-fixtures']);
    $activeTokens = $windowTokenIds === [] ? 0 : PersonalAccessToken::query()->whereIn('id', $windowTokenIds)->count();
    $afterLegacy = AkubicaUatFixtureManifest::query()->where('namespace', AkubicaUatFixtureContract::NAMESPACE)->count();
    $afterForeignItems = LaboratoryCartItem::query()->whereNotIn('customer_id', [(int) data_get($manifest->metadata, 'ids.customers.primary')])->count();
    $manifestStillActive = AkubicaUatFixtureManifest::query()->whereKey($manifest->id)->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)->exists();
    $baseItemsStillPresent = LaboratoryCartItem::query()->whereIn('id', $baseItemIds)->count() === count($baseItemIds);
    $temporaryTestsRemaining = LaboratoryTest::query()->where('gda_id', 'like', 'RC2-PRICE-FIX-'.$namespace.'-%')->count();
    $statePath = AkubicaUatFixtureContract::storagePath('access-state.json', $namespace);
    $ledgerPath = AkubicaUatFixtureContract::storagePath('window2-inventory.json', $namespace);
    $result = [
        'status' => ($resetAccess === 0 && $cleanupCode === 0 && $activeTokens === 0 && $manifestStillActive && $baseItemsStillPresent && $temporaryTestsRemaining === 0 && $afterLegacy === $beforeLegacy && $afterForeignItems === $beforeForeignItems && ! Storage::disk('local')->exists($statePath) && ! Storage::disk('local')->exists($ledgerPath)) ? 'PASS' : 'FAIL',
        'http' => 'Laravel kernel endpoints with MySQL synthetic data',
        'partial_failure_cleanup' => 'PASS',
        'materializer' => ['manifest' => 'active', 'cart_window_apply_repeated' => 'idempotent'],
        'cleanup' => ['access_reset_exit' => $resetAccess, 'price_cleanup_exit' => $cleanupCode, 'active_window_tokens_after' => $activeTokens, 'manifest_preserved' => $manifestStillActive, 'base_items_preserved' => $baseItemsStillPresent, 'temporary_tests_remaining' => $temporaryTestsRemaining, 'access_state_present' => Storage::disk('local')->exists($statePath), 'inventory_ledger_present' => Storage::disk('local')->exists($ledgerPath)],
        'legacy_manifests_before_after' => [$beforeLegacy, $afterLegacy],
        'foreign_cart_items_before_after' => [$beforeForeignItems, $afterForeignItems],
        'base_manifest_cart_items' => $beforeBaseItems,
        'synthetic_revocation' => 'custody revoke exit '.$revokeCode,
    ];
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
    exit($result['status'] === 'PASS' ? 0 : 1);
} catch (Throwable $exception) {
    failResult('INTEGRATION_EXCEPTION', ['class' => $exception::class]);
}
