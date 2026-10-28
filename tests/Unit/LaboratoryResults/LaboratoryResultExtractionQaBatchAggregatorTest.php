<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryResultExtractionQaDocumentCategory;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionQaBatchAggregator;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionQaBatchDocumentResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultExtractionQaBatchAggregatorTest extends TestCase
{
    private LaboratoryResultExtractionQaBatchAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregator = new LaboratoryResultExtractionQaBatchAggregator;
    }

    #[Test]
    public function agrega_metricas_correctamente(): void
    {
        $documents = [
            new LaboratoryResultExtractionQaBatchDocumentResult(
                versionId: 1,
                success: true,
                source: 'gda',
                textExtractionStatus: 'extracted',
                textObservationCount: 4,
                visionObservationCount: 4,
                matchCount: 3,
                conflictCount: 1,
                textOnlyCount: 0,
                visionOnlyCount: 0,
                unresolvedCount: 0,
                matchRate: 0.75,
                visionCoverage: 1.0,
                visionExecuted: true,
                documentCategory: LaboratoryResultExtractionQaDocumentCategory::Conflict,
            ),
            new LaboratoryResultExtractionQaBatchDocumentResult(
                versionId: 2,
                success: true,
                source: 'gda',
                textExtractionStatus: 'extracted',
                textObservationCount: 2,
                visionObservationCount: 3,
                matchCount: 2,
                conflictCount: 0,
                textOnlyCount: 0,
                visionOnlyCount: 1,
                unresolvedCount: 0,
                matchRate: 1.0,
                visionCoverage: 1.5,
                visionExecuted: true,
                documentCategory: LaboratoryResultExtractionQaDocumentCategory::PartialOverlap,
                visionOnlyAnalyteKeys: ['hemoglobina'],
            ),
        ];

        $result = $this->aggregator->aggregate($documents);

        $this->assertSame(2, $result->totalVersions);
        $this->assertSame(2, $result->versionsWithText);
        $this->assertSame(2, $result->versionsWithVision);
        $this->assertSame(6, $result->totalComparablePairs);
        $this->assertSame(5, $result->totalMatch);
        $this->assertSame(1, $result->totalConflict);
        $this->assertSame(1, $result->totalVisionOnly);
        $this->assertSame(0.8333, $result->matchRate);
        $this->assertSame(0.1667, $result->conflictRate);
        $this->assertSame(1.1667, $result->visionCoverage);
        $this->assertSame(1, $result->categoryCounts[LaboratoryResultExtractionQaDocumentCategory::Conflict->value]);
    }

    #[Test]
    public function maneja_denominador_cero(): void
    {
        $result = $this->aggregator->aggregate([
            LaboratoryResultExtractionQaBatchDocumentResult::failed(1, 'pdf missing'),
        ]);

        $this->assertNull($result->matchRate);
        $this->assertNull($result->conflictRate);
        $this->assertNull($result->visionCoverage);
        $this->assertNull($result->visionOnlyRate);
    }
}
