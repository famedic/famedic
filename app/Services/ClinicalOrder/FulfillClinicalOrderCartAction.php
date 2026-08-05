<?php

namespace App\Services\ClinicalOrder;

use App\Actions\Laboratories\AddItemToCartAction;
use App\Actions\Laboratories\PrepareCustomerLaboratoryCheckoutLinkAction;
use App\Enums\ClinicalOrderStatus;
use App\Enums\LaboratoryBrand;
use App\Models\ClinicalOrder;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryTest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bridge: Clinical Order → real laboratory cart + Customer checkout link.
 * Does not modify CommercialIntegrationEngine. Admin session stays admin.
 */
class FulfillClinicalOrderCartAction
{
    public function __construct(
        private AddItemToCartAction $addItemToCart,
        private PrepareCustomerLaboratoryCheckoutLinkAction $prepareCheckoutLink,
        private ClinicalOrderService $orders,
    ) {}

    /**
     * @return array{
     *   clinical_order: ClinicalOrder,
     *   checkout_url: string,
     *   brand: string,
     *   brand_label: string,
     *   customer_id: int,
     *   contact_id: int|null,
     *   laboratory_test_ids: list<int>,
     *   cart_item_ids: list<int>
     * }
     */
    public function __invoke(
        ClinicalOrder $order,
        Customer $customer,
        ?int $contactId = null,
    ): array {
        $customer->loadMissing(['contacts', 'user']);

        $testIds = $this->confirmedTestIds($order);
        if ($testIds === []) {
            throw new \InvalidArgumentException(
                'No hay estudios confirmados para agregar al carrito.'
            );
        }

        $tests = LaboratoryTest::query()
            ->whereIn('id', $testIds)
            ->get();

        if ($tests->count() !== count($testIds)) {
            throw new \InvalidArgumentException(
                'Uno o más estudios confirmados ya no existen en el catálogo.'
            );
        }

        $brands = $tests
            ->map(fn (LaboratoryTest $test) => $test->brand instanceof LaboratoryBrand
                ? $test->brand
                : LaboratoryBrand::tryFrom((string) $test->brand))
            ->filter()
            ->unique(fn (LaboratoryBrand $brand) => $brand->value)
            ->values();

        if ($brands->count() !== 1) {
            throw new \InvalidArgumentException(
                'La Laboratory Order debe contener estudios de una sola marca de laboratorio.'
            );
        }

        /** @var LaboratoryBrand $brand */
        $brand = $brands->first();

        $resolvedContactId = $this->resolveContactId($customer, $order, $contactId);

        return DB::transaction(function () use ($order, $customer, $brand, $tests, $resolvedContactId) {
            $order = $this->orders->appendTimelineEvent($order, [
                'id' => 'lab_selected',
                'label' => 'Laboratorio seleccionado',
                'meta' => [
                    'brand' => $brand->value,
                    'brand_label' => $brand->label(),
                ],
            ]);

            $cartItemIds = [];
            $laboratoryTestIds = [];

            foreach ($tests as $test) {
                $item = ($this->addItemToCart)($customer, (int) $test->id);
                $cartItemIds[] = (int) $item->id;
                $laboratoryTestIds[] = (int) $test->id;
            }

            $order = $this->orders->appendTimelineEvent($order, [
                'id' => 'cart_created',
                'label' => 'Carrito creado',
                'meta' => [
                    'cart_item_ids' => $cartItemIds,
                    'laboratory_test_ids' => $laboratoryTestIds,
                    'customer_id' => $customer->id,
                ],
            ]);

            // Checkout Famedic owns patient / address / payment / appointment.
            // Always hand off at the first checkout step — never skip ahead.
            $checkoutUrl = ($this->prepareCheckoutLink)(
                customer: $customer,
                brand: $brand,
                contactId: $resolvedContactId,
                clinicalOrderUuid: $order->uuid,
                checkoutStep: 'patient',
            );

            $order = $this->orders->appendTimelineEvent($order, [
                'id' => 'checkout_prepared',
                'label' => 'Checkout Famedic listo',
                'meta' => [
                    'checkout_url' => $checkoutUrl,
                    'contact_id' => $resolvedContactId,
                    'entry_step' => 'patient',
                ],
            ]);

            $integrations = is_array($order->integrations) ? $order->integrations : [];
            $integrations['checkout'] = [
                'customer_id' => $customer->id,
                'customer_name' => $customer->user?->full_name
                    ?? $customer->user?->name
                    ?? null,
                'customer_email' => $customer->user?->email,
                'contact_id' => $resolvedContactId,
                'brand' => $brand->value,
                'brand_label' => $brand->label(),
                'laboratory_test_ids' => $laboratoryTestIds,
                'cart_item_ids' => $cartItemIds,
                'checkout_url' => $checkoutUrl,
                'purchase_id' => null,
                'prepared_at' => now()->toIso8601String(),
                'paid_at' => null,
            ];
            $integrations['orders'] = [
                'ready' => true,
                'linked' => true,
            ];
            $order->integrations = $integrations;
            $order->save();

            try {
                $status = $order->status instanceof ClinicalOrderStatus
                    ? $order->status
                    : ClinicalOrderStatus::tryFrom((string) $order->status);

                if (
                    $status === ClinicalOrderStatus::Validated
                    || $status === ClinicalOrderStatus::QuotePrepared
                ) {
                    $order = $this->orders->markCartPrepared($order);
                    $order = $this->orders->markCheckoutStarted($order);
                } elseif ($status === ClinicalOrderStatus::CartPrepared) {
                    $order = $this->orders->markCheckoutStarted($order);
                } elseif ($status === ClinicalOrderStatus::CheckoutStarted) {
                    // Cart/link refresh while awaiting customer payment.
                } else {
                    throw new \InvalidArgumentException(
                        'Esta orden no puede enviarse a checkout desde su estado actual.'
                    );
                }
            } catch (\InvalidArgumentException $e) {
                throw $e;
            }

            Log::info('clinical_interpreter.cart_fulfilled', [
                'order_uuid' => $order->uuid,
                'customer_id' => $customer->id,
                'brand' => $brand->value,
                'test_count' => count($laboratoryTestIds),
            ]);

            return [
                'clinical_order' => $order->fresh('user') ?? $order,
                'checkout_url' => $checkoutUrl,
                'brand' => $brand->value,
                'brand_label' => $brand->label(),
                'customer_id' => $customer->id,
                'contact_id' => $resolvedContactId,
                'laboratory_test_ids' => $laboratoryTestIds,
                'cart_item_ids' => $cartItemIds,
            ];
        });
    }

