<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Enums\Gender;
use App\Models\LaboratoryAppointment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelPhone\PhoneNumber;

class UpdateLaboratoryAppointmentAction
{
    /**
     * Fecha/hora de cita en zona de negocio (Monterrey).
     *
     * No usar ->utc() al persistir: con APP_TIMEZONE distinto de UTC, MySQL guarda
     * datetime sin zona y Eloquent interpreta el valor en la zona de la app; si se
     * convierte a UTC antes de guardar, el mismo número de reloj se lee como hora
     * local y aparece un desfase (p. ej. +6 h en México).
     */
    private function resolveAppointmentAt(string $appointmentDate, string $appointmentTime): Carbon
    {
        $time = trim($appointmentTime);
        $date = trim($appointmentDate);

        if (str_contains($time, 'T') || str_contains($time, 'Z')) {
            return Carbon::parse($time)->timezone('America/Monterrey');
        }

        $datePart = Carbon::parse($date)->format('Y-m-d');

        return Carbon::createFromFormat('Y-m-d H:i', "{$datePart} {$time}", 'America/Monterrey');
    }

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

        $finalDateTime = $this->resolveAppointmentAt($appointment_date, $appointment_time);

        Log::info('Appointment Debug - Before Save', [
            'final_datetime' => $finalDateTime->toIso8601String(),
            'final_datetime_local' => $finalDateTime->format('Y-m-d H:i:s'),
        ]);

        $laboratoryAppointment->update([
            'appointment_date' => $finalDateTime,
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
