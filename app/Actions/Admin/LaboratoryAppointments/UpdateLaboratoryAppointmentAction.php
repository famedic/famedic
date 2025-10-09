<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Enums\Gender;
use App\Models\LaboratoryAppointment;
use Carbon\Carbon;
use Propaganistas\LaravelPhone\PhoneNumber;

class UpdateLaboratoryAppointmentAction
{
    public function __invoke(
        string $appointment_date,
        string $appointment_time,
        string $patient_name,
        string $patient_paternal_lastname,
        string $patient_maternal_lastname,
        Carbon $patient_birth_date,
        Gender $patient_gender,
        string $patient_phone,
        string $patient_phone_country,
        int $laboratory_store,
        ?string $notes,
        LaboratoryAppointment $laboratoryAppointment
    ): LaboratoryAppointment {

        $laboratoryAppointment->update([
            'appointment_date' => Carbon::createFromFormat('Y-m-d H:i', $appointment_date . $appointment_time, 'America/Monterrey')->utc(),
            'confirmed_at' => now(),
            'patient_name' => $patient_name,
            'patient_paternal_lastname' => $patient_paternal_lastname,
            'patient_maternal_lastname' => $patient_maternal_lastname,
            'patient_birth_date' => $patient_birth_date->toDateString(),
            'patient_gender' => $patient_gender->value,
            'patient_phone' => str_replace(' ', '', (new PhoneNumber($patient_phone, $patient_phone_country))->formatNational()),
            'patient_phone_country' => $patient_phone_country,
            'laboratory_store_id' => $laboratory_store,
            'notes' => $notes,
        ]);


        return $laboratoryAppointment->refresh();
    }
}
