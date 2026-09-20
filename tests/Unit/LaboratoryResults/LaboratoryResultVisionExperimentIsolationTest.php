<?php

namespace Tests\Unit\LaboratoryResults;

use App\Services\LaboratoryResults\Extraction\LaboratoryResultInputHash;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionExperimentalExtractor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultVisionExperimentIsolationTest extends TestCase
{
    #[Test]
    public function experiment_input_hash_no_colisiona_con_produccion(): void
    {
        $sha = str_repeat('a', 64);

        $production = LaboratoryResultInputHash::compute(
            $sha,
            LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION,
            3,
        );

        $experiment = LaboratoryResultInputHash::computeExperiment(
            $sha,
            LaboratoryResultVisionExperimentalExtractor::EXPERIMENT_KEY,
            LaboratoryResultInputHash::VISION_EXPERIMENT_10B_EXTRACTOR_VERSION,
            3,
        );

        $this->assertNotSame($production, $experiment);
    }

    #[Test]
    public function experiment_feature_es_distinto_al_extractor_productivo(): void
    {
        $this->assertNotSame(
            LaboratoryResultVisionExtractor::FEATURE,
            LaboratoryResultVisionExperimentalExtractor::FEATURE,
        );
    }
}
