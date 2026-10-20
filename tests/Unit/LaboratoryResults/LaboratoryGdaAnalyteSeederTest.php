<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryAnalyteResolutionStatus;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryAnalyteAlias;
use App\Services\LaboratoryResults\Catalog\LaboratoryGdaAnalyteCatalogDefinition;
use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteResolver;
use Database\Seeders\LaboratoryGdaAnalyteSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryGdaAnalyteSeederTest extends TestCase
{
    private LaboratoryAnalyteResolver $resolver;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        Schema::dropIfExists('laboratory_analyte_aliases');
        Schema::dropIfExists('laboratory_analytes');

        Schema::create('laboratory_analytes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('canonical_name');
            $table->string('loinc_code', 40)->nullable()->unique();
            $table->string('default_unit', 40)->nullable();
            $table->string('value_kind', 20);
            $table->string('category', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('laboratory_analyte_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_analyte_id')->constrained('laboratory_analytes')->cascadeOnDelete();
            $table->string('alias_normalized', 191)->unique();
            $table->string('alias_raw')->nullable();
            $table->string('source', 20);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();
        });

        $this->resolver = new LaboratoryAnalyteResolver;
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('laboratory_analyte_aliases');
        Schema::dropIfExists('laboratory_analytes');
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function seeder_es_idempotente(): void
    {
        (new LaboratoryGdaAnalyteSeeder)->run();
        $firstAnalytes = LaboratoryAnalyte::query()->count();
        $firstAliases = LaboratoryAnalyteAlias::query()->count();

        (new LaboratoryGdaAnalyteSeeder)->run();

        $this->assertSame($firstAnalytes, LaboratoryAnalyte::query()->count());
        $this->assertSame($firstAliases, LaboratoryAnalyteAlias::query()->count());
        $this->assertSame(count(LaboratoryGdaAnalyteCatalogDefinition::entries()), $firstAnalytes);
    }

    #[Test]
    public function hgm_resuelve_a_laboratory_analyte(): void
    {
        (new LaboratoryGdaAnalyteSeeder)->run();

        $result = $this->resolver->resolveDetailed('HGM');

        $this->assertSame(LaboratoryAnalyteResolutionStatus::Resolved, $result->status);
        $this->assertSame('FAMEDIC_CBC_HGM', $result->analyteCode());
    }

    #[Test]
    public function eritrocitos_resuelve_con_alias_distinto(): void
    {
        (new LaboratoryGdaAnalyteSeeder)->run();

        $fromSpanish = $this->resolver->resolveDetailed('Eritrocitos');
        $fromEnglish = $this->resolver->resolveDetailed('RBC');

        $this->assertSame('FAMEDIC_CBC_RBC', $fromSpanish->analyteCode());
        $this->assertSame('FAMEDIC_CBC_RBC', $fromEnglish->analyteCode());
    }

    #[Test]
    public function plaquetas_rdw_neutrofilos_vpm_resuelven_correctamente(): void
    {
        (new LaboratoryGdaAnalyteSeeder)->run();

        $this->assertSame('FAMEDIC_CBC_PLT', $this->resolver->resolveDetailed('Plaquetas')->analyteCode());
        $this->assertSame('FAMEDIC_CBC_RDW', $this->resolver->resolveDetailed('RDW-CV')->analyteCode());
        $this->assertSame('FAMEDIC_CBC_NEUT', $this->resolver->resolveDetailed('Neutrofilos totales')->analyteCode());
        $this->assertSame('FAMEDIC_CBC_VPM', $this->resolver->resolveDetailed('VPM')->analyteCode());
    }

    #[Test]
    public function hgm_no_resuelve_como_hemoglobina_ni_chcm(): void
    {
        (new LaboratoryGdaAnalyteSeeder)->run();

        $hgm = $this->resolver->resolveDetailed('HGM');
        $hb = $this->resolver->resolveDetailed('Hemoglobina');
        $chcm = $this->resolver->resolveDetailed('CHCM');

        $this->assertSame('FAMEDIC_CBC_HGM', $hgm->analyteCode());
        $this->assertSame('FAMEDIC_CBC_HGB', $hb->analyteCode());
        $this->assertSame('FAMEDIC_CBC_CHCM', $chcm->analyteCode());
        $this->assertNotSame($hgm->analyteCode(), $hb->analyteCode());
        $this->assertNotSame($hgm->analyteCode(), $chcm->analyteCode());
    }

    #[Test]
    public function alias_desconocido_permanece_unresolved(): void
    {
        (new LaboratoryGdaAnalyteSeeder)->run();

        $result = $this->resolver->resolveDetailed('Analito inventado QA');

        $this->assertSame(LaboratoryAnalyteResolutionStatus::Unresolved, $result->status);
        $this->assertNull($result->analyte);
    }

    #[Test]
    public function loinc_permanece_null(): void
    {
        (new LaboratoryGdaAnalyteSeeder)->run();

        $this->assertNull(LaboratoryAnalyte::query()->where('code', 'FAMEDIC_CBC_HGM')->value('loinc_code'));
        $this->assertSame(
            0,
            LaboratoryAnalyte::query()->whereNotNull('loinc_code')->count(),
        );
    }
}
