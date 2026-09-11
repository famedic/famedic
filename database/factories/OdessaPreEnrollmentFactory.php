<?php

namespace Database\Factories;

use App\Models\OdessaPreEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

class OdessaPreEnrollmentFactory extends Factory
{
    protected $model = OdessaPreEnrollment::class;

    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'source_sheet' => 'Sin Registro',
            'source_row' => fake()->numberBetween(2, 500),
            'source_action' => OdessaPreEnrollment::ACTION_ALTA,
            'company_external_identifier' => (string) fake()->numberBetween(5000, 6000),
            'employee_identifier' => (string) fake()->unique()->numberBetween(1000, 9999),
            'odessa_identifier' => null,
            'first_name' => fake()->firstName(),
            'paternal_last_name' => fake()->lastName(),
            'maternal_last_name' => fake()->lastName(),
            'birth_date' => fake()->date(),
            'source_email' => fake()->unique()->safeEmail(),
            'membership_type' => 'institutional',
            'murguia_status' => OdessaPreEnrollment::MURGUIA_NOT_REQUESTED,
            'link_status' => OdessaPreEnrollment::LINK_PENDING_ACCOUNT,
            'status' => OdessaPreEnrollment::STATUS_READY,
            'source_snapshot_json' => ['source' => 'factory'],
        ];
    }
}
