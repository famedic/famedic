<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;

class PrepareCustomerLaboratoryCheckoutLinkAction
{
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $brand,
        ?int $contactId,
        string $checkoutStep,
        ?int $addressId = null,
    ): string {
        $draftAttributes = [
            'checkout_step' => $checkoutStep,
        ];

        if ($contactId !== null) {
            $draftAttributes['contact_id'] = $contactId;
        }

        if ($addressId !== null) {
            $draftAttributes['address_id'] = $addressId;
        }

        $draft = LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $brand,
            ],
            $draftAttributes,
        );

        $query = array_filter([
            'step' => $checkoutStep,
            'contact' => $draft->contact_id ?? $contactId,
            'address' => $draft->address_id ?? $addressId,
        ], fn ($value) => $value !== null && $value !== '');

        return route('laboratory.checkout', [
            'laboratory_brand' => $brand,
            ...$query,
        ]);
    }
}
