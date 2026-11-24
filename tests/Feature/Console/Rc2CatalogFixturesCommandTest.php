<?php

use App\Console\Commands\Rc2CatalogFixturesCommand;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestPackage;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    AkubicaUatFixtureManifest::query()->create([
        'namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        'fixture_version' => AkubicaUatFixtureContract::FIXTURE_VERSION,
        'status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
        'metadata' => ['synthetic' => true],
        'expires_at' => now()->addHour(),
    ]);
});

test('catalog preparation is registered and dry-run is non-mutating', function () {
    expect(app('Illuminate\Contracts\Console\Kernel')->all())
        ->toHaveKey('akubica:rc2-catalog-fixtures')
        ->and(app('Illuminate\Contracts\Console\Kernel')->all()['akubica:rc2-catalog-fixtures'])
        ->toBeInstanceOf(Rc2CatalogFixturesCommand::class);

    $this->artisan('akubica:rc2-catalog-fixtures', [
        '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        '--dry-run' => true,
    ])->assertSuccessful()->expectsOutputToContain('B04');

    expect(LaboratoryTest::query()->where('gda_id', 'like', 'RC2-CATALOG-FIX-%')->count())->toBe(0);
});

test('catalog variants apply idempotently and cleanup preserves an unrelated test', function () {
    $unrelated = createOlabTest(['gda_id' => 'UNRELATED-CATALOG-'.bin2hex(random_bytes(3))]);
    $options = [
        '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        '--apply' => true,
        '--confirm' => Rc2CatalogFixturesCommand::CONFIRM,
    ];

    $this->artisan('akubica:rc2-catalog-fixtures', $options)->assertSuccessful();
    $firstIds = LaboratoryTest::query()->where('gda_id', 'like', 'RC2-CATALOG-FIX-%')->pluck('id')->all();
    $this->artisan('akubica:rc2-catalog-fixtures', $options)->assertSuccessful();
    expect(LaboratoryTest::query()->where('gda_id', 'like', 'RC2-CATALOG-FIX-%')->pluck('id')->all())->toBe($firstIds)
        ->and(LaboratoryTestPackage::query()->whereIn('package_test_id', $firstIds)->count())->toBe(1);

    $b01 = LaboratoryTest::query()->where('gda_id', prefixForTest('B01'))->firstOrFail();
    $b02 = LaboratoryTest::query()->where('gda_id', prefixForTest('B02'))->firstOrFail();
    $b04 = LaboratoryTest::query()->where('gda_id', prefixForTest('B04'))->firstOrFail();
    $b05 = LaboratoryTest::query()->where('gda_id', prefixForTest('B05'))->firstOrFail();
    $list = collect($this->getJson('/api/v1/catalog/laboratory-tests?brand=olab&search='.urlencode('RC2-CATALOG-FIX'))->assertOk()->json('data.laboratory_tests'));
    expect($list->pluck('id')->all())->toContain($b01->id, $b02->id);
    $this->getJson('/api/v1/catalog/laboratory-tests/'.$b01->id)->assertOk()->assertJsonPath('data.item_type', 'study');
    $this->getJson('/api/v1/catalog/packages/'.$b02->id)->assertOk()->assertJsonPath('data.item_type', 'package');
    $this->getJson('/api/v1/catalog/laboratory-tests/'.$b04->id)->assertOk()->assertJsonPath('data.pricing.comparison_status', 'no_savings');
    $this->getJson('/api/v1/catalog/laboratory-tests/'.$b05->id)->assertOk()->assertJsonPath('data.pricing.comparison_status', 'data_inconsistent');
    $packageTest = LaboratoryTest::query()->where('gda_id', 'like', 'RC2-CATALOG-FIX-%-B02')->firstOrFail();
    $this->getJson('/api/v1/catalog/packages/'.$packageTest->id)->assertOk()->assertJsonPath('data.item_type', 'package');

    $this->artisan('akubica:rc2-catalog-fixtures', [
        '--namespace' => AkubicaUatFixtureContract::DEDICATED_NAMESPACE,
        '--cleanup' => true,
        '--confirm' => Rc2CatalogFixturesCommand::CONFIRM,
    ])->assertSuccessful();

    expect(LaboratoryTest::query()->where('gda_id', 'like', 'RC2-CATALOG-FIX-%')->count())->toBe(0)
        ->and(LaboratoryTest::query()->whereKey($unrelated->id)->exists())->toBeTrue()
        ->and(DB::table('laboratory_test_package_components')->where('package_id', '>', 0)->count())->toBeGreaterThanOrEqual(0);
});

function prefixForTest(string $scenario): string
{
    return 'RC2-CATALOG-FIX-'.AkubicaUatFixtureContract::DEDICATED_NAMESPACE.'-'.$scenario;
}

test('catalog preparation fails closed for a non-dedicated namespace', function () {
    $this->artisan('akubica:rc2-catalog-fixtures', [
        '--namespace' => AkubicaUatFixtureContract::NAMESPACE,
        '--apply' => true,
        '--confirm' => Rc2CatalogFixturesCommand::CONFIRM,
    ])->assertFailed()->expectsOutputToContain('CATALOG_FIXTURE_NAMESPACE_NOT_ALLOWED');
});
