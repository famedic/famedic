<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Administrator;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Permission;
use App\Models\User;
use App\Services\Api\V1\Uat\AkubicaUatAccessProvisioner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

test('recovers reset from durable sanitized inventory after state loss', function () {
    $namespace = AkubicaUatFixtureContract::DEDICATED_NAMESPACE;
    foreach (['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $index => $role) {
        config()->set("akubica_uat.identities.{$role}", [
            'email' => 'recovery-'.$role.'@synthetic.example.invalid',
            'phone' => (string) (8110000011 + $index),
            'country' => 'MX',
        ]);
    }
    Storage::disk('local')->deleteDirectory($namespace);
    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
    $fixtureExit = Artisan::call('akubica:uat-fixtures', ['--apply' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
    expect($fixtureExit)->toBe(0, Artisan::output());

    $operator = User::factory()->create();
    $administrator = Administrator::create(['user_id' => $operator->id]);
    $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);
    expect(Artisan::call('akubica:uat-access', ['--apply' => true, '--namespace' => $namespace, '--scenario' => 'functional-window-2', '--confirm' => $namespace]))->toBe(0);

    $statePath = AkubicaUatFixtureContract::storagePath('access-state.json', $namespace);
    $inventoryPath = AkubicaUatFixtureContract::storagePath('access-inventory.json', $namespace);
    $state = Storage::disk('local')->get($statePath);
    $decoded = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
    $tokenIds = array_map('intval', array_merge(array_values($decoded['token_ids']), array_values($decoded['scenario_token_ids'])));
    expect($tokenIds)->toHaveCount(9)
        ->and(Storage::disk('local')->exists($inventoryPath))->toBeTrue();

    Storage::disk('local')->delete($statePath);
    $reset = app(AkubicaUatAccessProvisioner::class)->reset($namespace, $operator, 'functional-window-2');
    expect($reset['status'])->toBe('ok')
        ->and(PersonalAccessToken::query()->whereIn('id', $tokenIds)->count())->toBe(0)
        ->and(Storage::disk('local')->exists($statePath))->toBeFalse();

    $inventory = json_decode(Storage::disk('local')->get($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    expect($inventory['status'])->toBe('completed')
        ->and($inventory)->not->toHaveKey('encrypted_secrets')
        ->and(AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', 'active')->exists())->toBeTrue();

    Artisan::call('akubica:uat-fixtures', ['--reset' => true, '--confirm' => $namespace, '--namespace' => $namespace]);
});
