<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;

class PrepareCustomerLaboratoryCheckoutLinkAction
{
    public function __construct(
        private GenerateLaboratoryCheckoutResumeLinkAction $resumeLinkGenerator,
    ) {}

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

        LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $brand,
            ],
            $draftAttributes,
        );

        return $this->resumeLinkGenerator->forCustomerBrand($customer, $brand);
    }
}
