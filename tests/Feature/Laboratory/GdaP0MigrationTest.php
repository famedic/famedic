<?php

namespace Tests\Feature\Laboratory;

use App\Enums\GdaOrderStatus;
use App\Models\LaboratoryPurchase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Only the isolated MySQL runner; never the application database or migrate:fresh. */
class GdaP0MigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_16_220000_add_gda_order_status_to_laboratory_purchases';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id(); $table->string('gda_order_id')->nullable(); $table->unsignedBigInteger('gda_consecutivo')->nullable();
            $table->unsignedInteger('total_cents'); $table->timestamps(); $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('laboratory_purchases');
        Schema::dropIfExists('qa_gda_status_reference');
        Schema::dropIfExists('migrations');
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    private function state(): array
    {
        $schema = (array) DB::selectOne('SHOW CREATE TABLE laboratory_purchases');
        return [$schema['Create Table'], DB::table('laboratory_purchases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }

    private function imported(string $kind = 'equivalent'): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) use ($kind) {
            $column = $kind === 'enum' ? $table->enum('gda_status', ['pending', 'confirmed', 'failed', 'uncertain'])
                : $table->string('gda_status', $kind === 'length' ? 255 : 32);
            $column->nullable($kind !== 'nullable');
            if ($kind === 'default') { $column->default('pending'); }
            if ($kind === 'collation') { $column->collation('utf8mb4_bin'); }
            if ($kind === 'unique') { $table->unique('gda_status', 'laboratory_purchases_gda_status_idx'); }
            elseif ($kind === 'composite') { $table->index(['gda_status', 'total_cents'], 'laboratory_purchases_gda_status_idx'); }
            elseif ($kind !== 'missing_index') { $table->index('gda_status', 'laboratory_purchases_gda_status_idx'); }
        });
        if ($kind === 'prefix') {
            DB::statement('ALTER TABLE laboratory_purchases DROP INDEX laboratory_purchases_gda_status_idx, ADD INDEX laboratory_purchases_gda_status_idx (gda_status(8))');
        }
        if ($kind === 'invisible') {
            DB::statement('ALTER TABLE laboratory_purchases ALTER INDEX laboratory_purchases_gda_status_idx INVISIBLE');
        }
        if ($kind === 'extra_unique') {
            Schema::table('laboratory_purchases', fn (Blueprint $table) => $table->unique(['gda_status', 'total_cents'], 'unexpected_status_unique'));
        }
        if ($kind === 'extra_check') {
            DB::statement("ALTER TABLE laboratory_purchases ADD CONSTRAINT unexpected_status_check CHECK (gda_status IS NULL OR gda_status = 'pending')");
        }
        if ($kind === 'extra_foreign') {
            Schema::create('qa_gda_status_reference', fn (Blueprint $table) => $table->string('status', 32)->primary());
            DB::table('qa_gda_status_reference')->insert(['status' => 'pending']);
            Schema::table('laboratory_purchases', fn (Blueprint $table) => $table->foreign('gda_status')->references('status')->on('qa_gda_status_reference'));
        }
        $value = match ($kind) {
            'unknown' => 'completed', 'case' => 'Confirmed', 'whitespace' => 'confirmed ',
            'evidence', 'soft_deleted_evidence' => 'confirmed', default => 'pending',
        };
        DB::table('laboratory_purchases')->insert(['gda_order_id' => 'QA-IMPORTED',
            'gda_consecutivo' => in_array($kind, ['evidence', 'soft_deleted_evidence'], true) ? 0 : 123,
            'total_cents' => 100, 'gda_status' => $value,
            'deleted_at' => $kind === 'soft_deleted_evidence' ? now() : null]);
    }

    #[Test]
    public function fresh_schema_is_additive_without_backfill_and_repeated_up_preserves_history(): void
    {
        foreach ([['LEGACY-OK', 123], ['0', 0], [null, null]] as [$folio, $consecutive]) {
            DB::table('laboratory_purchases')->insert(['gda_order_id' => $folio, 'gda_consecutivo' => $consecutive, 'total_cents' => 100]);
        }
        $before = $this->state()[1];
        $this->migration()->up();
        $this->assertSame(3, DB::table('laboratory_purchases')->whereNull('gda_status')->count());
        $after = $this->state(); $this->migration()->up();
        $this->assertSame($after, $this->state());
        $rows = array_map(function ($row) { unset($row['gda_status']); return $row; }, $after[1]);
        $this->assertSame($before, $rows);
        $purchase = LaboratoryPurchase::create(['gda_order_id' => 'NEW-QA', 'gda_consecutivo' => null, 'total_cents' => 400]);
        $this->assertSame(GdaOrderStatus::Pending, $purchase->fresh()->gda_status);
    }

    #[Test]
    public function equivalent_import_is_registered_once_by_laravel_without_ddl_or_backfill(): void
    {
        $this->imported();
        foreach ([null, 'confirmed', 'uncertain', 'failed'] as $status) {
            DB::table('laboratory_purchases')->insert(['gda_status' => $status, 'gda_order_id' => 'IMPORTED-OK',
                'gda_consecutivo' => 456, 'total_cents' => 200, 'deleted_at' => now()]);
        }
        $before = $this->state();
        $options = ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true];
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertSame($before, $this->state());
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertSame(1, DB::table('migrations')->count());
        $this->assertSame($before, $this->state());
    }

    public static function incompatibleImports(): array
    {
        return array_map(fn ($kind) => [$kind], ['enum', 'length', 'nullable', 'default', 'collation', 'unique', 'composite',
            'missing_index', 'prefix', 'invisible', 'extra_unique', 'extra_check', 'extra_foreign', 'unknown', 'case', 'whitespace', 'evidence', 'soft_deleted_evidence']);
    }

    #[Test, DataProvider('incompatibleImports')]
    public function incompatible_imports_stop_without_changing_schema_rows_or_history(string $kind): void
    {
        $this->imported($kind); $before = $this->state();
        app('migration.repository')->createRepository();
        try {
            Artisan::call('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true]);
            $this->fail('Incompatible import must not be baselined.');
        } catch (\RuntimeException $safe) {
            $this->assertStringStartsWith('gda_status_schema_incompatible:', $safe->getMessage());
        }
        $this->assertSame($before, $this->state());
        $this->assertSame(0, DB::table('migrations')->count());
    }

    #[Test]
    public function rollback_never_drops_imported_column_or_purchase_evidence(): void
    {
        $this->imported(); $before = $this->state();
        $migration = $this->migration(); $migration->up();
        try { $migration->down(); $this->fail('Destructive rollback must be blocked.'); }
        catch (\RuntimeException $safe) { $this->assertSame('gda_status_rollback_blocked: preserve_historical_evidence', $safe->getMessage()); }
        $this->assertSame($before, $this->state());
        $migration->up();
        $this->assertSame($before, $this->state());
    }
}
