<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryResultVisionPiiSafetyStatus;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionGdaCropPlan;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionGdaCropPlanner;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPdfTextPositionReader;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiPatternMatcher;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderResult;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderer;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiVisualRedactor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionRealPdfPageRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultVisionPiiSafePageRendererTest extends TestCase
{
    #[Test]
    public function pagina_unsafe_no_retorna_imagen(): void
    {
        $realPdfRenderer = $this->createMock(LaboratoryResultVisionRealPdfPageRenderer::class);
        $realPdfRenderer->method('isAvailable')->willReturn(true);

        $positionReader = $this->createMock(LaboratoryResultVisionPdfTextPositionReader::class);
        $positionReader->method('isAvailable')->willReturn(true);
        $positionReader->method('readPageWords')->willReturn([
            ['page' => 1, 'text' => 'NOTA', 'left' => 10, 'top' => 50, 'width' => 20, 'height' => 8, 'line_num' => 1],
        ]);

        $cropPlanner = $this->createMock(LaboratoryResultVisionGdaCropPlanner::class);
        $cropPlanner->method('plan')->willReturn(new LaboratoryResultVisionGdaCropPlan(
            status: LaboratoryResultVisionPiiSafetyStatus::Unsafe,
            unsafeReason: 'table_header_not_found',
        ));

        $renderer = new LaboratoryResultVisionPiiSafePageRenderer(
            realPdfPageRenderer: $realPdfRenderer,
            textPositionReader: $positionReader,
            cropPlanner: $cropPlanner,
            visualRedactor: new LaboratoryResultVisionPiiVisualRedactor(new LaboratoryResultVisionPiiPatternMatcher),
        );

        $result = $renderer->render('pdf-bytes', 1);

        $this->assertSame(LaboratoryResultVisionPiiSafetyStatus::Unsafe, $result->piiSafetyStatus);
        $this->assertNull($result->pngBase64);
        $this->assertFalse($result->isSendableToVision());
        $this->assertSame('table_header_not_found', $result->unsafeReason);
    }

    #[Test]
    public function diagnostico_no_contiene_base64_ni_pii(): void
    {
        $result = (new LaboratoryResultVisionPiiSafePageRenderResult(
            piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::SafeCrop,
            pngBase64: base64_encode('secret-image'),
            layoutFamily: 'swisslab',
            cropMethod: 'gda_table_header_crop',
            qualityStatus: 'high',
            cropPoints: ['top_pt' => 212.0, 'page_width_pt' => 612.0, 'page_height_pt' => 792.0],
            outputWidth: 800,
            outputHeight: 600,
            sourceDpi: 150,
        ))->toPageDiagnostic(1, true);

        $serialized = json_encode($result);

        $this->assertSame('SAFE_CROP', $result['pii_safety_status']);
        $this->assertSame('pii_safe_raster', $result['image_source']);
        $this->assertArrayHasKey('crop_coordinates_pt', $result);
        $this->assertStringNotContainsString('base64', strtolower($serialized));
        $this->assertStringNotContainsString('secret-image', $serialized);
        $this->assertStringNotContainsString('juan', strtolower($serialized));
    }

    #[Test]
    public function crop_planner_detecta_encabezado_swisslab(): void
    {
        $planner = new LaboratoryResultVisionGdaCropPlanner;

        $plan = $planner->plan([
            ['page' => 1, 'text' => 'Paciente:', 'left' => 50, 'top' => 120, 'width' => 40, 'height' => 8, 'line_num' => 1],
            ['page' => 1, 'text' => 'ESTUDIO', 'left' => 40, 'top' => 212, 'width' => 50, 'height' => 8, 'line_num' => 2],
            ['page' => 1, 'text' => 'RESULTADO', 'left' => 200, 'top' => 212, 'width' => 60, 'height' => 8, 'line_num' => 2],
            ['page' => 1, 'text' => 'UNIDADES', 'left' => 320, 'top' => 212, 'width' => 50, 'height' => 8, 'line_num' => 2],
            ['page' => 1, 'text' => 'HEMOGLOBINA', 'left' => 40, 'top' => 250, 'width' => 80, 'height' => 8, 'line_num' => 3],
            ['page' => 1, 'text' => '14.4', 'left' => 200, 'top' => 250, 'width' => 20, 'height' => 8, 'line_num' => 3],
        ]);

        $this->assertSame(LaboratoryResultVisionPiiSafetyStatus::SafeCrop, $plan->status);
        $this->assertSame(210.0, $plan->cropTopPt);
        $this->assertSame('gda_table_header_crop', $plan->cropMethod);
    }

    #[Test]
    public function pii_pattern_matcher_detecta_curp_y_etiquetas(): void
    {
        $matcher = new LaboratoryResultVisionPiiPatternMatcher;

        $this->assertTrue($matcher->textContainsPiiLabel('Nombre del paciente'));
        $this->assertTrue($matcher->textMatchesPiiValue('ABCD900101HDFRRR09'));
        $this->assertFalse($matcher->textMatchesPiiValue('14.4'));
    }
}
