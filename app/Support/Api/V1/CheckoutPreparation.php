<?php

namespace App\Support\Api\V1;

use App\Enums\LaboratoryBrand;
use App\Http\Resources\Api\V1\AddressResource;
use App\Http\Resources\Api\V1\CartItemResource;
use App\Http\Resources\Api\V1\ContactResource;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\TaxProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class CheckoutPreparation
{
    public function cartItems(Customer $customer, LaboratoryBrand $brand): Collection
    {
        return $customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest')
            ->get();
    }

    /**
     * @return array{
     *     brand: string,
     *     currency: string,
     *     items_count: int,
     *     subtotal_cents: int,
     *     discount_cents: int,
     *     total_cents: int,
     *     coupon: null,
     * }
     */
    public function cartTotals(Customer $customer, LaboratoryBrand $brand, CartCouponSupport $cartCouponSupport): array
    {
        $items = $this->cartItems($customer, $brand);
        $draft = $cartCouponSupport->draftForBrand($customer, $brand);

        return $cartCouponSupport->buildTotalsWithCoupon($brand, $items, $draft?->coupon);
    }

    /**
     * @return array{
     *     currency: string,
     *     subtotal_cents: int,
     *     discount_cents: int,
     *     total_cents: int,
     * }
     */
    public function totalsFromItems(Collection $items): array
    {
        $subtotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->public_price_cents);
        $totalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents);

        return [
            'currency' => 'MXN',
            'subtotal_cents' => $subtotalCents,
            'discount_cents' => $subtotalCents - $totalCents,
            'total_cents' => $totalCents,
        ];
    }

    /**
     * @return array{
     *     has_items: bool,
     *     requires_contact: bool,
     *     requires_address: bool,
     *     requires_appointment: bool,
     *     requires_invoice_data: bool,
     * }
     */
    public function buildRequirements(Customer $customer, LaboratoryBrand $brand, Collection $items): array
    {
        return [
            'has_items' => $items->isNotEmpty(),
            'requires_contact' => true,
            'requires_address' => true,
            'requires_appointment' => $customer->getHasLaboratoryCartItemRequiringAppointment($brand),
            'requires_invoice_data' => false,
        ];
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public function buildWarnings(Customer $customer, LaboratoryBrand $brand, Collection $items): array
    {
        $warnings = [];

        if ($items->isEmpty()) {
            $warnings[] = [
                'code' => 'EMPTY_CART',
                'message' => 'El carrito está vacío.',
            ];
        }

        if ($items->isNotEmpty() && $customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            $hasAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($brand)
                || $customer->getPendingLaboratoryAppointment($brand);

            if (! $hasAppointment) {
                $warnings[] = [
                    'code' => 'REQUIRES_APPOINTMENT',
                    'message' => 'Uno o más estudios requieren agendar cita.',
                ];
            }
        }

        return $warnings;
    }

    public function canContinueToPaymentPlatform(Collection $items): bool
    {
        return $items->isNotEmpty();
    }

    /**
     * @return array{
     *     has_items: bool,
     *     has_contact: bool,
     *     has_required_address: bool,
     *     requires_appointment: bool,
     * }
     */
    public function buildDraftRequirements(
        Customer $customer,
        LaboratoryBrand $brand,
        Collection $items,
        LaboratoryCheckoutDraft $draft,
    ): array {
        return [
            'has_items' => $items->isNotEmpty(),
            'has_contact' => $draft->contact_id !== null,
            'has_required_address' => $draft->address_id !== null,
            'requires_appointment' => $customer->getHasLaboratoryCartItemRequiringAppointment($brand),
        ];
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public function buildDraftWarnings(
        Customer $customer,
        LaboratoryBrand $brand,
        Collection $items,
        LaboratoryCheckoutDraft $draft,
    ): array {
        $warnings = $this->buildWarnings($customer, $brand, $items);

        if ($items->isNotEmpty() && ! $draft->contact_id) {
            $warnings[] = [
                'code' => 'MISSING_CONTACT',
                'message' => 'Selecciona un paciente para continuar.',
            ];
        }

        if ($items->isNotEmpty() && ! $draft->address_id) {
            $warnings[] = [
                'code' => 'MISSING_ADDRESS',
                'message' => 'Selecciona una dirección para continuar.',
            ];
        }

        if ($items->isNotEmpty()
            && $draft->contact_id
            && $draft->address_id
            && $customer->getHasLaboratoryCartItemRequiringAppointment($brand)
        ) {
            $hasAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($brand)
                || $customer->getPendingLaboratoryAppointment($brand);

            if (! $hasAppointment) {
                $warnings[] = [
                    'code' => 'REQUIRES_APPOINTMENT',
                    'message' => 'Uno o más estudios requieren agendar cita.',
                ];
            }
        }

        return $warnings;
    }

    public function isReadyForPaymentLink(
        Customer $customer,
        LaboratoryBrand $brand,
        LaboratoryCheckoutDraft $draft,
        Collection $items,
    ): bool {
        if ($items->isEmpty() || ! $draft->contact_id || ! $draft->address_id) {
            return false;
        }

        if ($customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            return $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($brand) !== null
                || $customer->getPendingLaboratoryAppointment($brand) !== null;
        }

        return true;
    }

    /**
     * @return array{ok: true, items: Collection, draft: LaboratoryCheckoutDraft}|array{error: string, missing?: list<string>}
     */
    public function validatePaymentLinkReadiness(Customer $customer, LaboratoryBrand $brand): array
    {
        $items = $this->cartItems($customer, $brand);

        if ($items->isEmpty()) {
            return ['error' => 'EMPTY_CART'];
        }

        $draft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();

        $missing = [];

        if (! $draft?->contact_id) {
            $missing[] = 'contact_id';
        }

        if (! $draft?->address_id) {
            $missing[] = 'address_id';
        }

        if ($missing !== []) {
            return ['error' => 'CHECKOUT_NOT_READY', 'missing' => $missing];
        }

        if ($customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            $hasAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($brand)
                || $customer->getPendingLaboratoryAppointment($brand);

            if (! $hasAppointment) {
                return ['error' => 'APPOINTMENT_REQUIRED'];
            }
        }

        return [
            'ok' => true,
            'items' => $items,
            'draft' => $draft,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkoutRedirectQuery(LaboratoryCheckoutDraft $draft): array
    {
        return array_filter([
            'step' => $draft->checkout_step ?: 'confirmation',
            'contact' => $draft->contact_id,
            'address' => $draft->address_id,
            'coupon_id' => $draft->coupon_id,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function findOwnedContact(Customer $customer, int $contactId): ?Contact
    {
        return Contact::query()
            ->where('id', $contactId)
            ->where('customer_id', $customer->id)
            ->first();
    }

    public function findOwnedAddress(Customer $customer, int $addressId): ?Address
    {
        return Address::query()
            ->where('id', $addressId)
            ->where('customer_id', $customer->id)
            ->first();
    }

    public function findOwnedTaxProfile(Customer $customer, int $taxProfileId): ?TaxProfile
    {
        return TaxProfile::query()
            ->where('id', $taxProfileId)
            ->where('customer_id', $customer->id)
            ->first();
    }

    public function paymentMethods(Customer $customer): array
    {
        $tokens = EfevooToken::query()
            ->where('customer_id', $customer->id)
            ->active()
            ->excludeMockInProduction()
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (EfevooToken $token) => $token->card_last_four.'-'.($token->card_expiration ?? ''))
            ->values();

        return PaymentMethodResource::collection($tokens)->resolve(new Request);
    }

    public function prepare(
        Customer $customer,
        LaboratoryBrand $brand,
        Request $request,
        CartCouponSupport $cartCouponSupport,
    ): array {
        $items = $this->cartItems($customer, $brand);
        $requirements = $this->buildRequirements($customer, $brand, $items);
        $warnings = $this->buildWarnings($customer, $brand, $items);
        $draft = $cartCouponSupport->draftForBrand($customer, $brand);
        $famedicTotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents);
        $couponWarnings = $cartCouponSupport->couponWarnings(
            $request->user(),
            $draft?->coupon,
            $famedicTotalCents,
        );

        return [
            'brand' => $brand->value,
            'cart' => [
                'items' => CartItemResource::collection($items)->resolve($request),
                'totals' => $cartCouponSupport->totalsFromItems($items, $draft?->coupon),
            ],
            'coupon' => $cartCouponSupport->formatCouponPayload($draft?->coupon, $famedicTotalCents),
            'contacts' => ContactResource::collection(
                $customer->contacts()->orderBy('id')->get(),
            )->resolve($request),
            'addresses' => AddressResource::collection(
                $customer->addresses()->orderBy('id')->get(),
            )->resolve($request),
            'payment_methods' => $this->paymentMethods($customer),
            'requirements' => $requirements,
            'warnings' => array_merge($warnings, $couponWarnings),
            'can_continue_to_payment_platform' => $this->canContinueToPaymentPlatform($items),
        ];
    }
}
