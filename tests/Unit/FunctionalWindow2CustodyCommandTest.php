<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Administrator;
use App\Models\Permission;
use App\Models\User;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Process\Process;

// This suite exercises Artisan, config, Storage and MySQL; it is a Laravel
// integration suite despite its historical Unit directory location.
uses(Tests\TestCase::class);

function custodyWindow2ConfigureIdentities(): void
{
    foreach (['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $index => $role) {
        config()->set("akubica_uat.identities.{$role}", [
            'email' => 'custody-window2-'.$role.'@synthetic.example.invalid',
            'phone' => (string) (8410000001 + $index),
            'country' => 'MX',
        ]);
    }
}

test('real custody helper reads and revokes all window aliases in isolated mysql', function () {
    custodyWindow2ConfigureIdentities();
    $namespace = 'akubica-uat-batch2-a4-v1';
    $operator = User::factory()->create();
    $administrator = Administrator::create(['user_id' => $operator->id]);
    $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);
    Artisan::call('akubica:uat-access', ['--reset' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
    Storage::disk('local')->deleteDirectory($namespace);
    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
    Storage::disk('local')->delete(array_map(
        fn (string $path): string => AkubicaUatFixtureContract::storagePath($path, $namespace),
        AkubicaUatFixtureContract::STORAGE_DOCUMENTS,
    ));
    Artisan::call('akubica:uat-fixtures', ['--apply' => true, '--confirm' => $namespace, '--namespace' => $namespace]);

    Artisan::call('akubica:uat-access', ['--apply' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
    expect(Artisan::output())->toContain('"status":"ok"');
    $state = json_decode(Storage::disk('local')->get(AkubicaUatFixtureContract::storagePath('access-state.json', $namespace)), true, 512, JSON_THROW_ON_ERROR);
    $tokenIds = array_merge(array_values($state['token_ids']), array_values($state['scenario_token_ids']));
    expect(count($tokenIds))->toBe(9);

    $aliases = ['machine_native', 'machine_leo', 'native_membership_regular', 'leo_membership_none'];
    $childEnv = array_filter([
        'APP_ENV' => getenv('APP_ENV') ?: 'testing',
        'APP_KEY' => getenv('APP_KEY') ?: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => getenv('DB_CONNECTION') ?: 'mysql',
        'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
        'DB_PORT' => getenv('DB_PORT') ?: '13307',
        'DB_DATABASE' => getenv('DB_DATABASE') ?: 'famedic_rc2_it0061_window2_testing',
        'DB_USERNAME' => getenv('DB_USERNAME') ?: 'famedic_rc2_test',
        'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'famedic_rc2_test_pw',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'APP_CONFIG_CACHE' => base_path('storage/framework/cache/functional-window2-custody-config.php'),
    ], static fn ($value): bool => $value !== false && $value !== null);
    $read = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:custody', '--private', '--no-ansi'], base_path(), $childEnv);
    $read->setInput(json_encode(['action' => 'read', 'aliases' => $aliases], JSON_THROW_ON_ERROR));
    $read->run();
    expect($read->getExitCode(), 'helper stdout/stderr: '.trim($read->getOutput().' '.$read->getErrorOutput()))->toBe(0);
    expect($read->getErrorOutput())->not->toContain('synthetic');
    $secrets = json_decode(trim($read->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($secrets))->toBe($aliases);
    foreach ($secrets as $secret) expect($secret)->toBeString()->not->toBe('');

    $invalid = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:custody', '--no-ansi'], base_path(), $childEnv);
    $invalid->setInput(json_encode(['action' => 'read', 'aliases' => ['legacy_alias']], JSON_THROW_ON_ERROR));
    $invalid->run();
    expect($invalid->getExitCode())->not->toBe(0)->and($invalid->getOutput())->not->toContain($secrets['machine_native']);

    $revoke = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:custody', '--no-ansi'], base_path(), $childEnv);
    $revoke->setInput(json_encode(['action' => 'revoke', 'aliases' => $aliases], JSON_THROW_ON_ERROR));
    $revoke->run();
    expect($revoke->getExitCode())->toBe(0)->and($revoke->getOutput())->toContain('"revoked"');
    expect(PersonalAccessToken::query()->whereIn('id', $tokenIds)->count())->toBe(0);

    Artisan::call('akubica:uat-access', ['--reset' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
    expect(AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', 'active')->exists())->toBeTrue();
    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
});

test('real custody helper supports the dedicated membership scenario and rejects foreign scope', function () {
    custodyWindow2ConfigureIdentities();
    $namespace = 'akubica-uat-batch2-a4-v1';
    $scenario = 'functional-window-2-memberships';
    $this->artisan('akubica:uat-fixtures', [
        '--apply' => true,
        '--confirm' => $namespace,
        '--namespace' => $namespace,
    ])->assertSuccessful();

    $operator = User::factory()->create();
    $administrator = Administrator::create(['user_id' => $operator->id]);
    $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);

    // Recover stale local state from either supported scenario without
    // adopting it: the state itself supplies the scenario selector.
    $statePath = AkubicaUatFixtureContract::storagePath('access-state.json', $namespace);
    if (Storage::disk('local')->exists($statePath)) {
        $existing = json_decode((string) Storage::disk('local')->get($statePath), true, 512, JSON_THROW_ON_ERROR);
        $existingScenario = $existing['scenario'] ?? '';
        if (is_string($existingScenario) && $existingScenario !== '') {
            app(App\Services\Api\V1\Uat\AkubicaUatAccessProvisioner::class)->reset($namespace, $operator, $existingScenario);
        }
    }

    $aliases = [
        'machine_native',
        'native_membership_none',
        'native_membership_regular',
        'native_membership_institutional',
        'native_membership_expired',
    ];
    $childEnv = array_filter([
        'APP_ENV' => getenv('APP_ENV') ?: 'testing',
        'APP_KEY' => getenv('APP_KEY') ?: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => getenv('DB_CONNECTION') ?: 'mysql',
        'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
        'DB_PORT' => getenv('DB_PORT') ?: '13307',
        'DB_DATABASE' => getenv('DB_DATABASE') ?: 'famedic_rc2_it0061_window2_testing',
        'DB_USERNAME' => getenv('DB_USERNAME') ?: 'famedic_rc2_test',
        'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'famedic_rc2_test_pw',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ], static fn ($value): bool => $value !== false && $value !== null);

    try {
        $this->artisan('akubica:uat-access', [
            '--apply' => true,
            '--namespace' => $namespace,
            '--scenario' => $scenario,
            '--confirm' => $namespace,
        ])->assertSuccessful();

        $read = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:custody', '--private', '--no-ansi'], base_path(), $childEnv);
        $read->setInput(json_encode(['action' => 'read', 'scenario' => $scenario, 'aliases' => $aliases], JSON_THROW_ON_ERROR));
        $read->run();
        expect($read->getExitCode())->toBe(0);
        $secrets = json_decode(trim($read->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        expect(array_keys($secrets))->toBe($aliases);

        foreach (['membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $role) {
            $this->withHeaders([
                'Authorization' => 'Bearer '.$secrets['native_'.$role],
                'X-Synthetic-Machine-Credential' => $secrets['machine_native'],
                'Accept' => 'application/json',
            ])->getJson('/api/v1/memberships/status')->assertOk();
        }

        $foreign = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:custody', '--private', '--no-ansi'], base_path(), $childEnv);
        $foreign->setInput(json_encode(['action' => 'read', 'scenario' => 'functional-window-2', 'aliases' => ['native_membership_none']], JSON_THROW_ON_ERROR));
        $foreign->run();
        expect($foreign->getExitCode())->not->toBe(0);

        $foreignAlias = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:custody', '--private', '--no-ansi'], base_path(), $childEnv);
        $foreignAlias->setInput(json_encode(['action' => 'read', 'scenario' => $scenario, 'aliases' => ['leo_membership_none']], JSON_THROW_ON_ERROR));
        $foreignAlias->run();
        expect($foreignAlias->getExitCode())->not->toBe(0);

        $revoke = new Process([PHP_BINARY, base_path('artisan'), 'functional-window2:custody', '--no-ansi'], base_path(), $childEnv);
        $revoke->setInput(json_encode(['action' => 'revoke', 'scenario' => $scenario, 'aliases' => $aliases], JSON_THROW_ON_ERROR));
        $revoke->run();
        expect($revoke->getExitCode())->toBe(0)->and($revoke->getOutput())->toContain('"revoked"');
    } finally {
        $resetResult = app(App\Services\Api\V1\Uat\AkubicaUatAccessProvisioner::class)->reset($namespace, $operator, $scenario);
        expect($resetResult['status'] ?? null)->toBeIn(['ok', 'empty']);
        $this->artisan('akubica:uat-fixtures', [
            '--reset' => true,
            '--confirm' => $namespace,
            '--namespace' => $namespace,
        ])->assertSuccessful();
    }
});
