<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryAnalyteAliasSource;
use App\Enums\LaboratoryAnalyteResolutionStatus;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryAnalyteAlias;
use App\Services\LaboratoryResults\Catalog\LaboratoryGdaAnalyteCatalogDefinition;
use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteNameNormalizer;
use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryGdaChemistryAnalyteResolverTest extends TestCase
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

        foreach (LaboratoryGdaAnalyteCatalogDefinition::entries() as $entry) {
            if (($entry['category'] ?? '') !== 'chemistry') {
                continue;
            }

            $analyte = LaboratoryAnalyte::query()->create([
                'code' => $entry['code'],
                'canonical_name' => $entry['canonical_name'],
                'default_unit' => $entry['default_unit'],
                'value_kind' => $entry['value_kind']->value,
                'category' => $entry['category'],
                'is_active' => true,
            ]);

            foreach ($entry['aliases'] as $aliasRaw) {
                $aliasNormalized = LaboratoryAnalyteNameNormalizer::normalize($aliasRaw);

                if ($aliasNormalized === '') {
                    continue;
                }

                LaboratoryAnalyteAlias::query()->updateOrCreate(
                    ['alias_normalized' => $aliasNormalized],
                    [
                        'laboratory_analyte_id' => $analyte->id,
                        'alias_raw' => $aliasRaw,
                        'source' => LaboratoryAnalyteAliasSource::Import,
                        'confidence' => 1.0,
                    ],
                );
            }
        }

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
    public function resuelve_analitos_del_perfil_bioquimico_24_observados_en_pdf_2009(): void
    {
        $cases = [
            ['UREA SERICA', 'FAMEDIC_CHEM_UREA'],
            ['NITROGENO UREICO', 'FAMEDIC_CHEM_BUN'],
            ['BILIRRUBINA TOTAL', 'FAMEDIC_CHEM_BILI_T'],
            ['BILIRRUBINA DIRECTA', 'FAMEDIC_CHEM_BILI_D'],
            ['BILIRRUBINA INDIRECTA', 'FAMEDIC_CHEM_BILI_I'],
            ['PROTEINAS TOTALES', 'FAMEDIC_CHEM_TP'],
            ['ALBUMINA', 'FAMEDIC_CHEM_ALB'],
            ['GLOBULINAS', 'FAMEDIC_CHEM_GLOB'],
            ['RELACION ALBUMINA GLOBULINA', 'FAMEDIC_CHEM_AG_RATIO'],
            ['ASPARTATO AMINO TRANSFERASA (AST/TGO)', 'FAMEDIC_CHEM_AST'],
            ['POTASIO', 'FAMEDIC_CHEM_K'],
            ['FOSFORO', 'FAMEDIC_CHEM_P'],
            ['GAMMA GLUTAMIL TRANSFERASA', 'FAMEDIC_CHEM_GGT'],
        ];

        foreach ($cases as [$rawName, $expectedCode]) {
            $result = $this->resolver->resolveDetailed($rawName);

            $this->assertSame(
                LaboratoryAnalyteResolutionStatus::Resolved,
                $result->status,
                "Failed resolving {$rawName}",
            );
            $this->assertSame($expectedCode, $result->analyteCode());
        }
    }

    #[Test]
    public function resuelve_alias_cortos_ast_y_tgo(): void
    {
        $this->assertSame('FAMEDIC_CHEM_AST', $this->resolver->resolveDetailed('AST')->analyteCode());
        $this->assertSame('FAMEDIC_CHEM_AST', $this->resolver->resolveDetailed('TGO')->analyteCode());
    }

    #[Test]
    public function estructuras_radiologicas_siguen_unresolved(): void
    {
        $result = $this->resolver->resolveDetailed('Riñón derecho de');

        $this->assertSame(LaboratoryAnalyteResolutionStatus::Unresolved, $result->status);
        $this->assertNull($result->analyte);
    }
}
