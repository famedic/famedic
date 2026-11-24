<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Enums\LaboratoryBrand;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\LaboratoryTestPackage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Namespace-scoped catalog preparation for the local B01/B02/B04/B05 block.
 * It is deliberately separate from the legacy catalog and from price fixtures.
 */
final class Rc2CatalogFixturesCommand extends Command
{
    public const CONFIRM = 'rc2-catalog-fixtures';

    protected $signature = 'akubica:rc2-catalog-fixtures
                            {--dry-run : Muestra el plan sin escribir}
                            {--apply : Crea las variantes de catálogo}
                            {--cleanup : Elimina sólo las variantes de este namespace}
                            {--namespace= : Namespace dedicado obligatorio}
                            {--confirm= : Confirmación exacta para escribir}';

    protected $description = 'Prepara variantes sintéticas namespace-scoped para B01/B02/B04/B05.';

    public function handle(): int
    {
        if (! app()->environment((array) config('akubica_uat.allowed_environments', ['testing', 'staging']))) {
            return $this->emit('error', ['error_code' => 'CATALOG_FIXTURE_ENVIRONMENT_NOT_ALLOWED'], self::FAILURE);
        }

        $namespace = (string) $this->option('namespace');
        if ($namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE) {
            return $this->emit('error', ['error_code' => 'CATALOG_FIXTURE_NAMESPACE_NOT_ALLOWED'], self::FAILURE);
        }
        if (AkubicaUatFixtureManifest::query()->where('namespace', $namespace)
            ->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)->doesntExist()) {
            return $this->emit('error', ['error_code' => 'CATALOG_FIXTURE_NAMESPACE_NOT_ACTIVE'], self::FAILURE);
        }

        $apply = (bool) $this->option('apply');
        $cleanup = (bool) $this->option('cleanup');
        if ($apply && $cleanup) {
            return $this->emit('error', ['error_code' => 'CATALOG_FIXTURE_INVALID_OPTION_COMBINATION'], self::FAILURE);
        }
        if (! $apply && ! $cleanup || $this->option('dry-run')) {
            return $this->emit('ok', ['mode' => 'dry-run', 'plan' => $this->plan()]);
        }
        if ((string) $this->option('confirm') !== self::CONFIRM) {
            return $this->emit('error', ['error_code' => 'CATALOG_FIXTURE_CONFIRMATION_MISMATCH'], self::FAILURE);
        }

        $lock = Cache::lock('akubica-rc2-catalog-fixtures:'.$namespace, 30);
        if (! $lock->get()) {
            return $this->emit('error', ['error_code' => 'CATALOG_FIXTURE_LOCK_UNAVAILABLE'], self::FAILURE);
        }
        try {
            return DB::transaction(fn () => $apply ? $this->apply() : $this->cleanup());
        } finally {
            $lock->release();
        }
    }

    private function prefix(): string
    {
        return 'RC2-CATALOG-FIX-'.AkubicaUatFixtureContract::DEDICATED_NAMESPACE.'-';
    }

    private function definitions(): array
    {
        return [
            'B01' => ['gda_id' => $this->prefix().'B01', 'name' => '[UAT] RC2 B01 estudio disponible', 'public_price_cents' => 45000, 'famedic_price_cents' => 35000],
            'B02_COMPONENT' => ['gda_id' => $this->prefix().'B02-COMP', 'name' => '[UAT] RC2 B02 componente', 'public_price_cents' => 50000, 'famedic_price_cents' => 30000],
            'B02' => ['gda_id' => $this->prefix().'B02', 'name' => '[UAT] RC2 B02 paquete', 'public_price_cents' => 155400, 'famedic_price_cents' => 67900],
            'B04' => ['gda_id' => $this->prefix().'B04', 'name' => '[UAT] RC2 B04 sin ahorro', 'public_price_cents' => 35000, 'famedic_price_cents' => 35000],
            'B05' => ['gda_id' => $this->prefix().'B05', 'name' => '[UAT] RC2 B05 inconsistente', 'public_price_cents' => 30000, 'famedic_price_cents' => 35000],
        ];
    }

    private function plan(): array
    {
        $result = [];
        foreach ($this->definitions() as $key => $definition) {
            $row = LaboratoryTest::query()->withTrashed()->where('gda_id', $definition['gda_id'])->first();
            $result[$key] = ['gda_id' => $definition['gda_id'], 'exists' => $row !== null, 'id' => $row?->id];
        }
        $result['package_relation'] = ['supported_interface' => true, 'package_test' => $result['B02']['gda_id'], 'component' => $result['B02_COMPONENT']['gda_id']];

        return $result;
    }

    private function apply(): int
    {
        $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => $this->prefix().'category']);
        $rows = [];
        $tests = [];
        foreach ($this->definitions() as $key => $definition) {
            $test = LaboratoryTest::query()->withTrashed()->where('gda_id', $definition['gda_id'])->first() ?? new LaboratoryTest;
            if ($test->exists && $test->trashed()) {
                $test->restore();
            }
            $test->fill([
                'brand' => LaboratoryBrand::OLAB->value,
                'gda_id' => $definition['gda_id'],
                'name' => $definition['name'],
                'indications' => '[UAT] estudio sintético RC2; no clínico',
                'requires_appointment' => false,
                'public_price_cents' => $definition['public_price_cents'],
                'famedic_price_cents' => $definition['famedic_price_cents'],
                'laboratory_test_category_id' => $category->id,
            ])->save();
            $tests[$key] = $test;
            $rows[$key] = ['id' => $test->id, 'gda_id' => $test->gda_id, 'brand' => 'olab'];
        }
        $package = LaboratoryTestPackage::query()->firstOrCreate(['package_test_id' => $tests['B02']->id]);
        $package->components()->syncWithoutDetaching([$tests['B02_COMPONENT']->id => ['sort_order' => 1]]);
        $rows['B02']['package_id'] = $package->id;

        return $this->emit('ok', ['mode' => 'apply', 'fixtures' => $rows]);
    }

    private function cleanup(): int
    {
        $ids = LaboratoryTest::query()->withTrashed()->where('gda_id', 'like', $this->prefix().'%')->pluck('id');
        $packages = LaboratoryTestPackage::query()->whereIn('package_test_id', $ids)->pluck('id');
        $components = $packages->isEmpty() ? 0 : DB::table('laboratory_test_package_components')->whereIn('package_id', $packages)->delete();
        $deletedPackages = LaboratoryTestPackage::query()->whereIn('id', $packages)->delete();
        $deletedTests = LaboratoryTest::query()->withTrashed()->whereIn('id', $ids)->forceDelete();
        $deletedCategories = LaboratoryTestCategory::query()->where('name', $this->prefix().'category')
            ->whereDoesntHave('laboratoryTests')->delete();

        return $this->emit('ok', ['mode' => 'cleanup', 'deleted' => ['components' => $components, 'packages' => $deletedPackages, 'tests' => $deletedTests, 'categories' => $deletedCategories]]);
    }

    private function emit(string $status, array $payload, int $code = self::SUCCESS): int
    {
        $this->line(json_encode(['status' => $status] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $code;
    }
}
