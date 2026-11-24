<?php

use App\Models\Administrator;
use App\Models\Permission;
use App\Models\User;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use App\Services\Api\V1\Uat\AkubicaUatAccessProvisioner;
use App\Models\MedicalAttentionSubscription;
use App\Models\ApiConsumerDelegation;
use App\Models\ApiConsumerTokenBinding;

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
        ->toBe('native')
        ->and(collect($dryRun['scenario_bindings'])->firstWhere('alias', 'akubica-uat-batch2-a4-v1-native-membership-none')['capabilities'])
        ->toContain('checkout:prepare');

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

    $state = json_decode(Storage::disk('local')->get(
        AkubicaUatFixtureContract::storagePath('access-state.json', 'akubica-uat-batch2-a4-v1')
    ), true, 512, JSON_THROW_ON_ERROR);
    $scenarioDelegation = ApiConsumerDelegation::query()->findOrFail($state['scenario_delegation_ids']['native_membership_none']);
    $scenarioBinding = ApiConsumerTokenBinding::query()->findOrFail($state['scenario_binding_ids']['native_membership_none']);
    expect($scenarioDelegation->capabilities)->toContain('checkout:prepare')
        ->and($scenarioBinding->capabilities)->toContain('checkout:prepare');

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

test('native membership none reaches checkout prepare only with the delegated capability', function () {
    window2ConfigureIdentities();
    $this->artisan('akubica:uat-fixtures', [
        '--apply' => true,
        '--confirm' => 'akubica-uat-batch2-a4-v1',
        '--namespace' => 'akubica-uat-batch2-a4-v1',
    ])->assertSuccessful();

    $operator = User::factory()->create();
    $administrator = Administrator::create(['user_id' => $operator->id]);
    $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);

    try {
        $this->artisan('akubica:uat-access', [
            '--apply' => true,
            '--namespace' => 'akubica-uat-batch2-a4-v1',
            '--scenario' => 'functional-window-2',
            '--confirm' => 'akubica-uat-batch2-a4-v1',
        ])->assertSuccessful();

        $state = json_decode(Storage::disk('local')->get(
            AkubicaUatFixtureContract::storagePath('access-state.json', 'akubica-uat-batch2-a4-v1')
        ), true, 512, JSON_THROW_ON_ERROR);
        $machine = Crypt::decryptString($state['encrypted_secrets']['machine_native']);
        $bearer = Crypt::decryptString($state['scenario_encrypted_secrets']['native_membership_none']);
        $scenarioDelegation = ApiConsumerDelegation::query()->findOrFail($state['scenario_delegation_ids']['native_membership_none']);
        $scenarioBinding = ApiConsumerTokenBinding::query()->findOrFail($state['scenario_binding_ids']['native_membership_none']);
        $positive = $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])->withHeaders([
            'Authorization' => 'Bearer '.$bearer,
            'X-Synthetic-Machine-Credential' => $machine,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/checkout/prepare?service_line=medical_services');
        $positive->assertOk()
            ->assertJsonPath('data.assistant_can_pay', false)
            ->assertJsonPath('data.can_continue_on_platform', true)
            ->assertJsonPath('data.can_continue_to_payment_platform', false);

        $membershipHeaders = [
            'Authorization' => 'Bearer '.$bearer,
            'X-Synthetic-Machine-Credential' => $machine,
            'Accept' => 'application/json',
        ];
        $added = $this->withHeaders($membershipHeaders)->postJson('/api/v1/cart/items', [
            'service_line' => 'medical_services',
            'item_type' => 'membership',
            'membership_plan_code' => 'basic_annual',
            'quantity' => 1,
        ]);
        $added->assertCreated()->assertJsonPath('data.items.0.membership_plan_code', 'basic_annual');
        $cartItemId = (int) $added->json('data.items.0.id');
        expect($cartItemId)->toBeGreaterThan(0);

        $duplicate = $this->withHeaders($membershipHeaders)->postJson('/api/v1/cart/items', [
            'service_line' => 'medical_services',
            'item_type' => 'membership',
            'membership_plan_code' => 'basic_annual',
            'quantity' => 1,
        ]);
        $duplicate->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_ALREADY_IN_CART');

        $cart = $this->withHeaders($membershipHeaders)->getJson('/api/v1/cart?service_line=medical_services');
        $cart->assertOk()->assertJsonPath('data.items.0.id', $cartItemId);

        $removed = $this->withHeaders($membershipHeaders)->deleteJson('/api/v1/cart/items/'.$cartItemId.'?service_line=medical_services');
        $removed->assertOk();

        $withoutCheckout = array_values(array_diff($scenarioDelegation->capabilities, ['checkout:prepare']));
        $scenarioDelegation->forceFill(['capabilities' => $withoutCheckout])->save();
        $scenarioBinding->forceFill(['capabilities' => $withoutCheckout])->save();

        $negative = $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])->withHeaders([
            'Authorization' => 'Bearer '.$bearer,
            'X-Synthetic-Machine-Credential' => $machine,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/checkout/prepare?service_line=medical_services');
        $negative->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
    } finally {
        $this->artisan('akubica:uat-access', [
            '--reset' => true,
            '--namespace' => 'akubica-uat-batch2-a4-v1',
            '--scenario' => 'functional-window-2',
            '--confirm' => 'akubica-uat-batch2-a4-v1',
        ])->assertSuccessful();
    }
});

