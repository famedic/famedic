<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;

/**
 * Shared checkout-prep pattern used by appointments and Clinical Order bridge.
 * Builds/updates LaboratoryCheckoutDraft and returns the Customer checkout URL.
 * Never switches the admin session.
 */
class PrepareCustomerLaboratoryCheckoutLinkAction
{
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $brand,
        ?int $contactId = null,
        ?string $clinicalOrderUuid = null,
        ?string $checkoutStep = null,
    ): string {
        $customer->loadMissing('contacts');

        $existingDraft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();

        $resolvedContactId = $contactId
            ?? $existingDraft?->contact_id
            ?? $customer->contacts->first()?->id;

        if ($resolvedContactId) {
            $ownsContact = $customer->contacts->contains(
                fn (Contact $contact) => (int) $contact->id === (int) $resolvedContactId
            );
            if (! $ownsContact) {
                $resolvedContactId = $customer->contacts->first()?->id;
            }
        }

        $step = $checkoutStep
            ?? ($resolvedContactId ? 'confirmation' : 'patient');

        LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $brand,
            ],
            [
                'contact_id' => $resolvedContactId,
                'address_id' => $existingDraft?->address_id,
                'payment_method' => $existingDraft?->payment_method,
                'coupon_id' => $existingDraft?->coupon_id,
                'promo_validation_token' => $existingDraft?->promo_validation_token,
                'checkout_step' => $step,
                'clinical_order_uuid' => $clinicalOrderUuid ?? $existingDraft?->clinical_order_uuid,
            ],
        );

        $query = array_filter([
            'step' => $step,
            'contact' => $resolvedContactId,
            'address' => $existingDraft?->address_id,
            'payment_method' => $existingDraft?->payment_method,
            'coupon_id' => $existingDraft?->coupon_id,
        ], fn ($value) => $value !== null && $value !== '');

        return route('laboratory.checkout', [
            'laboratory_brand' => $brand,
            ...$query,
        ]);
    }
}
