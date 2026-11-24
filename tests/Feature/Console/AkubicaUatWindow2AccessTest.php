<?php

use App\Models\Administrator;
use App\Models\Permission;
use App\Models\User;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use App\Services\Api\V1\Uat\AkubicaUatAccessProvisioner;
use App\Models\MedicalAttentionSubscription;
function window2ConfigureIdentities(): void
{
    foreach (['primary', 'foreign', 'disposable', 'membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $index => $role) {
        config()->set("akubica_uat.identities.{$role}", [
            'email' => 'window2-'.$role.'@synthetic.example.invalid',
            'phone' => (string) (8310000001 + $index),
            'country' => 'MX',
        ]);
    }
}

test('window 2 access provisions scenario bearers idempotently and resets all tokens', function () {
    window2ConfigureIdentities();
    Storage::disk('local')->deleteDirectory('akubica-uat-batch2-a4-v1');
    $this->artisan('akubica:uat-fixtures', [
        '--reset' => true,
        '--confirm' => 'akubica-uat-batch2-a4-v1',
        '--namespace' => 'akubica-uat-batch2-a4-v1',
    ])->run();
    Storage::disk('local')->delete(array_map(
        fn (string $path): string => AkubicaUatFixtureContract::storagePath($path, 'akubica-uat-batch2-a4-v1'),
        AkubicaUatFixtureContract::STORAGE_DOCUMENTS,
    ));
    $fixtureCode = Artisan::call('akubica:uat-fixtures', [
        '--apply' => true,
        '--confirm' => 'akubica-uat-batch2-a4-v1',
        '--namespace' => 'akubica-uat-batch2-a4-v1',
    ]);
    expect($fixtureCode)->toBe(0);

    $manifest = AkubicaUatFixtureManifest::query()
        ->where('namespace', 'akubica-uat-batch2-a4-v1')
        ->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)
        ->firstOrFail();
    $membershipNoneCustomerId = (int) data_get($manifest->metadata, 'ids.customers.membership_none');
    expect($membershipNoneCustomerId)->toBeGreaterThan(0)
        ->and(MedicalAttentionSubscription::withTrashed()->where('customer_id', $membershipNoneCustomerId)->count())
        ->toBe(0);

    $operator = User::factory()->create();
    $administrator = Administrator::create(['user_id' => $operator->id]);
    $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);

    $dryRun = app(AkubicaUatAccessProvisioner::class)->dryRun(
        'akubica-uat-batch2-a4-v1',
        $operator,
        'functional-window-2',
    );
    expect(collect($dryRun['scenario_bindings'])->pluck('role')->all())
        ->toContain('membership_none', 'membership_regular')
        ->and(collect($dryRun['scenario_bindings'])->firstWhere('role', 'membership_none')['profile'])
        ->toBe('native');

    $this->artisan('akubica:uat-access', [
        '--dry-run' => true,
        '--namespace' => 'akubica-uat-batch2-a4-v1',
        '--scenario' => 'functional-window-2',
    ])->assertSuccessful();

    $before = PersonalAccessToken::query()->count();
    $apply = $this->artisan('akubica:uat-access', [
        '--apply' => true,
        '--namespace' => 'akubica-uat-batch2-a4-v1',
        '--scenario' => 'functional-window-2',
        '--confirm' => 'akubica-uat-batch2-a4-v1',
    ]);
    $applyCode = $apply->run();
    expect($applyCode)->toBe(0);
    $afterApply = PersonalAccessToken::query()->count();
    expect($afterApply - $before)->toBe(9);

    $this->artisan('akubica:uat-access', [
        '--apply' => true,
        '--namespace' => 'akubica-uat-batch2-a4-v1',
        '--scenario' => 'functional-window-2',
        '--confirm' => 'akubica-uat-batch2-a4-v1',
    ])->assertSuccessful();
    expect(PersonalAccessToken::query()->count())->toBe($afterApply);

    $this->artisan('akubica:uat-access', [
        '--reset' => true,
        '--namespace' => 'akubica-uat-batch2-a4-v1',
        '--scenario' => 'functional-window-2',
        '--confirm' => 'akubica-uat-batch2-a4-v1',
    ])->assertSuccessful();
    expect(PersonalAccessToken::query()->count())->toBe($before)
        ->and(AkubicaUatFixtureManifest::query()->where('namespace', 'akubica-uat-batch2-a4-v1')->where('status', 'active')->exists())->toBeTrue();
});