test('membership variants are namespace-scoped and idempotent without OTP', function () {
    window2ConfigureIdentities();
    $namespace = 'akubica-uat-batch2-a4-v1';
    $scenario = 'functional-window-2-memberships';
    $this->artisan('akubica:uat-access', [
        '--reset' => true,
        '--namespace' => $namespace,
        '--scenario' => $scenario,
        '--confirm' => $namespace,
    ])->run();
    $this->artisan('akubica:uat-fixtures', [
        '--apply' => true,
        '--confirm' => $namespace,
        '--namespace' => $namespace,
    ])->assertSuccessful();

    $operator = User::factory()->create();
    $administrator = Administrator::create(['user_id' => $operator->id]);
    $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));
    config()->set('akubica_uat.operator_id', $operator->id);

    try {
        $dryRun = app(AkubicaUatAccessProvisioner::class)->dryRun($namespace, $operator, $scenario);
        expect(collect($dryRun['scenario_bindings'])->pluck('role')->all())
            ->toBe(['membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'])
            ->and(collect($dryRun['scenario_bindings'])->pluck('profile')->unique()->values()->all())
            ->toBe(['native']);

        $before = PersonalAccessToken::query()->count();
        $this->artisan('akubica:uat-access', [
            '--apply' => true,
            '--namespace' => $namespace,
            '--scenario' => $scenario,
            '--confirm' => $namespace,
        ])->assertSuccessful();
        $afterApply = PersonalAccessToken::query()->count();
        expect($afterApply - $before)->toBe(10);

        $state = json_decode(Storage::disk('local')->get(
            AkubicaUatFixtureContract::storagePath('access-state.json', $namespace)
        ), true, 512, JSON_THROW_ON_ERROR);
        $machine = Crypt::decryptString($state['encrypted_secrets']['machine_native']);
        foreach (['membership_none', 'membership_regular', 'membership_institutional', 'membership_expired'] as $role) {
            $bearer = Crypt::decryptString($state['scenario_encrypted_secrets']['native_'.$role]);
            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$bearer,
                'X-Synthetic-Machine-Credential' => $machine,
                'Accept' => 'application/json',
            ])->getJson('/api/v1/memberships/status');
            $response->assertOk();
        }

        $this->artisan('akubica:uat-access', [
            '--apply' => true,
            '--namespace' => $namespace,
            '--scenario' => $scenario,
            '--confirm' => $namespace,
        ])->assertSuccessful();
        expect(PersonalAccessToken::query()->count())->toBe($afterApply);
    } finally {
        $this->artisan('akubica:uat-access', [
            '--reset' => true,
            '--namespace' => $namespace,
            '--scenario' => $scenario,
            '--confirm' => $namespace,
        ])->assertSuccessful();
        $this->artisan('akubica:uat-fixtures', [
            '--reset' => true,
            '--confirm' => $namespace,
            '--namespace' => $namespace,
        ])->assertSuccessful();
    }
});
