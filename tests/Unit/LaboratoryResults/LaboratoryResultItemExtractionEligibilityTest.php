<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Services\LaboratoryResults\LaboratoryResultItemExtractionEligibility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultItemExtractionEligibilityTest extends TestCase
{
    private LaboratoryResultItemExtractionEligibility $eligibility;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->bootstrapCatalogSchema();
        LaboratoryTest::disableSearchSyncing();
        $this->eligibility = app(LaboratoryResultItemExtractionEligibility::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('laboratory_tests');
        Schema::dropIfExists('laboratory_test_categories');

        parent::tearDown();
    }

    #[Test]
    public function perfil_bioquimico_24_es_elegible_por_nombre_de_item(): void
    {
        $result = $this->eligibility->evaluate(
            $this->purchaseItem('PERFIL BIOQUIMICO 24 (ELEC,CALCIO,FOS,TGP)', 'BIO-24'),
            LaboratoryBrand::OLAB,
        );

        $this->assertTrue($result->eligible);
        $this->assertSame(
            LaboratoryResultItemExtractionEligibility::REASON_CLINICAL_STUDY_NAME,
            $result->reason
        );
        $this->assertSame(
            LaboratoryResultItemExtractionEligibility::SOURCE_PURCHASE_ITEM_NAME,
            $result->source
        );
    }

    #[Test]
    public function eco_abdomen_superior_no_es_elegible_por_nombre_de_item(): void
    {
        $result = $this->eligibility->evaluate(
            $this->purchaseItem('ECO ABDOMEN SUPERIOR', 'ECO-ABD'),
            LaboratoryBrand::OLAB,
        );

        $this->assertFalse($result->eligible);
        $this->assertSame(
            LaboratoryResultItemExtractionEligibility::REASON_IMAGING_STUDY_NAME,
            $result->reason
        );
    }

    #[Test]
    public function usa_categoria_de_catalogo_para_imagen(): void
    {
        $category = LaboratoryTestCategory::query()->create(['name' => 'Ultrasonido Convencional']);
        $this->seedCatalogTest('ECO-1', 'ECO DE HIGADO', $category, LaboratoryBrand::OLAB);

        $result = $this->eligibility->evaluate(
            $this->purchaseItem('ECO DE HIGADO', 'ECO-1'),
            LaboratoryBrand::OLAB,
        );

        $this->assertFalse($result->eligible);
        $this->assertSame(
            LaboratoryResultItemExtractionEligibility::REASON_IMAGING_CATEGORY,
            $result->reason
        );
        $this->assertSame(
            LaboratoryResultItemExtractionEligibility::SOURCE_CATALOG_CATEGORY,
            $result->source
        );
    }

    #[Test]
    public function usa_categoria_de_catalogo_para_laboratorio_clinico(): void
    {
        $category = LaboratoryTestCategory::query()->create(['name' => 'Química sanguínea']);
        $this->seedCatalogTest('BIO-24', 'PERFIL BIOQUIMICO 24', $category, LaboratoryBrand::OLAB);

        $result = $this->eligibility->evaluate(
            $this->purchaseItem('PERFIL BIOQUIMICO 24', 'BIO-24'),
            LaboratoryBrand::OLAB,
        );

        $this->assertTrue($result->eligible);
        $this->assertSame(
            LaboratoryResultItemExtractionEligibility::REASON_CLINICAL_CATEGORY,
            $result->reason
        );
    }

    #[Test]
    public function estudio_desconocido_no_es_elegible(): void
    {
        $result = $this->eligibility->evaluate(
            $this->purchaseItem('Consulta general', 'GEN-1'),
            LaboratoryBrand::OLAB,
        );

        $this->assertFalse($result->eligible);
        $this->assertSame(
            LaboratoryResultItemExtractionEligibility::REASON_UNKNOWN_STUDY_TYPE,
            $result->reason
        );
    }

    private function purchaseItem(string $name, string $gdaId): LaboratoryPurchaseItem
    {
        return new LaboratoryPurchaseItem([
            'name' => $name,
            'gda_id' => $gdaId,
        ]);
    }

    private function seedCatalogTest(
        string $gdaId,
        string $name,
        LaboratoryTestCategory $category,
        LaboratoryBrand $brand,
    ): LaboratoryTest {
        return LaboratoryTest::query()->create([
            'brand' => $brand,
            'gda_id' => $gdaId,
            'name' => $name,
            'indications' => '-',
            'requires_appointment' => false,
            'public_price_cents' => 10000,
            'famedic_price_cents' => 9000,
            'laboratory_test_category_id' => $category->id,
        ]);
    }

    private function bootstrapCatalogSchema(): void
    {
        Schema::dropIfExists('laboratory_tests');
        Schema::dropIfExists('laboratory_test_categories');

        Schema::create('laboratory_test_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_tests', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
            $table->string('gda_id');
            $table->string('name');
            $table->text('indications')->nullable();
            $table->boolean('requires_appointment')->default(false);
            $table->unsignedInteger('public_price_cents')->default(0);
            $table->unsignedInteger('famedic_price_cents')->default(0);
            $table->foreignId('laboratory_test_category_id')->constrained('laboratory_test_categories');
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
