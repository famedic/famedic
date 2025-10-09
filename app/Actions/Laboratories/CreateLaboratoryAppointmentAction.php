<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;

class CreateLaboratoryAppointmentAction
{
    public function __invoke(Customer $customer, LaboratoryBrand $laboratoryBrand)
    {
        $laboratoryAppointment = $customer->getPendingLaboratoryAppointment($laboratoryBrand);

        return $laboratoryAppointment ?? $customer->laboratoryAppointments()->create([
            'brand' => $laboratoryBrand,
        ]);
    }
}
