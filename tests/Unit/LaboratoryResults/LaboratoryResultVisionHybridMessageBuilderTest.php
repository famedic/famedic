<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryResultVisionPiiSafetyStatus;
use App\Models\AiPrompt;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionHybridMessageBuilder;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPdfTextPositionReader;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderResult;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderer;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPromptDefinition;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LaboratoryResultsPdfFixture;
use Tests\TestCase;

class LaboratoryResultVisionHybridMessageBuilderTest extends TestCase
{
    #[Test]
    public function construye_bloques_hibridos_por_pagina(): void
    {
        $pdfBinary = LaboratoryResultsPdfFixture::binary(['PLAQUETAS 365 miles/uL 167-431']);
        $piiSafeRenderer = Mockery::mock(LaboratoryResultVisionPiiSafePageRenderer::class);
        $piiSafeRenderer->shouldReceive('render')
            ->once()
            ->with($pdfBinary, 1)
            ->andReturn(new LaboratoryResultVisionPiiSafePageRenderResult(
                piiSafetyStatus: LaboratoryResultVisionPiiSafetyStatus::SafeCrop,
                pngBase64: base64_encode('fake-png'),
                layoutFamily: 'swisslab',
                cropMethod: 'gda_table_header_crop',
                qualityStatus: 'high',
            ));
        $textPositionReader = Mockery::mock(LaboratoryResultVisionPdfTextPositionReader::class);
        $textPositionReader->shouldReceive('readPageWords')
            ->once()
            ->with($pdfBinary, 1)
            ->andReturn($this->tableWords([
                ['(A) PLAQUETAS', '365', 'miles/µL', '167 - 431'],
            ]));

        $builder = new LaboratoryResultVisionHybridMessageBuilder($piiSafeRenderer, $textPositionReader);
        $prompt = AiPrompt::query()->make([
            'user_prompt' => 'Pages: {{page_numbers}}',
            'system_prompt' => 'system',
            'response_schema' => LaboratoryResultVisionPromptDefinition::responseSchema(),
        ]);

        [$messages, $imageCount, $diagnostics] = $builder->build(
            $prompt,
            $pdfBinary,
            [[
                'page' => 1,
                'text' => "PLAQUETAS\t365\tmiles/µL\t167-431",
                'char_count' => 30,
                'score' => 1.0,
            ]],
        );

        $this->assertSame(1, $imageCount);
        $this->assertSame('pii_safe_raster', $diagnostics[0]['image_source']);
        $this->assertSame('SAFE_CROP', $diagnostics[0]['pii_safety_status']);

        $userContent = $messages[1]['content'];
        $this->assertIsArray($userContent);

        $textParts = array_values(array_filter(
            $userContent,
            fn (array $part): bool => ($part['type'] ?? '') === 'text'
        ));

        $structuredBlock = $textParts[1]['text'] ?? '';
        $sourceBlock = $textParts[2]['text'] ?? '';
        $this->assertStringContainsString(LaboratoryResultVisionHybridMessageBuilder::STRUCTURED_TSV_HEADER, $structuredBlock);
        $this->assertStringContainsString('| (A) PLAQUETAS | 365 | miles/µL | 167 - 431 |', $structuredBlock);
        $this->assertStringContainsString(LaboratoryResultVisionHybridMessageBuilder::SOURCE_TEXT_HEADER, $sourceBlock);
        $this->assertStringContainsString('PLAQUETAS', $sourceBlock);
        $this->assertStringContainsString('365', $sourceBlock);
    }

    #[Test]
    public function estructura_filas_tsv_sin_fusionar_decimales_vecinos(): void
    {
        $piiSafeRenderer = Mockery::mock(LaboratoryResultVisionPiiSafePageRenderer::class);
        $textPositionReader = Mockery::mock(LaboratoryResultVisionPdfTextPositionReader::class);
        $builder = new LaboratoryResultVisionHybridMessageBuilder($piiSafeRenderer, $textPositionReader);

        $block = $builder->formatStructuredTextPositionBlock(2, $this->tableWords([
            ['(A) CREATININA', '0.8', 'mg/dL', '0.55 - 1.02'],
            ['(A) COLESTEROL TOTAL', '20.8', 'mg/dL', '< 200'],
            ['(A) CALCIO', '10.8', 'mg/dL', '8.70 - 10.00'],
            ['(A) BILIRRUBINA TOTAL', '0.80', 'mg/dL', '0.30 - 1.20'],
        ]));

        $this->assertNotNull($block);
        $this->assertStringContainsString('| (A) CREATININA | 0.8 | mg/dL | 0.55 - 1.02 |', $block);
        $this->assertStringContainsString('| (A) COLESTEROL TOTAL | 20.8 | mg/dL | < 200 |', $block);
        $this->assertStringContainsString('| (A) CALCIO | 10.8 | mg/dL | 8.70 - 10.00 |', $block);
        $this->assertStringContainsString('| (A) BILIRRUBINA TOTAL | 0.80 | mg/dL | 0.30 - 1.20 |', $block);
        $this->assertStringNotContainsString('1.020.8', $block);
    }

    #[Test]
    public function fusiona_lineas_tsv_que_contienen_solo_unidad_con_la_siguiente_fila(): void
    {
        $piiSafeRenderer = Mockery::mock(LaboratoryResultVisionPiiSafePageRenderer::class);
        $textPositionReader = Mockery::mock(LaboratoryResultVisionPdfTextPositionReader::class);
        $builder = new LaboratoryResultVisionHybridMessageBuilder($piiSafeRenderer, $textPositionReader);

        $block = $builder->formatStructuredTextPositionBlock(2, array_merge(
            $this->tableWords([]),
            $this->lineWords([['mg/dL', 360.0]], 40.0),
            $this->lineWords([
                ['(A) CALCIO', 10.0],
                ['9.2', 250.0],
                ['8.70 - 10.00', 470.0],
            ], 60.0),
        ));

        $this->assertNotNull($block);
        $this->assertStringContainsString('| (A) CALCIO | 9.2 | mg/dL | 8.70 - 10.00 |', $block);
        $this->assertStringNotContainsString('|  |  | mg/dL |  |', $block);
    }

    private function tableWords(array $rows): array
    {
        $words = [];
        $top = 20.0;

        $words = array_merge($words, $this->lineWords([
            ['ESTUDIO', 10.0],
            ['RESULTADO', 250.0],
            ['UNIDADES', 360.0],
            ['VALORES', 470.0],
            ['DE', 520.0],
            ['REFERENCIA', 545.0],
        ], $top));

        foreach ($rows as $row) {
            $top += 20.0;
            $words = array_merge($words, $this->lineWords([
                [$row[0], 10.0],
                [$row[1], 250.0],
                [$row[2], 360.0],
                [$row[3], 470.0],
            ], $top));
        }

        return $words;
    }

    private function lineWords(array $tokens, float $top): array
    {
        $words = [];

        foreach ($tokens as [$text, $left]) {
            $offset = 0.0;
            foreach (explode(' ', $text) as $part) {
                $words[] = [
                    'page' => 1,
                    'text' => $part,
                    'left' => $left + $offset,
                    'top' => $top,
                    'width' => 10.0,
                    'height' => 8.0,
                    'line_num' => (int) $top,
                ];
                $offset += 22.0;
            }
        }

        return $words;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
