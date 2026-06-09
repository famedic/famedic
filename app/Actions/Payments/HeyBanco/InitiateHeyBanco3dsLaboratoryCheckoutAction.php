<?php

namespace App\Actions\Payments\HeyBanco;

use App\Enums\LaboratoryBrand;
use App\Exceptions\HeyBanco3dsRedirectRequiredException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;

class InitiateHeyBanco3dsLaboratoryCheckoutAction
{
    public function __construct(
        private CreateHeyBanco3dsTokenChargeSessionAction $createSessionAction,
        private StartHeyBanco3dsTokenChargeAction $startSessionAction,
    ) {}

    /**
     * @return never
     */
    public function __invoke(
        Customer $customer,
        Address $address,
        ?Contact $contact,
        string $paymentMethodId,
        LaboratoryBrand $laboratoryBrand,
        int $amountCents,
        int $totalCents,
        ?int $couponId,
        int $discountCents,
        ?LaboratoryAppointment $laboratoryAppointment = null,
    ): void {
        $reference = 'FM-LAB-' . $customer->id . '-' . time() . '-' . rand(1000, 9999);

        $checkoutContext = [
            'type' => 'laboratory_checkout',
            'customer_id' => $customer->id,
            'address_id' => $address->id,
            'contact_id' => $contact?->id,
            'laboratory_brand' => $laboratoryBrand->value,
            'total_cents' => $totalCents,
            'amount_charged_cents' => $amountCents,
            'discount_cents' => $discountCents,
            'coupon_id' => $couponId,
            'laboratory_appointment_id' => $laboratoryAppointment?->id,
            'reference' => $reference,
        ];

        $session = ($this->createSessionAction)(
            customer: $customer,
            paymentMethodId: $paymentMethodId,
            amountCents: $amountCents,
            checkoutContext: $checkoutContext,
        );

        $result = ($this->startSessionAction)($session);

        throw new HeyBanco3dsRedirectRequiredException(
            session: $session->fresh(),
            redirectUrl: (string) $result->redirectUrl,
        );
    }
}
