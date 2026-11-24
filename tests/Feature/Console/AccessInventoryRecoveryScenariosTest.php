<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Administrator;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Permission;
use App\Models\User;
use App\Services\Api\V1\Uat\AkubicaUatAccessProvisioner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

function recoveryScenarioSetup(): array
{
    $namespace = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
    foreach (['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $index => $role) {
        config()->set("akubica_uat.identities.{$role}", [
            'email' => 'inventory-'.$role.'@synthetic.example.invalid',
            'phone' => (string) (8110000031 + $index),
            'country' => 'MX',
        ]);
    }
    Storage::disk('local')->deleteDirectory($namespace);
    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
    expect(Artisan::call('akubica:uat-fixtures', ['--apply' => true, '--confirm' => $namespace, '--namespace' => $namespace]))->toBe(0);

    $operator = User::factory()->create();
    $administrator = Administrator::create(['user_id' => $operator->id]);
    $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);

    return [$namespace, $operator];
}

test('records partial apply before the first failure and never records secrets', function () {
    [$namespace, $operator] = recoveryScenarioSetup();
    $inventoryPath = AkubicaUatFixtureContract::storagePath('access-inventory.json', $namespace);
    $consumerInserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$consumerInserts): void {
        if (str_contains(strtolower($query->sql), 'insert into `api_consumers`') && ++$consumerInserts === 2) {
            throw new RuntimeException('synthetic partial-apply failure');
        }
    });

    $applyCode = Artisan::call('akubica:uat-access', ['--apply' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
    expect($applyCode)->not->toBe(0, Artisan::output());
    expect(Storage::disk('local')->exists($inventoryPath))->toBeTrue();
    $inventory = json_decode(Storage::disk('local')->get($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    expect($inventory['status'])->toBe('completed')
        ->and($inventory['state']['consumer_ids'])->not->toBeEmpty()
        ->and($inventory)->not->toHaveKey('encrypted_secrets')
        ->and(json_encode($inventory))->not->toContain('plainTextToken')
        ->and(glob(Storage::disk('local')->path($inventoryPath).'.tmp.*'))->toBe([]);

    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
});

test('rejects foreign namespace and malformed inventory references before reset', function () {
    [$namespace, $operator] = recoveryScenarioSetup();
    expect(Artisan::call('akubica:uat-access', ['--apply' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]))->toBe(0);
    $inventoryPath = AkubicaUatFixtureContract::storagePath('access-inventory.json', $namespace);
    $statePath = AkubicaUatFixtureContract::storagePath('access-state.json', $namespace);
    $inventory = json_decode(Storage::disk('local')->get($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    $validInventory = $inventory;
    Storage::disk('local')->delete($statePath);

    $inventory['namespace'] = 'akubica-uat-v1';
    Storage::disk('local')->put($inventoryPath, json_encode($inventory, JSON_THROW_ON_ERROR));
    expect(fn () => app(AkubicaUatAccessProvisioner::class)->reset($namespace, $operator, 'functional-window-2'))
        ->toThrow(DomainException::class);

    $inventory['namespace'] = $namespace;
    $inventory['state']['consumer_ids']['native'] = 'foreign-id';
    Storage::disk('local')->put($inventoryPath, json_encode($inventory, JSON_THROW_ON_ERROR));
    expect(fn () => app(AkubicaUatAccessProvisioner::class)->reset($namespace, $operator, 'functional-window-2'))
        ->toThrow(DomainException::class);

    Storage::disk('local')->put($inventoryPath, json_encode($validInventory, JSON_THROW_ON_ERROR));
    Artisan::call('akubica:uat-access', ['--reset' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
});

test('keeps active inventory after interrupted deletion and completes on retry', function () {
    [$namespace, $operator] = recoveryScenarioSetup();
    $applyCode = Artisan::call('akubica:uat-access', ['--apply' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
    expect($applyCode)->toBe(0, Artisan::output());
    $statePath = AkubicaUatFixtureContract::storagePath('access-state.json', $namespace);
    $inventoryPath = AkubicaUatFixtureContract::storagePath('access-inventory.json', $namespace);
    Storage::disk('local')->delete($statePath);

    $failDelete = true;
    DB::listen(function (QueryExecuted $query) use (&$failDelete): void {
        if ($failDelete && str_contains(strtolower($query->sql), 'delete from') && str_contains(strtolower($query->sql), 'personal_access_tokens')) {
            $failDelete = false;
            throw new RuntimeException('synthetic interrupted deletion');
        }
    });

    expect(fn () => app(AkubicaUatAccessProvisioner::class)->reset($namespace, $operator, 'functional-window-2'))
        ->toThrow(RuntimeException::class);
    $afterFailure = json_decode(Storage::disk('local')->get($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    expect($afterFailure['status'])->toBe('active');

    $retry = app(AkubicaUatAccessProvisioner::class)->reset($namespace, $operator, 'functional-window-2');
    $afterRetry = json_decode(Storage::disk('local')->get($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    expect($retry['status'])->toBe('ok')
        ->and($afterRetry['status'])->toBe('completed')
        ->and(Storage::disk('local')->exists($statePath))->toBeFalse();

    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
});

test('supports reset, repeated reset, new apply and reset again', function () {
    [$namespace, $operator] = recoveryScenarioSetup();
    $provisioner = app(AkubicaUatAccessProvisioner::class);

    expect($provisioner->apply($namespace, $operator, 'functional-window-2')['status'])->toBe('ok');
    Storage::disk('local')->delete(AkubicaUatFixtureContract::storagePath('access-state.json', $namespace));
    expect($provisioner->reset($namespace, $operator, 'functional-window-2')['status'])->toBe('ok')
        ->and($provisioner->reset($namespace, $operator, 'functional-window-2')['status'])->toBe('empty');

    expect($provisioner->apply($namespace, $operator, 'functional-window-2')['status'])->toBe('ok');
    expect($provisioner->reset($namespace, $operator, 'functional-window-2')['status'])->toBe('ok');
    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', 'active')->exists())->toBeTrue();

    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
});

test('keeps a scenario PAT in the ledger when binding preparation fails', function () {
    [$namespace, $operator] = recoveryScenarioSetup();
    $inventoryPath = AkubicaUatFixtureContract::storagePath('access-inventory.json', $namespace);
    $captured = null;
    $patLocks = 0;
    DB::listen(function (QueryExecuted $query) use (&$captured, &$patLocks, $inventoryPath): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'personal_access_tokens') && str_contains($sql, 'for update') && ++$patLocks === 5) {
            $captured = json_decode(Storage::disk('local')->get($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
            throw new RuntimeException('synthetic scenario binding preparation failure');
        }
    });

    $code = Artisan::call('akubica:uat-access', ['--apply' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]);
    expect($code)->not->toBe(0, Artisan::output())
        ->and($captured['status'] ?? null)->toBe('active')
        ->and($captured['state']['scenario_token_ids'] ?? [])->not->toBeEmpty()
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    $inventory = json_decode(Storage::disk('local')->get($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    expect($inventory['status'])->toBe('completed')
        ->and(Storage::disk('local')->exists(AkubicaUatFixtureContract::storagePath('access-state.json', $namespace)))->toBeFalse();
    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
});
