<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryObservationSourcePriority;
use App\Enums\LaboratoryResultExtractionComparisonOutcome;
use App\Enums\LaboratoryResultObservationValueType;
use App\Services\LaboratoryResults\Extraction\LaboratoryObservationSourcePriorityResolver;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonItem;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultObservationCandidate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryObservationSourcePriorityResolverTest extends TestCase
{
    private LaboratoryObservationSourcePriorityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new LaboratoryObservationSourcePriorityResolver;
    }

    #[Test]
    public function match_es_vision_confirmation(): void
    {
        $item = new LaboratoryResultExtractionComparisonItem(
            outcome: LaboratoryResultExtractionComparisonOutcome::Match,
            analyteKey: 'rdw',
        );

        $this->assertSame(LaboratoryObservationSourcePriority::VisionConfirmation, $this->resolver->resolve($item));
        $this->assertFalse($this->resolver->requiresReview($item));
    }

    #[Test]
    public function conflict_requiere_revision(): void
    {
        $item = new LaboratoryResultExtractionComparisonItem(
            outcome: LaboratoryResultExtractionComparisonOutcome::Conflict,
            analyteKey: 'hgm',
            textCandidate: new LaboratoryResultObservationCandidate(
                analyteNameRaw: 'HGM',
                valueType: LaboratoryResultObservationValueType::Numeric,
                numericValue: 29.0,
                unit: 'pg',
            ),
            visionCandidate: new LaboratoryResultObservationCandidate(
                analyteNameRaw: 'HGM',
                valueType: LaboratoryResultObservationValueType::Numeric,
                numericValue: 29.0,
                unit: 'g/dL',
            ),
            conflictFields: ['unit'],
        );

        $this->assertSame(LaboratoryObservationSourcePriority::ConflictRequiresReview, $this->resolver->resolve($item));
        $this->assertTrue($this->resolver->requiresReview($item));
    }

    #[Test]
    public function text_only_es_text_primary(): void
    {
        $item = new LaboratoryResultExtractionComparisonItem(
            outcome: LaboratoryResultExtractionComparisonOutcome::TextOnly,
            analyteKey: 'vpm',
        );

        $this->assertSame(LaboratoryObservationSourcePriority::TextPrimary, $this->resolver->resolve($item));
    }

    #[Test]
    public function vision_only_requiere_revision(): void
    {
        $item = new LaboratoryResultExtractionComparisonItem(
            outcome: LaboratoryResultExtractionComparisonOutcome::VisionOnly,
            analyteKey: 'glucosa',
        );

        $this->assertSame(LaboratoryObservationSourcePriority::VisionOnly, $this->resolver->resolve($item));
        $this->assertTrue($this->resolver->requiresReview($item));
    }
}
