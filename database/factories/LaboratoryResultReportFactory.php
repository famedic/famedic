<?php

namespace Database\Factories;

use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultReportSource;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryResultReport>
 */
class LaboratoryResultReportFactory extends Factory
{
    protected $model = LaboratoryResultReport::class;

    public function definition(): array
    {
        return [
            'laboratory_purchase_id' => 0,
            'laboratory_result_version_id' => null,
            'source' => LaboratoryResultReportSource::Gda,
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
            'extraction_status' => LaboratoryResultExtractionStatus::Pending,
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'observation_count' => 0,
            'input_hash' => hash('sha256', fake()->uuid()),
            'extractor_version' => null,
            'prompt_version' => null,
            'published_version_slot' => null,
        ];
    }

    public function forVersion(LaboratoryResultVersion $version): static
    {
        return $this->state(function () use ($version) {
            $version->loadMissing('resultStatus');

            return [
                'laboratory_purchase_id' => $version->resultStatus->laboratory_purchase_id,
                'laboratory_result_version_id' => $version->id,
            ];
        });
    }

    public function published(LaboratoryResultVersion $version): static
    {
        return $this->forVersion($version)->state(fn () => [
            'structured_status' => LaboratoryResultStructuredStatus::Published,
            'extraction_status' => LaboratoryResultExtractionStatus::Extracted,
            'published_at' => now(),
            'published_version_slot' => $version->id,
        ]);
    }
}
