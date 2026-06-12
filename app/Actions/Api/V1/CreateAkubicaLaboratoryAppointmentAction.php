<?php

namespace App\Actions\Api\V1;

use App\Actions\Laboratories\SyncLaboratoryAppointmentFromContactAction;
use App\Enums\LaboratoryAppointmentInteractionType;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;
use App\Support\Api\V1\CheckoutPreparation;
use App\Support\Api\V1\LaboratoryAppointmentSupport;
use Carbon\Carbon;

class CreateAkubicaLaboratoryAppointmentAction
{
    public function __construct(
        private readonly CheckoutPreparation $checkoutPreparation,
        private readonly LaboratoryAppointmentSupport $appointmentSupport,
        private readonly SyncLaboratoryAppointmentFromContactAction $syncFromContactAction,
    ) {}

    /**
     * @return array{appointment: \App\Models\LaboratoryAppointment, can_continue_to_payment_link: bool}|array{error: string}
     */
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $brand,
        Contact $contact,
        Address $address,
        Carbon $scheduledAt,
        ?string $notes = null,
    ): array {
        $items = $this->checkoutPreparation->cartItems($customer, $brand);

        if ($items->isEmpty()) {
            return ['error' => 'EMPTY_CART'];
        }

        if (! $this->appointmentSupport->requiresAppointment($customer, $brand)) {
            return ['error' => 'APPOINTMENT_NOT_REQUIRED'];
        }

        if ($this->appointmentSupport->hasBlockingAppointment($customer, $brand)) {
            return ['error' => 'APPOINTMENT_ALREADY_EXISTS'];
        }

        $appointment = ($this->syncFromContactAction)($customer, $brand, $contact);

        $appointment->update([
            'callback_availability_starts_at' => $scheduledAt,
            'callback_availability_ends_at' => $scheduledAt->copy()->addHour(),
            'notes' => $notes,
        ]);

        $appointment->interactions()->create([
            'type' => LaboratoryAppointmentInteractionType::PatientCallbackPreference,
            'metadata' => [
                'callback_availability_starts_at' => $scheduledAt->toIso8601String(),
                'callback_availability_ends_at' => $scheduledAt->copy()->addHour()->toIso8601String(),
                'source' => 'akubica_api',
            ],
        ]);

        LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $brand,
            ],
            [
                'contact_id' => $contact->id,
                'address_id' => $address->id,
                'checkout_step' => 'confirmation',
            ],
        );

        return [
            'appointment' => $appointment->refresh(),
            'can_continue_to_payment_link' => isset(
                $this->checkoutPreparation->validatePaymentLinkReadiness($customer, $brand)['ok']
            ),
        ];
    }
}
