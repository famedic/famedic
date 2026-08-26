<?php

namespace App\Actions\Laboratories;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\Transaction;
use App\Notifications\FewDaysLeftToRequestInvoice;
use App\Notifications\LaboratoryAppointmentUpdatedByConcierge;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Services\CouponApplicationService;
use App\Services\Carts\CartEventRecorder;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Services\Orders\OrderAutomationService;
use App\Services\PromoCodeService;
use App\Support\LocalExternalIntegrationGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

class FulfillLaboratoryCartOrderAction
{
    public function __construct(
        private CreateGDAQuotationAction $createGDAQuotationAction,
        private SyncMonitoringCartService $syncMonitoringCartService,
        private CouponApplicationService $couponApplicationService,
        private PromoCodeService $promoCodeService,
        private SyncLaboratoryCheckoutDraftAction $syncLaboratoryCheckoutDraftAction,
        private OrderAutomationService $orderAutomationService,
        private CartEventRecorder $cartEventRecorder,
    ) {}

    /**
     * Crea el pedido de laboratorio, cotización GDA, limpia carrito y notifica.
     * Debe llamarse cuando el cobro ya fue autorizado/capturado (tarjeta, PayPal, etc.).
     */
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        Address $address,
        Contact $contact,
        Transaction $transaction,
        ?LaboratoryAppointment $laboratoryAppointment,
        Collection $laboratoryCartItems,
        string $gdaBrandValue,
        ?int $couponId = null,
        ?string $promoValidationToken = null,
        ?string $cartHash = null,
        ?Cart $cart = null,
        ?array $clientContext = null,
    ): LaboratoryPurchase {
        if (! LocalExternalIntegrationGate::allows('laboratory_fulfillment')) {
            throw new \RuntimeException(
                'Pedidos de laboratorio bloqueados durante pruebas locales reales. Use el cobro aislado local.'
            );
        }

        $cart ??= $this->syncMonitoringCartService->activeLaboratoryCart($customer, $laboratoryBrand);

        if (! $cart && $customer->user_id && $laboratoryCartItems->isNotEmpty()) {
            Log::warning('[CartTraceability] Laboratory purchase could not resolve monitoring cart', [
                'customer_id' => $customer->id,
                'user_id' => $customer->user_id,
                'laboratory_cart_items_count' => $laboratoryCartItems->count(),
                'brand' => $laboratoryBrand->value,
            ]);
        }

        DB::beginTransaction();

        $clinicalOrderUuid = null;

        try {
            $laboratoryPurchase = $this->createLaboratoryPurchase(
                $customer,
                $laboratoryBrand,
                $contact,
                $address,
                $laboratoryCartItems,
                $cart,
            );

            if ($cart) {
                $this->cartEventRecorder->recordOnce(
                    $cart,
                    CartEventType::PurchaseCreated,
                    "laboratory_purchase:{$laboratoryPurchase->id}:created",
                    $this->withClientContext([
                        'laboratory_purchase_id' => $laboratoryPurchase->id,
                        'brand' => $laboratoryBrand->value,
                        'total_cents' => $laboratoryPurchase->total_cents,
                    ], $clientContext),
                    $laboratoryPurchase->created_at,
                    'laboratory_order',
                );
            }

            $laboratoryPurchase->transactions()->attach($transaction);

            if (
                $laboratoryAppointment
                && $customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)
            ) {
                $laboratoryAppointment->laboratory_purchase_id = $laboratoryPurchase->id;
                if ($cart && Schema::hasColumn('laboratory_appointments', 'cart_id')) {
                    $laboratoryAppointment->cart_id ??= $cart->id;
                }
                $laboratoryAppointment->save();
            }

            logger('=== GDA BRAND DEBUG (FulfillLaboratoryCartOrderAction) ===');
            logger('LaboratoryBrand Enum value: '.$laboratoryBrand->value);
            logger('GDA brand value: '.$gdaBrandValue);

            if (app()->environment('local')) {
                $gdaQuotation = ['id' => rand(100000, 999999)];
            } else {
                $gdaQuotation = ($this->createGDAQuotationAction)(
                    $customer,
                    $address,
                    $contact,
                    $gdaBrandValue,
                    $laboratoryCartItems,
                    $laboratoryPurchase->id
                );
            }

            $laboratoryPurchase->update([
                'gda_order_id' => $gdaQuotation['id'],
                'gda_consecutivo' => $gdaQuotation['infogda_consecutivo'] ?? null,
                'gda_acuse' => $gdaQuotation['gda_acuse'] ?? null,
                'gda_response' => $gdaQuotation['gda_response'] ?? null,
                'gda_code_http' => $gdaQuotation['gda_code_http'] ?? null,
                'gda_mensaje' => $gdaQuotation['gda_mensaje'] ?? null,
                'gda_description' => $gdaQuotation['gda_description'] ?? null,
                'pdf_base64' => $gdaQuotation['pdf_base64'] ?? null,
            ]);

            if ($promoValidationToken !== null) {
                $resolvedCartHash = $cartHash ?? $this->promoCodeService->buildLaboratoryCartHash(
                    $laboratoryCartItems,
                    (int) $laboratoryPurchase->total_cents
                );

                $this->promoCodeService->confirmRedemption(
                    $customer->user,
                    $promoValidationToken,
                    $laboratoryPurchase,
                    (int) $laboratoryPurchase->total_cents,
                    $resolvedCartHash,
                );
            } elseif ($couponId !== null) {
                $this->couponApplicationService->applyForLaboratoryPurchase(
                    $customer->user,
                    $laboratoryPurchase,
                    $couponId
                );
            }

            $this->syncMonitoringCartService->markLaboratoryCartCompleted($customer, $laboratoryBrand, $clientContext);

            $clinicalOrderUuid = LaboratoryCheckoutDraft::query()
                ->where('customer_id', $customer->id)
                ->where('laboratory_brand', $laboratoryBrand)
                ->value('clinical_order_uuid');

            $this->syncLaboratoryCheckoutDraftAction->clearForCustomer($customer, $laboratoryBrand);
            $this->clearCart($customer, $laboratoryBrand);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        // Post-commit only: purchase + transaction are durable. Never rollback checkout on automation failure.
        $this->dispatchLaboratoryOrderAutomation($laboratoryPurchase);

        if (is_string($clinicalOrderUuid) && $clinicalOrderUuid !== '') {
            try {
                $clinicalOrders = app(\App\Services\ClinicalOrder\ClinicalOrderService::class);
                $clinicalOrder = $clinicalOrders->findByUuid($clinicalOrderUuid);
                if ($clinicalOrder) {
                    $clinicalOrders->completeFromLaboratoryPurchase(
                        $clinicalOrder,
                        (int) $laboratoryPurchase->id,
                    );
                }
            } catch (\Throwable $e) {
                logger()->warning('clinical_interpreter.purchase_link_failed', [
                    'clinical_order_uuid' => $clinicalOrderUuid,
                    'purchase_id' => $laboratoryPurchase->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $laboratoryPurchase->customer->user->notify(new LaboratoryPurchaseCreated($laboratoryPurchase));

        if ($laboratoryAppointment?->laboratory_purchase_id === $laboratoryPurchase->id) {
            $laboratoryAppointment->refresh();
            $laboratoryAppointment->loadMissing([
                'laboratoryStore',
                'laboratoryPurchase.transactions',
                'laboratoryPurchase.laboratoryPurchaseItems',
                'customer.laboratoryCartItems.laboratoryTest',
            ]);

            $laboratoryPurchase->customer->user->notify(
                new LaboratoryAppointmentUpdatedByConcierge($laboratoryAppointment)
            );
        }

        $this->checkAndSendInvoiceDeadlineNotification($laboratoryPurchase);

        return $laboratoryPurchase;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $clientContext
     * @return array<string, mixed>
     */
    private function withClientContext(array $metadata, ?array $clientContext): array
    {
        if ($clientContext === null || $clientContext === []) {
            return $metadata;
        }

        return array_merge($metadata, ['client' => $clientContext]);
    }

    /**
     * Fire order automations once after a successful DB commit.
     * Isolates AC/Email/etc. from the purchase transaction.
     */
    private function dispatchLaboratoryOrderAutomation(LaboratoryPurchase $laboratoryPurchase): void
    {
        $automationStartedAt = now();
        $startedHrtime = hrtime(true);

        try {
            $laboratoryPurchase->refresh();
            $laboratoryPurchase->loadMissing(['customer.user', 'transactions', 'laboratoryPurchaseItems']);

            $hasTransaction = $laboratoryPurchase->transactions->isNotEmpty();
            if (! $hasTransaction) {
                Log::warning('[Order Automation Failed] Laboratory purchase has no attached transaction', [
                    'purchase_id' => $laboratoryPurchase->id,
                    'automation_started_at' => $automationStartedAt->toIso8601String(),
                    'automation_success' => false,
                ]);

                return;
            }

            Log::info('[Order Automation Started]', [
                'channel' => 'laboratory',
                'purchase_id' => $laboratoryPurchase->id,
                'transaction_id' => $laboratoryPurchase->transactions->sortByDesc('id')->first()?->id,
                'customer_id' => $laboratoryPurchase->customer_id,
                'automation_started_at' => $automationStartedAt->toIso8601String(),
            ]);

            $context = $this->orderAutomationService->contextForLaboratory($laboratoryPurchase);
            $dispatchResult = $this->orderAutomationService->handleLaboratoryOrder($context);

            $automationFinishedAt = now();
            $durationMs = (int) round((hrtime(true) - $startedHrtime) / 1_000_000);

            Log::info('[Order Automation Completed]', [
                'channel' => 'laboratory',
                'purchase_id' => $laboratoryPurchase->id,
                'automation_started_at' => $automationStartedAt->toIso8601String(),
                'automation_finished_at' => $automationFinishedAt->toIso8601String(),
                'automation_duration_ms' => $durationMs,
                'automation_success' => $dispatchResult->ok(),
                'automation_driver_count' => count($dispatchResult->drivers),
                'automation_failed_count' => $dispatchResult->failed,
                'dispatch' => $dispatchResult->toArray(),
            ]);

            if (! $dispatchResult->ok()) {
                Log::warning('[Order Automation Failed]', [
                    'channel' => 'laboratory',
                    'purchase_id' => $laboratoryPurchase->id,
                    'automation_started_at' => $automationStartedAt->toIso8601String(),
                    'automation_finished_at' => $automationFinishedAt->toIso8601String(),
                    'automation_duration_ms' => $durationMs,
                    'automation_success' => false,
                    'automation_driver_count' => count($dispatchResult->drivers),
                    'automation_failed_count' => $dispatchResult->failed,
                    'errors' => $dispatchResult->errors,
                    'dispatch' => $dispatchResult->toArray(),
                ]);
            }
        } catch (Throwable $e) {
            $automationFinishedAt = now();
            $durationMs = (int) round((hrtime(true) - $startedHrtime) / 1_000_000);

            Log::error('[Order Automation Failed]', [
                'channel' => 'laboratory',
                'purchase_id' => $laboratoryPurchase->id,
                'customer_id' => $laboratoryPurchase->customer_id,
                'automation_started_at' => $automationStartedAt->toIso8601String(),
                'automation_finished_at' => $automationFinishedAt->toIso8601String(),
                'automation_duration_ms' => $durationMs,
                'automation_success' => false,
                'automation_driver_count' => null,
                'automation_failed_count' => null,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    private function clearCart(Customer $customer, LaboratoryBrand $laboratoryBrand): void
    {
        $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->delete();
    }

    private function createLaboratoryPurchase(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        Contact $contact,
        Address $address,
        Collection $laboratoryCartItems,
        ?Cart $cart = null,
    ): LaboratoryPurchase {
        $totalCents = $laboratoryCartItems->sum(function ($laboratoryCartItem) {
            return $laboratoryCartItem->laboratoryTest->famedic_price_cents;
        });

        $payload = [
            'gda_order_id' => 0,
            'brand' => $laboratoryBrand->value,
            'name' => $contact->name,
            'paternal_lastname' => $contact->paternal_lastname,
            'maternal_lastname' => $contact->maternal_lastname,
            'phone' => str_replace(' ', '', (new PhoneNumber($contact->phone, $contact->phone_country))->formatNational()),
            'phone_country' => $contact->phone_country,
            'birth_date' => $contact->birth_date,
            'gender' => $contact->gender,
            'street' => $address->street,
            'number' => $address->number,
            'neighborhood' => $address->neighborhood,
            'state' => $address->state,
            'city' => $address->city,
            'zipcode' => $address->zipcode,
            'additional_references' => $address->additional_references,
            'total_cents' => $totalCents,
        ];

        if ($cart && Schema::hasColumn('laboratory_purchases', 'cart_id')) {
            $payload['cart_id'] = $cart->id;
        }

        $laboratoryPurchase = $customer->laboratoryPurchases()->save(
            new LaboratoryPurchase($payload)
        );

        foreach ($laboratoryCartItems as $laboratoryCartItem) {
            $laboratoryPurchase->laboratoryPurchaseItems()->save(
                new LaboratoryPurchaseItem([
                    'name' => $laboratoryCartItem->laboratoryTest->name,
                    'description' => $laboratoryCartItem->laboratoryTest->description,
                    'feature_list' => $laboratoryCartItem->laboratoryTest->feature_list,
                    'gda_id' => $laboratoryCartItem->laboratoryTest->gda_id,
                    'indications' => $laboratoryCartItem->laboratoryTest->indications,
                    'price_cents' => $laboratoryCartItem->laboratoryTest->famedic_price_cents,
                ])
            );
        }

        return $laboratoryPurchase;
    }

    private function checkAndSendInvoiceDeadlineNotification(LaboratoryPurchase $laboratoryPurchase): void
    {
        if (! $laboratoryPurchase->customer->taxProfiles()->exists()) {
            return;
        }

        $lastDayOfPurchaseMonth = $laboratoryPurchase->created_at->endOfMonth();
        $daysLeft = now()->diffInDays($lastDayOfPurchaseMonth);

        if ($daysLeft <= 7) {
            $laboratoryPurchase->customer->user->notify(
                new FewDaysLeftToRequestInvoice($laboratoryPurchase, $daysLeft)
            );
        }
    }
}
