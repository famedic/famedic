<?php

namespace App\Actions\Laboratories;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Jobs\Carts\CheckAppointmentPendingJob;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Services\Carts\CartEventRecorder;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Propaganistas\LaravelPhone\PhoneNumber;

class SyncLaboratoryAppointmentFromContactAction
{
    public function __construct(
        private SyncMonitoringCartService $syncMonitoringCartService,
        private CartEventRecorder $cartEventRecorder,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        Contact $contact,
        ?array $clientContext = null,
    ): LaboratoryAppointment {
        $laboratoryAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand)
            ?? $customer->getPendingLaboratoryAppointment($laboratoryBrand);

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

        $this->syncMonitoringCartService->syncLaboratory($customer, $clientContext);
        $cart = $this->syncMonitoringCartService->activeLaboratoryCart($customer, $laboratoryBrand);

        if (! $cart && $customer->user_id && $customer->laboratoryCartItems()->ofBrand($laboratoryBrand)->exists()) {
            Log::warning('[CartTraceability] Laboratory appointment from contact could not resolve monitoring cart', [
                'customer_id' => $customer->id,
                'user_id' => $customer->user_id,
                'brand' => $laboratoryBrand->value,
                'laboratory_appointment_id' => $laboratoryAppointment->id,
            ]);
        }

        if ($cart && Schema::hasColumn('laboratory_appointments', 'cart_id') && ! $laboratoryAppointment->cart_id) {
            $laboratoryAppointment->forceFill(['cart_id' => $cart->id])->save();
        }

        if ($cart) {
            $this->cartEventRecorder->recordOnce(
                $cart,
                CartEventType::AppointmentRequested,
                "laboratory_appointment:{$laboratoryAppointment->id}:requested",
                $this->withClientContext([
                    'laboratory_appointment_id' => $laboratoryAppointment->id,
                    'appointment_id' => $laboratoryAppointment->id,
                    'brand' => $laboratoryBrand->value,
                ], $clientContext),
                $laboratoryAppointment->created_at,
                'laboratory_checkout',
            );

            if ($laboratoryAppointment->confirmed_at === null) {
                CheckAppointmentPendingJob::dispatch((int) $laboratoryAppointment->id)
                    ->afterCommit()
                    ->delay(now()->addMinutes(max(1, (int) config('carts.appointment_pending_after_minutes', 5))));
            }
        }

        $this->syncMonitoringCartService->touchLaboratoryCartActivity($customer);

        return $laboratoryAppointment->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $clientContext
     * @return array<string, mixed>
     */
    private function withClientContext(array $metadata, ?array $clientContext): array
    {
        if ($clientContext === null || $clientContext === []) {
            return $metadata;
        }

        return array_merge($metadata, ['client' => $clientContext]);
    }
}
