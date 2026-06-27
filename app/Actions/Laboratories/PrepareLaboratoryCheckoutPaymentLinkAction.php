<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;

class PrepareLaboratoryCheckoutPaymentLinkAction
{
    public function __invoke(LaboratoryAppointment $appointment): string
    {
        $appointment->loadMissing(['customer.contacts', 'laboratoryStore']);

        $customer = $appointment->customer;
        $brand = $appointment->brand;

        $existingDraft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();

        $contactId = $this->resolveContactId($customer, $appointment, $existingDraft);

        $draft = LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $brand,
            ],
            [
                'contact_id' => $contactId,
                'address_id' => $existingDraft?->address_id,
                'payment_method' => $existingDraft?->payment_method,
                'coupon_id' => $existingDraft?->coupon_id,
                'checkout_step' => 'confirmation',
            ],
        );

        $query = array_filter([
            'step' => 'confirmation',
            'contact' => $contactId,
            'address' => $draft->address_id,
            'payment_method' => $draft->payment_method,
            'coupon_id' => $draft->coupon_id,
        ], fn ($value) => $value !== null && $value !== '');

        return route('laboratory.checkout', [
            'laboratory_brand' => $brand,
            ...$query,
        ]);
    }

    private function resolveContactId(
        Customer $customer,
        LaboratoryAppointment $appointment,
        ?LaboratoryCheckoutDraft $draft,
    ): ?int {
        $matched = $customer->contacts->first(
            fn (Contact $contact) => $this->contactMatchesAppointment($contact, $appointment),
        );

        if ($matched) {
            return $matched->id;
        }

        if ($draft?->contact_id) {
            $draftContact = $customer->contacts->firstWhere('id', $draft->contact_id);
            if ($draftContact) {
                return $draftContact->id;
            }
        }

        return $customer->contacts->first()?->id;
    }

    private function contactMatchesAppointment(Contact $contact, LaboratoryAppointment $appointment): bool
    {
        return mb_strtolower(trim((string) $contact->name)) === mb_strtolower(trim((string) $appointment->patient_name))
            && mb_strtolower(trim((string) $contact->paternal_lastname)) === mb_strtolower(trim((string) $appointment->patient_paternal_lastname))
            && mb_strtolower(trim((string) $contact->maternal_lastname)) === mb_strtolower(trim((string) $appointment->patient_maternal_lastname));
    }

    /**
     * @return array<string, mixed>
     */
    public function checkoutSummaryForMail(LaboratoryAppointment $appointment): array
    {
        $appointment->loadMissing(['customer.contacts', 'laboratoryStore']);

        $customer = $appointment->customer;
        $brand = $appointment->brand;

        $draft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->with(['contact', 'address'])
            ->first();

        $contact = $draft?->contact;
        if ($contact && ! $this->contactMatchesAppointment($contact, $appointment)) {
            $contact = $customer->contacts->first(
                fn (Contact $c) => $this->contactMatchesAppointment($c, $appointment),
            ) ?? $contact;
        }

        if (! $contact) {
            $contact = $customer->contacts->first(
                fn (Contact $c) => $this->contactMatchesAppointment($c, $appointment),
            );
        }

        $address = $draft?->address;
        $addressText = $address
            ? trim((string) ($address->formatted_address ?: $address->full_address))
            : null;

        $dt = localizedDate($appointment->appointment_date);

        return [
            'patient_name' => $appointment->patient_full_name ?? '—',
            'patient_phone' => $appointment->patient_full_phone ?? '—',
            'patient_birth_date' => $appointment->formatted_patient_birth_date ?? '—',
            'patient_gender' => $appointment->formatted_patient_gender ?? '—',
            'contact_name' => $contact?->full_name ?? $appointment->patient_full_name ?? '—',
            'address' => $addressText ?: '—',
            'payment_method' => $this->paymentMethodLabel($draft?->payment_method, $customer),
            'appointment_date' => $dt?->isoFormat('dddd D [de] MMMM [de] YYYY') ?? '—',
            'appointment_time' => $dt?->isoFormat('h:mm a') ?? '—',
            'branch_name' => $appointment->laboratoryStore?->name ?? '—',
            'branch_address' => filled($appointment->laboratoryStore?->address)
                ? $appointment->laboratoryStore->address
                : '—',
            'notes' => filled($appointment->notes) ? $appointment->notes : null,
        ];
    }

    private function paymentMethodLabel(?string $paymentMethod, Customer $customer): string
    {
        if ($paymentMethod === null || $paymentMethod === '') {
            return 'Sin seleccionar';
        }

        return match ($paymentMethod) {
            'odessa' => 'Saldo a la Vista (Odessa)',
            'paypal' => 'PayPal',
            'coupon_balance' => 'Crédito a favor (cupón)',
            default => $this->efevooTokenPaymentLabel($paymentMethod, $customer),
        };
    }

    private function efevooTokenPaymentLabel(string $paymentMethod, Customer $customer): string
    {
        if (! ctype_digit($paymentMethod)) {
            return $paymentMethod;
        }

        $token = EfevooToken::query()
            ->where('customer_id', $customer->id)
            ->where('id', (int) $paymentMethod)
            ->first();

        if (! $token) {
            return 'Tarjeta #'.$paymentMethod;
        }

        return sprintf(
            '%s •••• %s',
            ucfirst(strtolower((string) $token->card_brand)),
            $token->card_last_four,
        );
    }
}
