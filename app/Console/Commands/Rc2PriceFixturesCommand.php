<?php

namespace App\Console\Commands;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * RC2 B03/B06 (D6): synthetic, reversible price fixtures.
 *  - B03: study without public reference price (public_price_cents = 0 -> public_price_unavailable).
 *  - B06: study with a mutable FAMEDIC price (--mutate toggles 30000 <-> 31500) to prove server-side revalidation.
 * Default mode is dry-run. Writes require --apply/--mutate/--cleanup plus --confirm=rc2-price-fixtures.
 * Only touches rows marked with the RC2-PRICE-FIX- gda_id prefix; never runs outside allowed environments.
 */
class Rc2PriceFixturesCommand extends Command
{
    public const CONFIRM = 'rc2-price-fixtures';

    public const PREFIX = 'RC2-PRICE-FIX-';

    public const CATEGORY = '[UAT] RC2 precios';

    public const B06_PRICES = [30000, 31500];

    protected $signature = 'akubica:rc2-price-fixtures
                            {--dry-run : Muestra el plan sin escribir (modo por omision)}
                            {--apply : Crea o restaura los fixtures B03/B06}
                            {--mutate : Alterna el precio FAMEDIC del fixture B06}
                            {--cleanup : Elimina exclusivamente los fixtures marcados y sus lineas de carrito}
                            {--namespace= : Namespace dedicado; obligatorio para preparación QA aislada}
                            {--confirm= : Confirmacion exacta para escribir}';

    protected $description = 'Fixtures sinteticos y reversibles de precio para B03 (sin precio publico) y B06 (precio mutable).';

    public function handle(): int
    {
        if (! app()->environment((array) config('akubica_uat.allowed_environments', ['testing', 'staging']))) {
            return $this->emit('error', ['error_code' => 'FIXTURE_ENVIRONMENT_NOT_ALLOWED'], self::FAILURE);
        }

        $namespace = $this->option('namespace');
        if ($namespace !== null && $namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE) {
            return $this->emit('error', ['error_code' => 'FIXTURE_NAMESPACE_NOT_ALLOWED'], self::FAILURE);
        }
        if ($namespace !== null && AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->where('status', AkubicaUatFixtureContract::STATUS_ACTIVE)->doesntExist()) {
            return $this->emit('error', ['error_code' => 'FIXTURE_NAMESPACE_NOT_ACTIVE'], self::FAILURE);
        }

        $modes = array_filter(['apply' => $this->option('apply'), 'mutate' => $this->option('mutate'), 'cleanup' => $this->option('cleanup')]);
        if (count($modes) > 1) {
            return $this->emit('error', ['error_code' => 'FIXTURE_INVALID_OPTION_COMBINATION'], self::FAILURE);
        }

        $mode = array_key_first($modes) ?? 'dry-run';
        if ($this->option('dry-run') || $mode === 'dry-run') {
            return $this->emit('ok', ['mode' => 'dry-run', 'namespace' => $namespace, 'plan' => $this->plan()]);
        }

        if ((string) $this->option('confirm') !== self::CONFIRM) {
            return $this->emit('error', ['error_code' => 'FIXTURE_CONFIRMATION_MISMATCH'], self::FAILURE);
        }

        $lock = Cache::lock('akubica-rc2-price-fixtures', 30);
        if (! $lock->get()) {
            return $this->emit('error', ['error_code' => 'FIXTURE_LOCK_UNAVAILABLE'], self::FAILURE);
        }

        try {
            return LaboratoryTest::withoutSyncingToSearch(fn () => DB::transaction(fn () => match ($mode) {
                'apply' => $this->apply(),
                'mutate' => $this->mutate(),
                'cleanup' => $this->cleanup(),
            }));
        } finally {
            $lock->release();
        }
    }

    private function prefix(): string
    {
        return $this->option('namespace') === null ? self::PREFIX : self::PREFIX.$this->option('namespace').'-';
    }

    private function category(): string
    {
        return $this->option('namespace') === null ? self::CATEGORY : self::CATEGORY.' · '.$this->option('namespace');
    }

