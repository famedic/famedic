<?php

namespace Database\Factories;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class LaboratoryAppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand' => fake()->randomElement(LaboratoryBrand::cases())->value,
            'customer_id' => Customer::factory(),
        ];
    }

    public function withNotes(?string $notes = null): Factory
    {
        return $this->state(function (array $attributes) use ($notes) {
            return [
                'notes' => $notes ?? fake()->paragraph(),
            ];
        });
    }

    public function confirmed(?Carbon $appointmentDate = null, ?Carbon $confirmedAt = null, ?string $patientName = null): Factory
    {
        return $this->state(function (array $attributes) use ($appointmentDate, $confirmedAt, $patientName) {
            return [
                'patient_name' => $patientName ?? fake()->firstName(),
                'patient_paternal_lastname' => fake()->lastName(),
                'patient_maternal_lastname' => fake()->lastName(),
                'patient_phone' => fake()->phoneNumber(),
                'patient_birth_date' => fake()->date(),
                'patient_gender' => fake()->randomElement(array_keys(config('mexicanstates'))),
                'confirmed_at' => $confirmedAt ?? fake()->dateTimeBetween('-1 month', 'now'),
                'appointment_date' => $appointmentDate ?? fake()->dateTimeBetween('now', '+1 month'),
            ];
        });
    }
}
