<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryResultExtractionComparisonOutcome;
use App\Enums\LaboratoryResultExtractionQaDocumentCategory;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonItem;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonReport;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonSummary;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionQaDocumentClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultExtractionQaDocumentClassifierTest extends TestCase
{
    private LaboratoryResultExtractionQaDocumentClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new LaboratoryResultExtractionQaDocumentClassifier;
    }

    #[Test]
    public function clasifica_conflict_high_overlap_y_no_comparable(): void
    {
        $conflict = new LaboratoryResultExtractionComparisonReport(
            new LaboratoryResultExtractionComparisonSummary([
                new LaboratoryResultExtractionComparisonItem(LaboratoryResultExtractionComparisonOutcome::Conflict, 'glucosa'),
            ], 0, 1, 0, 0, 0),
            1,
            1,
            true,
            true,
        );

        $highOverlap = new LaboratoryResultExtractionComparisonReport(
            new LaboratoryResultExtractionComparisonSummary([], 2, 0, 0, 0, 0),
            2,
            2,
            true,
            true,
        );

        $noComparable = new LaboratoryResultExtractionComparisonReport(
            new LaboratoryResultExtractionComparisonSummary([], 0, 0, 0, 0, 0),
            0,
            0,
            false,
            true,
        );

        $this->assertSame(LaboratoryResultExtractionQaDocumentCategory::Conflict, $this->classifier->classify($conflict));
        $this->assertSame(LaboratoryResultExtractionQaDocumentCategory::HighOverlap, $this->classifier->classify($highOverlap));
        $this->assertSame(LaboratoryResultExtractionQaDocumentCategory::NoComparable, $this->classifier->classify($noComparable));
    }
}
