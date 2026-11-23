<?php

declare(strict_types=1);

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Administrator;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Permission;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\User;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$namespace = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
$aliases = ['machine_native', 'machine_leo', 'native_membership_regular', 'leo_membership_none'];
$metadataPath = base_path('storage/framework/fw2-synthetic-release.json');

function harnessIdentities(): void
{
    foreach (['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $index => $role) {
        config()->set("akubica_uat.identities.{$role}", [
            'email' => "fw2-{$role}@synthetic.example.invalid",
            'phone' => (string) (8110000001 + $index),
            'country' => 'MX',
        ]);
    }
}

function runCustodyProcess(array $payload): array
{
    $env = is_array(getenv()) ? getenv() : [];
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

    return [$process->getExitCode(), $process->getOutput()];
}

function failHarness(string $code): never
{
    fwrite(STDOUT, json_encode(['status' => 'BLOCKED', 'code' => $code], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}

$action = $argv[1] ?? null;

try {
    config()->set('app.key', getenv('APP_KEY') ?: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    harnessIdentities();

    if ($action === 'seed') {
        @mkdir(dirname($metadataPath), 0777, true);
        file_put_contents($metadataPath, json_encode([
            'release' => 'synthetic-release-it0061-window2',
            'active_sha' => 'f0be2da9d052da424cd5cf6cfb073ecd7e73409b',
        ], JSON_THROW_ON_ERROR));

        $hasManifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->exists();
        $hasState = Storage::disk('local')->exists(AkubicaUatFixtureContract::storagePath('access-state.json', $namespace));
        if ($hasManifest || $hasState) {
            $resetAccess = $hasState ? Artisan::call('akubica:uat-access', ['--reset' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]) : 0;
            file_put_contents(base_path('storage/logs/fw2-seed-access-reset.out'), Artisan::output());
            $resetFixtures = $hasManifest ? Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]) : 0;
            file_put_contents(base_path('storage/logs/fw2-seed-fixture-reset.out'), Artisan::output());
            if ($resetAccess !== 0 || $resetFixtures !== 0) failHarness('PREVIOUS_WINDOW_RESET_FAILED');
        }
        Storage::disk('local')->deleteDirectory($namespace);

        $operator = User::factory()->create();
        $administrator = Administrator::create(['user_id' => $operator->id]);
        $permission = Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']);
        $administrator->givePermissionTo($permission);
        config()->set('akubica_uat.operator_id', $operator->id);

        $fixtureExit = Artisan::call('akubica:uat-fixtures', ['--apply' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
        file_put_contents(base_path('storage/logs/fw2-seed-fixtures.out'), Artisan::output());
        if ($fixtureExit !== 0) failHarness('FIXTURE_APPLY_FAILED');
        $accessExit = Artisan::call('akubica:uat-access', ['--apply' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
        file_put_contents(base_path('storage/logs/fw2-seed-access.out'), Artisan::output());
        if ($accessExit !== 0) failHarness('ACCESS_APPLY_FAILED');

        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->firstOrFail();
        $ids = (array) data_get($manifest->metadata, 'ids', []);
        $cartItems = array_values(array_map('intval', (array) ($ids['cart_items'] ?? [])));
        if (count($cartItems) < 2) failHarness('FIXTURE_CART_INVENTORY_INCOMPLETE');
        $state = json_decode(Storage::disk('local')->get(AkubicaUatFixtureContract::storagePath('access-state.json', $namespace)), true, 512, JSON_THROW_ON_ERROR);
        $tokenIds = array_merge(array_values($state['token_ids'] ?? []), array_values($state['scenario_token_ids'] ?? []));

        fwrite(STDOUT, json_encode([
            'status' => 'PASS',
            'namespace' => $namespace,
            'metadata_path' => $metadataPath,
            'cart_items' => [$cartItems[0], $cartItems[1]],
            'customer_primary' => (int) data_get($ids, 'customers.primary'),
            'test_ids' => array_values(array_map('intval', (array) ($ids['tests'] ?? []))),
            'token_count' => count($tokenIds),
        ], JSON_THROW_ON_ERROR).PHP_EOL);
        exit(0);
    }

    if ($action === 'cleanup') {
        [$revokeCode] = runCustodyProcess(['action' => 'revoke', 'aliases' => $aliases]);
        $revokeCode ??= 1;
        $statePath = AkubicaUatFixtureContract::storagePath('access-state.json', $namespace);
        $state = Storage::disk('local')->exists($statePath)
            ? json_decode(Storage::disk('local')->get($statePath), true, 512, JSON_THROW_ON_ERROR)
            : [];
        $tokenIds = array_merge(array_values($state['token_ids'] ?? []), array_values($state['scenario_token_ids'] ?? []));
        $activeTokens = $tokenIds === [] ? 0 : PersonalAccessToken::query()->whereIn('id', array_map('intval', $tokenIds))->count();
        $accessReset = 0;
        if ($activeTokens > 0) {
            $accessReset = Artisan::call('akubica:uat-access', ['--reset' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
        } elseif (Storage::disk('local')->exists($statePath)) {
            Storage::disk('local')->delete($statePath);
        }
        $fixtureReset = Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
        Storage::disk('local')->deleteDirectory($namespace);
        $manifestActive = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', 'active')->exists();
        fwrite(STDOUT, json_encode([
            'status' => ($revokeCode === 0 && $accessReset === 0 && $fixtureReset === 0 && $activeTokens === 0 && ! $manifestActive) ? 'PASS' : 'BLOCKED',
            'revoke_exit' => $revokeCode,
            'access_reset_exit' => $accessReset,
            'fixture_reset_exit' => $fixtureReset,
            'active_window_tokens_after_revoke' => $activeTokens,
            'manifest_active_after_reset' => $manifestActive,
            'access_state_present_after_reset' => Storage::disk('local')->exists($statePath),
        ], JSON_THROW_ON_ERROR).PHP_EOL);
        exit(($revokeCode === 0 && $accessReset === 0 && $fixtureReset === 0 && $activeTokens === 0 && ! $manifestActive) ? 0 : 1);
    }

    failHarness('ACTION_INVALID');
} catch (Throwable) {
    failHarness('HARNESS_FAILED');
}
