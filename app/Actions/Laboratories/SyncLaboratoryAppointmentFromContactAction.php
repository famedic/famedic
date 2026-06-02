<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Services\Monitoring\SyncMonitoringCartService;
use Propaganistas\LaravelPhone\PhoneNumber;

class SyncLaboratoryAppointmentFromContactAction
{
    public function __construct(
        private SyncMonitoringCartService $syncMonitoringCartService,
    ) {
    }

    public function __invoke(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        Contact $contact,
    ): LaboratoryAppointment {
        $laboratoryAppointment = $customer->getPendingLaboratoryAppointment($laboratoryBrand);

        if (! $laboratoryAppointment) {
            $laboratoryAppointment = $customer->laboratoryAppointments()->create([
                'brand' => $laboratoryBrand,
            ]);
        }

        $phoneCountry = $contact->phone_country ?? 'MX';
        $formattedPhone = $contact->phone
            ? str_replace(' ', '', (new PhoneNumber($contact->phone, $phoneCountry))->formatNational())
            : null;

        $laboratoryAppointment->update([
            'patient_name' => $contact->name,
            'patient_paternal_lastname' => $contact->paternal_lastname,
            'patient_maternal_lastname' => $contact->maternal_lastname,
            'patient_birth_date' => $contact->birth_date,
            'patient_gender' => $contact->gender,
            'patient_phone' => $formattedPhone,
            'patient_phone_country' => $phoneCountry,
        ]);

        $this->syncMonitoringCartService->touchLaboratoryCartActivity($customer);

        return $laboratoryAppointment->refresh();
    }
}