    private function definitions(): array
    {
        return [
            'B03' => ['gda_id' => $this->prefix().'B03', 'name' => '[UAT] RC2 B03 sin precio publico', 'public_price_cents' => 0, 'famedic_price_cents' => 25000],
            'B06' => ['gda_id' => $this->prefix().'B06', 'name' => '[UAT] RC2 B06 precio mutable', 'public_price_cents' => 40000, 'famedic_price_cents' => self::B06_PRICES[0]],
        ];
    }

    private function plan(): array
    {
        $plan = [];
        foreach ($this->definitions() as $scenario => $definition) {
            $existing = LaboratoryTest::query()->withTrashed()->where('gda_id', $definition['gda_id'])->first();
            $plan[$scenario] = [
                'gda_id' => $definition['gda_id'],
                'exists' => $existing !== null,
                'id' => $existing?->id,
                'famedic_price_cents' => $existing?->famedic_price_cents ?? $definition['famedic_price_cents'],
                'public_price_cents' => $existing?->public_price_cents ?? $definition['public_price_cents'],
            ];
        }

        return $plan;
    }

    private function apply(): int
    {
        $category = LaboratoryTestCategory::query()->withTrashed()->firstOrCreate(['name' => $this->category()]);
        if ($category->trashed()) {
            $category->restore();
        }

        $result = [];
        foreach ($this->definitions() as $scenario => $definition) {
            $test = LaboratoryTest::query()->withTrashed()->where('gda_id', $definition['gda_id'])->first() ?? new LaboratoryTest;
            if ($test->exists && $test->trashed()) {
                $test->restore();
            }
            $test->fill([
                'brand' => LaboratoryBrand::OLAB->value,
                'gda_id' => $definition['gda_id'],
                'name' => $definition['name'],
                'indications' => '[UAT] estudio sintetico RC2; no clinico',
                'requires_appointment' => false,
                'public_price_cents' => $definition['public_price_cents'],
                'famedic_price_cents' => $definition['famedic_price_cents'],
                'laboratory_test_category_id' => $category->id,
            ])->save();
            $result[$scenario] = ['id' => $test->id, 'gda_id' => $test->gda_id, 'brand' => 'olab',
                'famedic_price_cents' => $test->famedic_price_cents, 'public_price_cents' => $test->public_price_cents];
        }

        return $this->emit('ok', ['mode' => 'apply', 'fixtures' => $result]);
    }

    private function mutate(): int
    {
        $test = LaboratoryTest::query()->where('gda_id', $this->definitions()['B06']['gda_id'])->first();
        if ($test === null) {
            return $this->emit('error', ['error_code' => 'FIXTURE_NOT_APPLIED'], self::FAILURE);
        }

        $before = (int) $test->famedic_price_cents;
        $after = $before === self::B06_PRICES[0] ? self::B06_PRICES[1] : self::B06_PRICES[0];
        $test->forceFill(['famedic_price_cents' => $after])->save();

        return $this->emit('ok', ['mode' => 'mutate', 'B06' => ['id' => $test->id, 'famedic_price_cents_before' => $before, 'famedic_price_cents_after' => $after]]);
    }

    private function cleanup(): int
    {
        $ids = LaboratoryTest::query()->withTrashed()->where('gda_id', 'like', $this->prefix().'%')->pluck('id');
        $cartLines = LaboratoryCartItem::query()->withTrashed()->whereIn('laboratory_test_id', $ids)->forceDelete();
        $tests = LaboratoryTest::query()->withTrashed()->whereIn('id', $ids)->forceDelete();
        $category = LaboratoryTestCategory::query()->withTrashed()->where('name', $this->category())
            ->whereDoesntHave('laboratoryTests', fn ($q) => $q->withTrashed())->forceDelete();

        return $this->emit('ok', ['mode' => 'cleanup', 'deleted' => ['laboratory_tests' => $tests, 'laboratory_cart_items' => $cartLines, 'categories' => $category]]);
    }

    private function emit(string $status, array $payload, int $code = self::SUCCESS): int
    {
        $this->line(json_encode(['status' => $status] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $code;
    }
}
