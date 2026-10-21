<?php

use App\Models\TaxProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runTaxProfileDefaultOwnerKeyRecoveryBackfill(): void
{
    $migration = require base_path(
        'database/migrations/2026_08_01_220001_repair_tax_profile_default_owner_key.php',
    );

    $migration->backfillDefaults();
}

it('preserves a manually selected default across repeated recovery backfills', function (): void {
    $customer = User::factory()->withRegularCustomer()->create()->customer;
    $older = TaxProfile::factory()->for($customer)->create();
    $newer = TaxProfile::factory()->for($customer)->create();

    $older->forceFill(['is_default' => true])->save();

    runTaxProfileDefaultOwnerKeyRecoveryBackfill();
    runTaxProfileDefaultOwnerKeyRecoveryBackfill();

    expect($older->fresh()->is_default)->toBeTrue()
        ->and($older->fresh()->default_owner_key)->toBe($customer->id)
        ->and($newer->fresh()->is_default)->toBeFalse()
        ->and($newer->fresh()->default_owner_key)->toBeNull();
});

it('chooses the newest active profile only when a customer has no default', function (): void {
    $customer = User::factory()->withRegularCustomer()->create()->customer;
    $older = TaxProfile::factory()->for($customer)->create([
        'created_at' => now()->subDay(),
    ]);
    $newer = TaxProfile::factory()->for($customer)->create([
        'created_at' => now(),
    ]);

    runTaxProfileDefaultOwnerKeyRecoveryBackfill();

    expect($older->fresh()->is_default)->toBeFalse()
        ->and($newer->fresh()->is_default)->toBeTrue()
        ->and($newer->fresh()->default_owner_key)->toBe($customer->id);
});

it('reduces multiple historical defaults to the most recently updated selection', function (): void {
    $customer = User::factory()->withRegularCustomer()->create()->customer;
    $first = TaxProfile::factory()->for($customer)->create();
    $second = TaxProfile::factory()->for($customer)->create();

    DB::table('tax_profiles')
        ->whereIn('id', [$first->id, $second->id])
        ->update(['is_default' => true]);
    DB::table('tax_profiles')
        ->where('id', $second->id)
        ->update(['updated_at' => now()->addMinute()]);

    runTaxProfileDefaultOwnerKeyRecoveryBackfill();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($first->fresh()->default_owner_key)->toBeNull()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->default_owner_key)->toBe($customer->id);
});

it('synchronizes the owner key when a default changes customer', function (): void {
    $source = User::factory()->withRegularCustomer()->create()->customer;
    $target = User::factory()->withRegularCustomer()->create()->customer;
    $profile = TaxProfile::factory()->for($source)->create();
    $targetDefault = TaxProfile::factory()->for($target)->create();

    $profile->forceFill(['is_default' => true])->save();
    $profile->forceFill([
        'customer_id' => $target->id,
        'is_default' => true,
    ])->save();

    expect($profile->fresh()->default_owner_key)->toBe($target->id)
        ->and($targetDefault->fresh()->is_default)->toBeFalse()
        ->and($targetDefault->fresh()->default_owner_key)->toBeNull()
        ->and($source->taxProfiles()->where('is_default', true)->count())->toBe(0);
});

it('synchronizes soft delete and restore without creating a second default', function (): void {
    $customer = User::factory()->withRegularCustomer()->create()->customer;
    $profile = TaxProfile::factory()->for($customer)->create();

    $profile->forceFill(['is_default' => true])->save();
    $profile->delete();

    expect($profile->fresh()->default_owner_key)->toBeNull();

    $profile->restore();

    expect($profile->fresh()->is_default)->toBeTrue()
        ->and($profile->fresh()->default_owner_key)->toBe($customer->id);

    $other = TaxProfile::factory()->for($customer)->create();
    $other->forceFill(['is_default' => true])->save();
    $profile->delete();
    $profile->restore();

    expect($other->fresh()->is_default)->toBeTrue()
        ->and($other->fresh()->default_owner_key)->toBe($customer->id)
        ->and($profile->fresh()->is_default)->toBeFalse()
        ->and($profile->fresh()->default_owner_key)->toBeNull();
});

it('keeps the unique index and one active default invariant', function (): void {
    $index = collect(Schema::getIndexes('tax_profiles'))
        ->firstWhere('name', 'tax_profiles_default_owner_key_unique');

    expect($index['unique'] ?? false)->toBeTrue()
        ->and($index['columns'] ?? [])->toBe(['default_owner_key']);

    $customer = User::factory()->withRegularCustomer()->create()->customer;
    $first = TaxProfile::factory()->for($customer)->create();
    $second = TaxProfile::factory()->for($customer)->create();

    $first->forceFill(['is_default' => true])->save();
    $second->forceFill(['is_default' => true])->save();

    expect($customer->taxProfiles()->where('is_default', true)->count())->toBe(1)
        ->and($second->fresh()->default_owner_key)->toBe($customer->id)
        ->and($first->fresh()->default_owner_key)->toBeNull();
});

it('keeps the ordinary owner key nullable and hidden from serialized output', function (): void {
    $column = collect(Schema::getColumns('tax_profiles'))
        ->firstWhere('name', 'default_owner_key');

    expect($column['nullable'] ?? false)->toBeTrue()
        ->and($column['generation'] ?? null)->toBeNull()
        ->and((new TaxProfile)->getHidden())->toContain('default_owner_key');
});
