<?php

namespace App\Actions\Api\V1;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;
use App\Support\Api\V1\CheckoutPreparation;

class SyncAkubicaCheckoutDraftAction
{
    public function __construct(
        private readonly CheckoutPreparation $checkoutPreparation,
    ) {}

    /**
     * @param  array{
     *     contact_id?: int|null,
     *     address_id?: int|null,
     *     requires_invoice?: bool|null,
     *     tax_profile_id?: int|null,
     *     notes?: string|null,
     * }  $payload
     */
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $brand,
        array $payload,
    ): array {
        $items = $this->checkoutPreparation->cartItems($customer, $brand);

        if ($items->isEmpty()) {
            return ['error' => 'EMPTY_CART'];
        }

        if (array_key_exists('contact_id', $payload) && $payload['contact_id'] !== null) {
            if (! $this->checkoutPreparation->findOwnedContact($customer, (int) $payload['contact_id'])) {
                return ['error' => 'CONTACT_NOT_FOUND'];
            }
        }

        if (array_key_exists('address_id', $payload) && $payload['address_id'] !== null) {
            if (! $this->checkoutPreparation->findOwnedAddress($customer, (int) $payload['address_id'])) {
                return ['error' => 'ADDRESS_NOT_FOUND'];
            }
        }

        if (array_key_exists('tax_profile_id', $payload) && $payload['tax_profile_id'] !== null) {
            if (! $this->checkoutPreparation->findOwnedTaxProfile($customer, (int) $payload['tax_profile_id'])) {
                return ['error' => 'TAX_PROFILE_NOT_FOUND'];
            }
        }

        $existingDraft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();

        $contactId = array_key_exists('contact_id', $payload)
            ? $payload['contact_id']
            : $existingDraft?->contact_id;

        $addressId = array_key_exists('address_id', $payload)
            ? $payload['address_id']
            : $existingDraft?->address_id;

        $requiresAppointment = $customer->getHasLaboratoryCartItemRequiringAppointment($brand);

        $checkoutStep = match (true) {
            $requiresAppointment && $contactId && $addressId => 'appointment',
            $contactId && $addressId => 'confirmation',
            $contactId !== null => 'address',
            default => 'patient',
        };

        $attributes = [
            'checkout_step' => $checkoutStep,
        ];

        if (array_key_exists('contact_id', $payload)) {
            $attributes['contact_id'] = $payload['contact_id'];
        }

        if (array_key_exists('address_id', $payload)) {
            $attributes['address_id'] = $payload['address_id'];
        }

        $draft = LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $brand,
            ],
            $attributes,
        );

        $draft->refresh();

        $requiresInvoice = $payload['requires_invoice'] ?? false;
        $taxProfileId = $payload['tax_profile_id'] ?? null;
        $notes = $payload['notes'] ?? null;

        return [
            'draft' => [
                'brand' => $brand->value,
                'contact_id' => $draft->contact_id,
                'address_id' => $draft->address_id,
                'requires_invoice' => (bool) $requiresInvoice,
                'tax_profile_id' => $taxProfileId,
                'notes' => $notes,
                'checkout_step' => $draft->checkout_step,
                'is_ready_for_payment_link' => $this->checkoutPreparation->isReadyForPaymentLink(
                    $customer,
                    $brand,
                    $draft,
                    $items,
                ),
            ],
            'requirements' => $this->checkoutPreparation->buildDraftRequirements(
                $customer,
                $brand,
                $items,
                $draft,
            ),
            'warnings' => $this->checkoutPreparation->buildDraftWarnings(
                $customer,
                $brand,
                $items,
                $draft,
            ),
        ];
    }
}
