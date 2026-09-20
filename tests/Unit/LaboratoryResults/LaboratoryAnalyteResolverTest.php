<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryAnalyteResolutionStatus;
use App\Models\LaboratoryAnalyte;
use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\LaboratoryResults\Support\HemogramAnalyteCatalog;

class LaboratoryAnalyteResolverTest extends TestCase
{
    use HemogramAnalyteCatalog;

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

        $this->seedHemogramAnalyteCatalog();
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
    public function resuelve_hgm_por_alias(): void
    {
        $result = $this->resolver->resolveDetailed('HGM');

        $this->assertSame(LaboratoryAnalyteResolutionStatus::Resolved, $result->status);
        $this->assertSame('FAMEDIC_CBC_HGM', $result->analyteCode());
    }

    #[Test]
    public function hemoglobina_y_hgm_son_analitos_distintos(): void
    {
        $hgm = $this->resolver->resolveDetailed('HGM');
        $hb = $this->resolver->resolveDetailed('Hemoglobina');

        $this->assertSame('FAMEDIC_CBC_HGM', $hgm->analyteCode());
        $this->assertSame('FAMEDIC_CBC_HGB', $hb->analyteCode());
        $this->assertNotSame($hgm->identityKey, $hb->identityKey);
    }

    #[Test]
    public function resuelve_eritrocitos_plaquetas_rdw_neutrofilos_vpm(): void
    {
        $this->assertSame('FAMEDIC_CBC_RBC', $this->resolver->resolveDetailed('Eritrocitos')->analyteCode());
        $this->assertSame('FAMEDIC_CBC_PLT', $this->resolver->resolveDetailed('Plaquetas')->analyteCode());
        $this->assertSame('FAMEDIC_CBC_RDW', $this->resolver->resolveDetailed('RDW')->analyteCode());
        $this->assertSame('FAMEDIC_CBC_NEUT', $this->resolver->resolveDetailed('Neutrófilos totales')->analyteCode());
        $this->assertSame('FAMEDIC_CBC_VPM', $this->resolver->resolveDetailed('VPM')->analyteCode());
    }

    #[Test]
    public function analito_desconocido_queda_unresolved(): void
    {
        $result = $this->resolver->resolveDetailed('Analito inventado QA');

        $this->assertSame(LaboratoryAnalyteResolutionStatus::Unresolved, $result->status);
        $this->assertNull($result->analyte);
    }

    #[Test]
    public function resuelve_por_analyte_code_explicito(): void
    {
        $result = $this->resolver->resolveDetailed('Texto irrelevante', 'FAMEDIC_CBC_RDW');

        $this->assertSame('FAMEDIC_CBC_RDW', $result->analyteCode());
    }
}
