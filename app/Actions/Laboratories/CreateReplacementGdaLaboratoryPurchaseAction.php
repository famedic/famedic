<?php

namespace App\Actions\Laboratories;

use App\Actions\Marketing\RecordMarketingCampaignConversionAction;
use App\Enums\GdaOrderStatus;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryGdaFailureOperation;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Exceptions\RecoverGdaLaboratoryPurchaseException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\User;
use App\Services\CouponApplicationService;
use App\Services\Laboratory\LaboratoryGdaFailureLogService;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Support\GDA\GdaApiUrl;
use App\Services\Orders\OrderAutomationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CreateReplacementGdaLaboratoryPurchaseAction
{
    public function __construct(
        private RecoverUncertainGdaLaboratoryPurchaseAction $recoverUncertainGdaLaboratoryPurchaseAction,
        private CreateGDAQuotationAction $createGDAQuotationAction,
        private SyncMonitoringCartService $syncMonitoringCartService,
        private CouponApplicationService $couponApplicationService,
        private SyncLaboratoryCheckoutDraftAction $syncLaboratoryCheckoutDraftAction,
        private OrderAutomationService $orderAutomationService,
        private RecordMarketingCampaignConversionAction $recordMarketingCampaignConversionAction,
        private LaboratoryGdaFailureLogService $laboratoryGdaFailureLogService,
    ) {}

    public function canReplace(LaboratoryPurchase $source): bool
    {
        if (filled($source->replacement_laboratory_purchase_id)) {
            return false;
        }

        return $this->recoverUncertainGdaLaboratoryPurchaseAction->canRecover($source);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(LaboratoryPurchase $source): array
    {
        $preview = $this->recoverUncertainGdaLaboratoryPurchaseAction->preview($source);

        return array_merge($preview, [
            'mode' => 'replacement',
            'source_purchase_id' => $source->id,
            'replacement_note' => 'Se creará un pedido nuevo con ID distinto para enviar a GDA. '
                .'El pedido original conservará el pago capturado como referencia.',
            'gda_request' => [
                'source_purchase_id' => $source->id,
                'new_purchase_id' => '(se asignará al confirmar)',
                'brand' => $source->brand->value,
                'patient_full_name' => $source->full_name,
            ],
        ]);
    }

    public function __invoke(LaboratoryPurchase $source, int $couponId, User $actor): LaboratoryPurchase
    {
        if (! $this->canReplace($source)) {
            throw new RecoverGdaLaboratoryPurchaseException('Este pedido no puede reemplazarse con uno nuevo en GDA.');
        }

        return Cache::lock("gda-replace:{$source->id}", 120)->block(10, function () use ($source, $couponId, $actor) {
            $source->refresh();
            $source->loadMissing([
                'customer.user',
                'transactions',
                'laboratoryPurchaseItems',
                'laboratoryAppointment',
            ]);

            if (! $this->canReplace($source)) {
                throw new RecoverGdaLaboratoryPurchaseException('Este pedido ya no está disponible para reemplazo.');
            }

            $customer = $source->customer;
            $user = $customer->user;
            $brand = $source->brand;
            $gdaBrandValue = $brand->value;

            $this->couponApplicationService->validateApplicationForGdaRecovery(
                $user,
                $couponId,
                (int) $source->total_cents,
            );

            $initialTransactionLevel = DB::transactionLevel();
            $replacementPurchase = null;
            $clonedAppointment = null;

            DB::beginTransaction();

            try {
                $replacementPurchase = $this->createReplacementPurchase($source, $customer);
                $laboratoryCartItems = $this->recoverUncertainGdaLaboratoryPurchaseAction
                    ->rebuildCartItemsForAdmin($source, $customer);
                $this->syncMonitoringCartService->syncLaboratory($customer);
                $cart = $this->syncMonitoringCartService->activeLaboratoryCart($customer, $brand);

                if (! $cart) {
                    throw new RecoverGdaLaboratoryPurchaseException('No se pudo reconstruir el carrito de monitoreo.');
                }

                $clonedAppointment = $this->recoverUncertainGdaLaboratoryPurchaseAction
                    ->cloneAppointmentForAdmin($source, $cart, $customer, $brand);
                $contact = $this->contactFromPurchase($source);
                $address = $this->addressFromPurchase($source);

                if (GdaApiUrl::shouldSimulateOrders()) {
                    $gdaQuotation = [
                        'id' => strtoupper(uniqid('LOCAL')),
                        'infogda_consecutivo' => random_int(10000000, 99999999),
                    ];
                } else {
                    $gdaQuotation = ($this->createGDAQuotationAction)(
                        $customer,
                        $address,
                        $contact,
                        $gdaBrandValue,
                        $laboratoryCartItems,
                        $replacementPurchase->id,
                    );
                }

                $replacementPurchase->update([
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

                $this->assertGdaConfirmed($replacementPurchase);

                $this->couponApplicationService->applyForLaboratoryPurchaseGdaRecovery(
                    $user,
                    $replacementPurchase,
                    $couponId,
                );

                if (Schema::hasColumn('laboratory_purchases', 'cart_id')) {
                    $replacementPurchase->update(['cart_id' => $cart->id]);
                }

                if ($clonedAppointment instanceof LaboratoryAppointment) {
                    $clonedAppointment->laboratory_purchase_id = $replacementPurchase->id;
                    if (Schema::hasColumn('laboratory_appointments', 'cart_id')) {
                        $clonedAppointment->cart_id = $cart->id;
                    }
                    $clonedAppointment->save();
                }

                $source->update([
                    'replacement_laboratory_purchase_id' => $replacementPurchase->id,
                    'has_gda_warning' => true,
                    'gda_warning_message' => 'Reemplazado por pedido #'.$replacementPurchase->id.' (GDA con nuevo ID).',
                    'gda_description' => 'El cobro original permanece en este pedido; la orden operativa en GDA es el reemplazo.',
                ]);

                $this->syncMonitoringCartService->markLaboratoryCartCompleted($customer, $brand);
                $this->syncLaboratoryCheckoutDraftAction->clearForCustomer($customer, $brand);
                $this->clearCart($customer, $brand);

                Log::info('[GDA Replace] Replacement laboratory purchase created', [
                    'source_purchase_id' => $source->id,
                    'replacement_purchase_id' => $replacementPurchase->id,
                    'admin_user_id' => $actor->id,
                    'coupon_id' => $couponId,
                    'gda_order_id' => $replacementPurchase->gda_order_id,
                ]);

                DB::commit();
            } catch (GdaOrderResultUncertainException $e) {
                if (DB::transactionLevel() > $initialTransactionLevel) {
                    DB::rollBack();
                }

                $this->laboratoryGdaFailureLogService->recordUncertain(
                    $e,
                    LaboratoryGdaFailureOperation::AdminReplace,
                    administrator: $actor,
                    purchase: $replacementPurchase ?? $source,
                    sourceLaboratoryPurchaseId: $source->id,
                );

                throw $e;
            } catch (Throwable $e) {
                if (DB::transactionLevel() > $initialTransactionLevel) {
                    DB::rollBack();
                }

                throw $e;
            }

            $replacementPurchase->refresh()->load([
                'transactions',
                'laboratoryPurchaseItems',
                'customer.user',
                'laboratoryAppointment.laboratoryStore',
                'replacedLaboratoryPurchase',
            ]);

            ($this->recordMarketingCampaignConversionAction)($replacementPurchase);
            $this->dispatchLaboratoryOrderAutomation($replacementPurchase);

            return $replacementPurchase;
        });
    }

    private function createReplacementPurchase(
        LaboratoryPurchase $source,
        Customer $customer,
    ): LaboratoryPurchase {
        $payload = [
            'gda_order_id' => '0',
            'gda_status' => GdaOrderStatus::Pending,
            'brand' => $source->brand,
            'name' => $source->name,
            'paternal_lastname' => $source->paternal_lastname,
            'maternal_lastname' => $source->maternal_lastname,
            'phone' => $source->phone,
            'phone_country' => $source->phone_country,
            'birth_date' => $source->birth_date,
            'gender' => $source->gender,
            'street' => $source->street,
            'number' => $source->number,
            'neighborhood' => $source->neighborhood,
            'state' => $source->state,
            'city' => $source->city,
            'zipcode' => $source->zipcode,
            'additional_references' => $source->additional_references,
            'total_cents' => $source->total_cents,
            'replaces_laboratory_purchase_id' => $source->id,
        ];

        $replacementPurchase = $customer->laboratoryPurchases()->create($payload);

        $source->loadMissing('laboratoryPurchaseItems');

        foreach ($source->laboratoryPurchaseItems as $item) {
            $replacementPurchase->laboratoryPurchaseItems()->save(
                new LaboratoryPurchaseItem([
                    'name' => $item->name,
                    'description' => $item->description,
                    'feature_list' => $item->feature_list,
                    'gda_id' => $item->gda_id,
                    'indications' => $item->indications,
                    'price_cents' => $item->price_cents,
                ])
            );
        }

        return $replacementPurchase->fresh(['laboratoryPurchaseItems']);
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
            Log::error('[GDA Replace] Order automation failed after replacement', [
                'purchase_id' => $laboratoryPurchase->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
