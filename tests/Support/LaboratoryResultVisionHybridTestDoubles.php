<?php

namespace Tests\Support;

use App\Enums\LaboratoryResultVisionPiiSafetyStatus;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultTextExtractor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionExtractor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionHybridMessageBuilder;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPageSelector;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPdfTextPositionReader;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderResult;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderer;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionResponseParser;
use App\Services\OpenAi\OpenAiClient;
use Mockery;
use Mockery\MockInterface;

trait LaboratoryResultVisionHybridTestDoubles
{
    protected function bindPiiSafeRendererMock(
        ?LaboratoryResultVisionPiiSafePageRenderResult $renderResult = null,
    ): MockInterface {
        $renderResult ??= new LaboratoryResultVisionPiiSafePageRenderResult(
            piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::SafeCrop,
            pngBase64: base64_encode('fake-png-raster'),
            layoutFamily: 'swisslab',
            cropMethod: 'gda_table_header_crop',
            qualityStatus: 'high',
            cropPoints: ['top_pt' => 212.0, 'page_width_pt' => 612.0, 'page_height_pt' => 792.0],
            outputWidth: 800,
            outputHeight: 600,
            sourceDpi: 150,
        );

        /** @var MockInterface&LaboratoryResultVisionPiiSafePageRenderer $renderer */
        $renderer = Mockery::mock(LaboratoryResultVisionPiiSafePageRenderer::class);
        $renderer->shouldReceive('isAvailable')->andReturn(true);
        $renderer->shouldReceive('render')->andReturn($renderResult);

        if (method_exists($this, 'app')) {
            app()->instance(LaboratoryResultVisionPiiSafePageRenderer::class, $renderer);
        }

        return $renderer;
    }

    /** @deprecated use bindPiiSafeRendererMock */
    protected function bindRealPdfRendererMock(): MockInterface
    {
        return $this->bindPiiSafeRendererMock();
    }

    protected function makeHybridVisionExtractor(OpenAiClient $openAi): LaboratoryResultVisionExtractor
    {
        $piiSafeRenderer = Mockery::mock(LaboratoryResultVisionPiiSafePageRenderer::class);
        $piiSafeRenderer->shouldReceive('isAvailable')->andReturn(true);
        $piiSafeRenderer->shouldReceive('render')->andReturn(new LaboratoryResultVisionPiiSafePageRenderResult(
            piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::SafeCrop,
            pngBase64: base64_encode('fake-png-raster'),
            layoutFamily: 'swisslab',
            cropMethod: 'gda_table_header_crop',
            qualityStatus: 'high',
            cropPoints: ['top_pt' => 212.0, 'page_width_pt' => 612.0, 'page_height_pt' => 792.0],
            outputWidth: 800,
            outputHeight: 600,
            sourceDpi: 150,
        ));
        $textPositionReader = Mockery::mock(LaboratoryResultVisionPdfTextPositionReader::class);
        $textPositionReader->shouldReceive('readPageWords')->andReturn([]);

        return new LaboratoryResultVisionExtractor(
            openAiClient: $openAi,
            textExtractor: app(LaboratoryResultTextExtractor::class),
            pageSelector: new LaboratoryResultVisionPageSelector,
            hybridMessageBuilder: new LaboratoryResultVisionHybridMessageBuilder($piiSafeRenderer, $textPositionReader),
            piiSafePageRenderer: $piiSafeRenderer,
            responseParser: new LaboratoryResultVisionResponseParser,
        );
    }
}
