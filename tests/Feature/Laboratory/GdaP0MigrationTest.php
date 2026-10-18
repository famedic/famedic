<?php

namespace Tests\Feature\Laboratory;

use App\Enums\GdaOrderStatus;
use App\Models\LaboratoryPurchase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Run separately on a new disposable MySQL database; no RefreshDatabase or migrate:fresh. */
class GdaP0MigrationTest extends TestCase
{
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
        parent::tearDown();
    }

    #[Test]
    public function migration_is_additive_without_backfill_and_can_be_rolled_back_and_reapplied(): void
    {
        DB::table('laboratory_purchases')->insert([
            ['gda_order_id' => 'LEGACY-OK', 'gda_consecutivo' => 123, 'total_cents' => 100, 'deleted_at' => null],
            ['gda_order_id' => '0', 'gda_consecutivo' => 0, 'total_cents' => 200, 'deleted_at' => null],
            ['gda_order_id' => null, 'gda_consecutivo' => null, 'total_cents' => 300, 'deleted_at' => now()],
        ]);
        $before = DB::table('laboratory_purchases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration = require database_path('migrations/2026_10_16_220000_add_gda_order_status_to_laboratory_purchases.php');
        $migration->up();
        $this->assertSame(3, DB::table('laboratory_purchases')->whereNull('gda_status')->count());
        $this->assertTrue(Schema::hasIndex('laboratory_purchases', 'laboratory_purchases_gda_status_idx'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('laboratory_purchases', 'gda_status'));
        $this->assertSame($before, DB::table('laboratory_purchases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $migration->up();
        $this->assertSame(3, DB::table('laboratory_purchases')->whereNull('gda_status')->count());
        $purchase = LaboratoryPurchase::create(['gda_order_id' => 'NEW-QA', 'gda_consecutivo' => null, 'total_cents' => 400]);
        $this->assertSame(GdaOrderStatus::Pending, $purchase->fresh()->gda_status);
    }

    #[Test]
    public function existing_imported_values_are_preserved_and_require_explicit_schema_review(): void
    {
        Schema::table('laboratory_purchases', fn (Blueprint $table) => $table->string('gda_status', 32)->nullable());
        foreach ([null, 'confirmed', 'completed', 'unknown'] as $value) {
            DB::table('laboratory_purchases')->insert(['gda_order_id' => 'IMPORTED', 'total_cents' => 100, 'gda_status' => $value]);
        }
        $before = DB::table('laboratory_purchases')->orderBy('id')->pluck('gda_status')->all();
        $migration = require database_path('migrations/2026_10_16_220000_add_gda_order_status_to_laboratory_purchases.php');
        try { $migration->up(); $this->fail('Imported column must require review.'); }
        catch (\RuntimeException $safe) { $this->assertStringContainsString('already exists', $safe->getMessage()); }
        $this->assertSame($before, DB::table('laboratory_purchases')->orderBy('id')->pluck('gda_status')->all());
    }
}
