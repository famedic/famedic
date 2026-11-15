<?php

use App\Console\Commands\MaterializeAkubicaUatR04R05FixturesCommand as Cmd;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\LaboratoryAppointment;

function r04r05Manifest(): array
{
    $primary = Customer::factory()->withRegularAccount()->create(['user_id' => \App\Models\User::factory()->create()->id]);
    $foreign = Customer::factory()->withRegularAccount()->create(['user_id' => \App\Models\User::factory()->create()->id]);
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => 'akubica-uat-v1',
        'fixture_version' => 1,
        'status' => 'active',
        'expires_at' => now()->addDays(7),
        'metadata' => ['ids' => ['customers' => ['primary' => $primary->id, 'foreign' => $foreign->id], 'store' => []]],
    ]);

    return [$primary, $foreign];
}

test('dry-run is the default and writes nothing', function () {
    r04r05Manifest();
    $this->artisan('akubica:uat-fixtures-r04r05')->assertSuccessful();
    expect(FamilyAccount::query()->count())->toBe(0)
        ->and(LaboratoryAppointment::query()->count())->toBe(0);
});

test('apply requires the exact namespace confirmation', function () {
    r04r05Manifest();
    $this->artisan('akubica:uat-fixtures-r04r05 --apply --confirm=otro')->assertFailed();
    expect(FamilyAccount::query()->count())->toBe(0);
});

test('fails closed without an active manifest', function () {
    $this->artisan('akubica:uat-fixtures-r04r05 --apply --confirm=akubica-uat-v1')->assertFailed();
});

test('apply is idempotent and reset removes only marked records', function () {
    [$primary, $foreign] = r04r05Manifest();
    $unrelated = FamilyAccount::factory()->create(['customer_id' => $primary->id]);

    $this->artisan('akubica:uat-fixtures-r04r05 --apply --confirm=akubica-uat-v1')->assertSuccessful();
    $this->artisan('akubica:uat-fixtures-r04r05 --apply --confirm=akubica-uat-v1')->assertSuccessful();

    expect(FamilyAccount::query()->where('name', Cmd::FAMILY_NAME)->count())->toBe(2)
        ->and(LaboratoryAppointment::query()->where('notes', Cmd::MARKER)->count())->toBe(2)
        ->and(LaboratoryAppointment::query()->where('notes', Cmd::MARKER)->whereNotNull('patient_phone')->count())->toBe(0);

    $this->artisan('akubica:uat-fixtures-r04r05 --reset --confirm=akubica-uat-v1')->assertSuccessful();

    expect(FamilyAccount::query()->withTrashed()->where('name', Cmd::FAMILY_NAME)->count())->toBe(0)
        ->and(LaboratoryAppointment::query()->withTrashed()->where('notes', Cmd::MARKER)->count())->toBe(0)
        ->and(FamilyAccount::query()->whereKey($unrelated->id)->exists())->toBeTrue();
});

test('R04 family endpoint data is isolated per customer after apply', function () {
    [$primary, $foreign] = r04r05Manifest();
    $this->artisan('akubica:uat-fixtures-r04r05 --apply --confirm=akubica-uat-v1')->assertSuccessful();

    expect($primary->familyAccounts()->count())->toBe(1)
        ->and($foreign->familyAccounts()->count())->toBe(1)
        ->and($primary->familyAccounts()->first()->id)->not->toBe($foreign->familyAccounts()->first()->id);
});
