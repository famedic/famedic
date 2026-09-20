<?php

namespace Tests\Unit\LaboratoryResults\Extraction;

use App\Enums\LaboratoryAnalyteAliasSource;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryAnalyteAlias;
use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteResolver;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Laboratory\GdaResultsStorageIsolatedSchema;
use Tests\Feature\Laboratory\StructuredResultsIsolatedSchema;
use Tests\TestCase;

class LaboratoryAnalyteResolverTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownStructuredResultsSchema();
        $this->tearDownIsolatedSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function resuelve_por_alias_exacto(): void
    {
        $analyte = LaboratoryAnalyte::factory()->create(['code' => 'glucose_serum']);
        LaboratoryAnalyteAlias::query()->create([
            'laboratory_analyte_id' => $analyte->id,
            'alias_normalized' => 'glucosa',
            'alias_raw' => 'Glucosa',
            'source' => LaboratoryAnalyteAliasSource::Manual,
        ]);

        $resolved = app(LaboratoryAnalyteResolver::class)->resolve('GLUCÓSA');

        $this->assertTrue($resolved?->is($analyte));
    }

    #[Test]
    public function resuelve_por_code(): void
    {
        $analyte = LaboratoryAnalyte::factory()->create(['code' => 'hemoglobin']);

        $resolved = app(LaboratoryAnalyteResolver::class)->resolve('hemoglobin');

        $this->assertTrue($resolved?->is($analyte));
    }

    #[Test]
    public function sin_match_retorna_null(): void
    {
        $this->assertNull(app(LaboratoryAnalyteResolver::class)->resolve('Analito Desconocido'));
    }
}
