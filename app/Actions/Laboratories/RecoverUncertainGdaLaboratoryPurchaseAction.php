<?php

namespace App\Actions\Laboratories;

use App\Actions\Marketing\RecordMarketingCampaignConversionAction;
use App\Enums\CouponPurchaseType;
use App\Enums\CouponType;
use App\Enums\GdaOrderStatus;
use App\Enums\LaboratoryBrand;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Exceptions\RecoverGdaLaboratoryPurchaseException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\CouponApplicationService;
use App\Services\CouponService;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Services\Orders\OrderAutomationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RecoverUncertainGdaLaboratoryPurchaseAction
{
    public function __construct(
        private CreateGDAQuotationAction $createGDAQuotationAction,
        private SyncMonitoringCartService $syncMonitoringCartService,
        private CouponApplicationService $couponApplicationService,
        private SyncLaboratoryCheckoutDraftAction $syncLaboratoryCheckoutDraftAction,
        private OrderAutomationService $orderAutomationService,
        private RecordMarketingCampaignConversionAction $recordMarketingCampaignConversionAction,
        private CouponService $couponService,
    ) {}

    /**
     * Pedido pagado cuyo folio GDA nunca quedó confirmado (uncertain o histórico).
     */
    public function needsGdaRecovery(LaboratoryPurchase $purchase): bool
    {
        if ($purchase->trashed()) {
            return false;
        }

        if ($this->hasConfirmedGdaIdentifiers($purchase)) {
            return false;
        }

        if ($purchase->gda_status === GdaOrderStatus::Uncertain) {
            return $this->hasSuccessfulTransaction($purchase);
        }

        return $this->hasSuccessfulTransaction($purchase);
    }

    public function canRecover(LaboratoryPurchase $purchase): bool
    {
        if (! $this->needsGdaRecovery($purchase)) {
            return false;
        }

        if ($this->hasActiveCouponTransaction($purchase)) {
            return false;
        }

        if ($purchase->laboratoryPurchaseItems()->count() === 0) {
            return false;
        }

        try {
            $this->resolveCatalogTests($purchase);
        } catch (RecoverGdaLaboratoryPurchaseException) {
            return false;
        }

        $user = $purchase->customer?->user;
        if ($user === null) {
            return false;
        }

        return $this->eligibleBalanceCoupons($purchase, $user)->isNotEmpty();
    }

    /**
     * @return list<string>
     */
    public function recoverBlockReasons(LaboratoryPurchase $purchase): array
    {
        $reasons = [];

        if ($purchase->trashed()) {
            $reasons[] = 'El pedido está cancelado.';
        }

        if ($this->hasConfirmedGdaIdentifiers($purchase)) {
            $reasons[] = 'GDA ya tiene folio y consecutivo confirmados.';
        }

        if (! $this->hasSuccessfulTransaction($purchase)) {
            $reasons[] = 'No hay un pago capturado ligado al pedido.';
        }

        if ($this->hasActiveCouponTransaction($purchase)) {
            $reasons[] = 'Ya se aplicó un saldo a favor a este pedido.';
        }

        if ($purchase->laboratoryPurchaseItems()->count() === 0) {
            $reasons[] = 'El pedido no tiene estudios registrados.';
        }

        try {
            $this->resolveCatalogTests($purchase);
        } catch (RecoverGdaLaboratoryPurchaseException $e) {
            $reasons[] = $e->getMessage();
        }

        $user = $purchase->customer?->user;
        if ($user === null) {
            $reasons[] = 'El cliente no tiene usuario asociado.';
        } elseif ($this->eligibleBalanceCoupons($purchase, $user)->isEmpty()) {
            $reasons[] = 'El cliente no tiene saldo a favor aplicable al total del pedido.';
        }

        return $reasons;
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(LaboratoryPurchase $purchase): array
    {
        $purchase->loadMissing([
            'customer.user',
            'transactions',
            'laboratoryPurchaseItems',
            'laboratoryAppointment.laboratoryStore',
        ]);

        $customer = $purchase->customer;
        $user = $customer?->user;
        $brand = $purchase->brand;
        $catalogError = null;

        try {
            $catalogTests = $this->resolveCatalogTests($purchase);
        } catch (RecoverGdaLaboratoryPurchaseException $e) {
            $catalogTests = collect();
            $catalogError = $e->getMessage();
        }

        $transaction = $purchase->transactions->first(fn ($tx) => $tx->isSuccessfulPayment());

        return [
            'eligible' => $this->canRecover($purchase),
            'block_reasons' => $this->recoverBlockReasons($purchase),
            'user' => $user ? [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
            ] : null,
            'purchase' => [
                'id' => $purchase->id,
                'total_cents' => (int) $purchase->total_cents,
                'formatted_total' => $purchase->formatted_total,
                'brand' => $brand->value,
                'gda_status' => $purchase->gda_status?->value,
            ],
            'transaction' => $transaction ? [
                'id' => $transaction->id,
                'payment_method' => $transaction->payment_method,
                'formatted_amount' => $transaction->formatted_amount,
                'payment_status' => $transaction->payment_status,
            ] : null,
            'balance_coupons' => $user
                ? $this->eligibleBalanceCoupons($purchase, $user)->values()->all()
                : [],
            'gda_items' => $catalogTests->map(fn (LaboratoryTest $test) => [
                'gda_id' => $test->gda_id,
                'name' => $test->name,
                'price_cents' => (int) $test->famedic_price_cents,
                'formatted_price' => formattedCentsPrice((int) $test->famedic_price_cents),
            ])->values()->all(),
            'appointment' => $purchase->laboratoryAppointment ? [
                'id' => $purchase->laboratoryAppointment->id,
                'formatted_appointment_date' => $purchase->laboratoryAppointment->formatted_appointment_date,
                'store_name' => $purchase->laboratoryAppointment->laboratoryStore?->name,
                'will_clone' => true,
            ] : null,
            'gda_request' => [
                'purchase_id' => $purchase->id,
                'brand' => $brand->value,
                'patient_full_name' => $purchase->full_name,
            ],
            'catalog_error' => $catalogError,
        ];
    }

    public function __invoke(LaboratoryPurchase $purchase, int $couponId, User $actor): LaboratoryPurchase
    {
        if (! $this->canRecover($purchase)) {
            throw new RecoverGdaLaboratoryPurchaseException('Este pedido no puede recuperarse en GDA.');
        }

        return Cache::lock("gda-recover:{$purchase->id}", 120)->block(10, function () use ($purchase, $couponId, $actor) {
            $purchase->refresh();
            $purchase->loadMissing([
                'customer.user',
                'transactions',
                'laboratoryPurchaseItems',
                'laboratoryAppointment',
            ]);

            if (! $this->canRecover($purchase)) {
                throw new RecoverGdaLaboratoryPurchaseException('Este pedido ya no está disponible para recuperación.');
            }

            $customer = $purchase->customer;
            $user = $customer->user;
            $brand = $purchase->brand;
            $gdaBrandValue = $brand->value;

            $coupon = Coupon::query()->findOrFail($couponId);
            if ($coupon->type !== CouponType::Balance) {
                throw new RecoverGdaLaboratoryPurchaseException('Solo se pueden usar cupones de saldo a favor.');
            }

            $this->couponApplicationService->validateApplication(
                $user,
                $couponId,
                (int) $purchase->total_cents,
            );

            $initialTransactionLevel = DB::transactionLevel();
            $clonedAppointment = null;
            $clinicalOrderUuid = null;

            DB::beginTransaction();

            try {
                $this->resolveCatalogTests($purchase);
                $laboratoryCartItems = $this->rebuildCartItems($purchase, $customer);
                $this->syncMonitoringCartService->syncLaboratory($customer);
                $cart = $this->syncMonitoringCartService->activeLaboratoryCart($customer, $brand);

                if (! $cart) {
                    throw new RecoverGdaLaboratoryPurchaseException('No se pudo reconstruir el carrito de monitoreo.');
                }

                $clonedAppointment = $this->cloneAppointmentForRecovery($purchase, $cart, $customer, $brand);

                $contact = $this->contactFromPurchase($purchase);
                $address = $this->addressFromPurchase($purchase);

                if (app()->environment('local')) {
                    $gdaQuotation = [
                        'id' => strtoupper(uniqid('LOCAL')),
                        'infogda_consecutivo' => random_int(10000000, 99999999),
                    ];
                } else {
                    try {
                        $gdaQuotation = ($this->createGDAQuotationAction)(
                            $customer,
                            $address,
                            $contact,
                            $gdaBrandValue,
                            $laboratoryCartItems,
                            $purchase->id,
                        );
                    } catch (GdaOrderResultUncertainException $e) {
                        $this->markGdaUncertain($purchase, $e);
                        DB::commit();

                        throw $e;
                    }
                }

                $purchase->update([
                    'gda_order_id' => $gdaQuotation['id'],
                    'gda_consecutivo' => $gdaQuotation['infogda_consecutivo'] ?? null,
                    'gda_status' => GdaOrderStatus::Confirmed,
                    'has_gda_warning' => false,
                    'gda_warning_message' => null,
                    'gda_acuse' => $gdaQuotation['gda_acuse'] ?? null,
                    'gda_response' => $gdaQuotation['gda_response'] ?? null,
                    'gda_code_http' => $gdaQuotation['gda_code_http'] ?? null,
                    'gda_mensaje' => $gdaQuotation['gda_mensaje'] ?? null,
                    'gda_description' => $gdaQuotation['gda_description'] ?? null,
                    'pdf_base64' => $gdaQuotation['pdf_base64'] ?? null,
                ]);

                $this->assertGdaConfirmed($purchase);

                $this->couponApplicationService->applyForLaboratoryPurchase(
                    $user,
                    $purchase,
                    $couponId,
                );

                if ($cart && Schema::hasColumn('laboratory_purchases', 'cart_id')) {
                    $purchase->update(['cart_id' => $cart->id]);
                }

                if ($clonedAppointment instanceof LaboratoryAppointment) {
                    $clonedAppointment->laboratory_purchase_id = $purchase->id;
                    if (Schema::hasColumn('laboratory_appointments', 'cart_id')) {
                        $clonedAppointment->cart_id = $cart->id;
                    }
                    $clonedAppointment->save();
                }

                $this->syncMonitoringCartService->markLaboratoryCartCompleted($customer, $brand);

                $clinicalOrderUuid = LaboratoryCheckoutDraft::query()
                    ->where('customer_id', $customer->id)
                    ->where('laboratory_brand', $brand)
                    ->value('clinical_order_uuid');

                $this->syncLaboratoryCheckoutDraftAction->clearForCustomer($customer, $brand);
                $this->clearCart($customer, $brand);

                Log::info('[GDA Recover] Laboratory purchase recovered by admin', [
                    'purchase_id' => $purchase->id,
                    'admin_user_id' => $actor->id,
                    'coupon_id' => $couponId,
                    'gda_order_id' => $purchase->gda_order_id,
                ]);

                DB::commit();
            } catch (GdaOrderResultUncertainException $e) {
                if (DB::transactionLevel() > $initialTransactionLevel) {
                    DB::rollBack();
                }

                throw $e;
            } catch (Throwable $e) {
                if (DB::transactionLevel() > $initialTransactionLevel) {
                    DB::rollBack();
                }

                throw $e;
            }

            $purchase->refresh();
            $purchase->load([
                'transactions',
                'laboratoryPurchaseItems',
                'customer.user',
                'laboratoryAppointment.laboratoryStore',
            ]);

            ($this->recordMarketingCampaignConversionAction)($purchase);
            $this->dispatchLaboratoryOrderAutomation($purchase);

            return $purchase;
        });
    }

    /**
     * @return Collection<int, LaboratoryTest>
     */
    private function resolveCatalogTests(LaboratoryPurchase $purchase): Collection
    {
        $purchase->loadMissing('laboratoryPurchaseItems');
        $brand = $purchase->brand;

        return $purchase->laboratoryPurchaseItems->map(function ($purchaseItem) use ($brand) {
            $test = LaboratoryTest::query()
                ->where('gda_id', $purchaseItem->gda_id)
                ->where('brand', $brand->value)
                ->first();

            if ($test === null) {
                throw new RecoverGdaLaboratoryPurchaseException(
                    "No se encontró estudio de catálogo para gda_id {$purchaseItem->gda_id}."
                );
            }

            return $test;
        });
    }

    /**
     * @return Collection<int, \App\Models\LaboratoryCartItem>
     */
    private function rebuildCartItems(LaboratoryPurchase $purchase, Customer $customer): Collection
    {
        $brand = $purchase->brand;
        $catalogTests = $this->resolveCatalogTests($purchase);

        $customer->laboratoryCartItems()->ofBrand($brand)->delete();

        return $catalogTests->map(function (LaboratoryTest $test) use ($customer) {
            $cartItem = $customer->laboratoryCartItems()->create([
                'laboratory_test_id' => $test->id,
            ]);

            return $cartItem->load('laboratoryTest');
        });
    }

    private function cloneAppointmentForRecovery(
        LaboratoryPurchase $purchase,
        Cart $cart,
        Customer $customer,
        LaboratoryBrand $brand,
    ): ?LaboratoryAppointment {
        $source = $purchase->laboratoryAppointment;

        if (! $customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            return null;
        }

        if ($source === null) {
            throw new RecoverGdaLaboratoryPurchaseException(
                'Este pedido requiere cita de laboratorio pero no hay una cita previa para clonar.'
            );
        }

        $source->update(['laboratory_purchase_id' => null]);

        $clone = $source->replicate();
        $clone->laboratory_purchase_id = null;
        $clone->cart_id = $cart->id;
        $clone->save();

        return $clone;
    }

    private function contactFromPurchase(LaboratoryPurchase $purchase): Contact
    {
        return new Contact([
            'customer_id' => $purchase->customer_id,
            'name' => $purchase->name,
            'paternal_lastname' => $purchase->paternal_lastname,
            'maternal_lastname' => $purchase->maternal_lastname,
            'phone' => $purchase->phone,
            'phone_country' => $purchase->phone_country,
            'birth_date' => $purchase->birth_date,
            'gender' => $purchase->gender,
        ]);
    }

    private function addressFromPurchase(LaboratoryPurchase $purchase): Address
    {
        return new Address([
            'customer_id' => $purchase->customer_id,
            'street' => $purchase->street,
            'number' => $purchase->number,
            'neighborhood' => $purchase->neighborhood,
            'state' => $purchase->state,
            'city' => $purchase->city,
            'zipcode' => $purchase->zipcode,
            'additional_references' => $purchase->additional_references,
        ]);
    }

    private function hasConfirmedGdaIdentifiers(LaboratoryPurchase $purchase): bool
    {
        return filled($purchase->gda_order_id)
            && trim((string) $purchase->gda_order_id) !== '0'
            && filled($purchase->gda_consecutivo);
    }

    private function hasSuccessfulTransaction(LaboratoryPurchase $purchase): bool
    {
        $purchase->loadMissing('transactions');

        return $purchase->transactions->contains(fn ($transaction) => $transaction->isSuccessfulPayment());
    }

    private function hasActiveCouponTransaction(LaboratoryPurchase $purchase): bool
    {
        return CouponTransaction::query()
            ->where('purchase_type', CouponPurchaseType::Lab)
            ->where('purchase_id', $purchase->id)
            ->notReversed()
            ->exists();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function eligibleBalanceCoupons(LaboratoryPurchase $purchase, User $user): \Illuminate\Support\Collection
    {
        $totalCents = (int) $purchase->total_cents;

        return $this->couponService->getAvailableCoupons($user->id)
            ->filter(fn (array $coupon) => ($coupon['type'] ?? null) === CouponType::Balance->value)
            ->filter(function (array $coupon) use ($totalCents, $user) {
                try {
                    $this->couponApplicationService->validateApplication(
                        $user,
                        (int) $coupon['id'],
                        $totalCents,
                    );

                    return true;
                } catch (Throwable) {
                    return false;
                }
            })
            ->map(fn (array $coupon) => array_merge($coupon, [
                'formatted_remaining' => formattedCentsPrice((int) $coupon['remaining_cents']),
            ]));
    }

    private function clearCart(Customer $customer, LaboratoryBrand $laboratoryBrand): void
    {
        $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->delete();
    }

    private function assertGdaConfirmed(LaboratoryPurchase $laboratoryPurchase): void
    {
        $laboratoryPurchase->refresh();

        if (
            $laboratoryPurchase->gda_status !== GdaOrderStatus::Confirmed
            || blank($laboratoryPurchase->gda_order_id)
            || trim((string) $laboratoryPurchase->gda_order_id) === '0'
            || blank($laboratoryPurchase->gda_consecutivo)
        ) {
            throw GdaOrderResultUncertainException::forInvariant(
                'gda_completion_invariant_failed',
                $laboratoryPurchase,
            );
        }
    }

    private function markGdaUncertain(
        LaboratoryPurchase $laboratoryPurchase,
        GdaOrderResultUncertainException $exception,
    ): void {
        $laboratoryPurchase->update([
            'gda_status' => GdaOrderStatus::Uncertain,
            'has_gda_warning' => true,
            'gda_warning_message' => $exception->getMessage(),
            'gda_code_http' => $exception->httpStatus(),
            'gda_mensaje' => 'uncertain',
            'gda_description' => $exception->reason(),
        ]);

        Log::warning('[GDA Recover] Retry returned uncertain GDA result', $exception->context() + [
            'purchase_id' => $laboratoryPurchase->id,
        ]);
    }

    private function dispatchLaboratoryOrderAutomation(LaboratoryPurchase $laboratoryPurchase): void
    {
        try {
            $laboratoryPurchase->refresh();
            $laboratoryPurchase->loadMissing(['customer.user', 'transactions', 'laboratoryPurchaseItems']);

            if ($laboratoryPurchase->transactions->isEmpty()) {
                return;
            }

            $context = $this->orderAutomationService->contextForLaboratory($laboratoryPurchase);
            $this->orderAutomationService->handleLaboratoryOrder($context);
        } catch (Throwable $e) {
            Log::error('[GDA Recover] Order automation failed after recovery', [
                'purchase_id' => $laboratoryPurchase->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
