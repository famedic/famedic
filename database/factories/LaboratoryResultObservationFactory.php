<?php

namespace Database\Factories;

use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryResultObservation>
 */
class LaboratoryResultObservationFactory extends Factory
{
    protected $model = LaboratoryResultObservation::class;

    public function definition(): array
    {
        return [
            'laboratory_result_report_id' => LaboratoryResultReport::factory(),
            'laboratory_analyte_id' => null,
            'analyte_code' => null,
            'analyte_name_raw' => 'Glucosa',
            'analyte_name_display' => null,
            'numeric_value' => fake()->randomFloat(2, 70, 140),
            'text_value' => null,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => 'mg/dL',
            'unit_raw' => null,
            'reference_low' => 70,
            'reference_high' => 100,
            'reference_text' => '70-100',
            'reference_status' => LaboratoryResultReferenceStatus::Unknown,
            'abnormal_flag' => null,
            'abnormal_source' => null,
            'laboratory_purchase_item_id' => null,
            'panel_name_raw' => null,
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
            'metadata' => null,
            'source_bbox' => null,
        ];
    }
}