    /**
     * @return list<int>
     */
    private function confirmedTestIds(ClinicalOrder $order): array
    {
        $fromStudies = collect($order->studies ?? [])
            ->filter(fn ($study) => ($study['status'] ?? 'confirmed') === 'confirmed')
            ->map(fn ($study) => (int) ($study['laboratory_test_id'] ?? 0))
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if ($fromStudies !== []) {
            return array_values(array_unique($fromStudies));
        }

        $fromPayload = collect($order->cart_payload['laboratory_test_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        return array_values(array_unique($fromPayload));
    }

    private function resolveContactId(
        Customer $customer,
        ClinicalOrder $order,
        ?int $contactId,
    ): ?int {
        if ($contactId) {
            $owns = $customer->contacts->contains(
                fn (Contact $contact) => (int) $contact->id === (int) $contactId
            );

            return $owns ? $contactId : null;
        }

        $patientName = trim((string) ($order->patient['name'] ?? ''));
        if ($patientName === '') {
            return $customer->contacts->first()?->id;
        }

        $needle = mb_strtolower($patientName);
        $matched = $customer->contacts->first(function (Contact $contact) use ($needle) {
            $full = mb_strtolower(trim(
                collect([
                    $contact->name,
                    $contact->paternal_lastname,
                    $contact->maternal_lastname,
                ])->filter()->implode(' ')
            ));

            return $full === $needle || str_contains($full, $needle) || str_contains($needle, $full);
        });

        return $matched?->id ?? $customer->contacts->first()?->id;
    }
}
