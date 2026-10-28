<?php

namespace Database\Factories;

use App\Enums\LaboratoryAnalyteValueKind;
use App\Models\LaboratoryAnalyte;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryAnalyte>
 */
class LaboratoryAnalyteFactory extends Factory
{
    protected $model = LaboratoryAnalyte::class;

    public function definition(): array
    {
        $code = 'test_analyte_'.fake()->unique()->lexify('????');

        return [
            'code' => $code,
            'canonical_name' => 'Test Analyte '.strtoupper($code),
            'loinc_code' => null,
            'default_unit' => 'mg/dL',
            'value_kind' => LaboratoryAnalyteValueKind::Numeric,
            'category' => 'test',
            'is_active' => true,
        ];
    }
}
