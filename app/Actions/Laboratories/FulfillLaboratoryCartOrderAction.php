<?php

namespace App\Actions\Laboratories;

use App\Actions\Marketing\RecordMarketingCampaignConversionAction;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryGdaFailureOperation;
use App\Services\Laboratory\LaboratoryGdaFailureLogService;
use App\Enums\GdaOrderStatus;
use App\Exceptions\GdaOrderDefinitiveException;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Services\Laboratory\GdaOrderConfirmation;
use App\Services\Laboratory\UncertainGdaPaymentGuard;
use App\Jobs\GenerateLaboratoryPurchasePreparationSummaryJob;
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
use App\Services\Carts\CartAbandonmentService;
use App\Services\Carts\CartEventRecorder;
use App\Services\CouponApplicationService;
use App\Services\LaboratoryPreparation\LaboratoryPreparationEligibility;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Services\Orders\OrderAutomationService;
use App\Services\PromoCodeService;
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
        private CartAbandonmentService $cartAbandonmentService,
        private RecordMarketingCampaignConversionAction $recordMarketingCampaignConversionAction,
        private LaboratoryPreparationSummaryService $laboratoryPreparationSummaryService,
        private LaboratoryPreparationEligibility $laboratoryPreparationEligibility,
        private LaboratoryGdaFailureLogService $laboratoryGdaFailureLogService,
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
        return app(UncertainGdaPaymentGuard::class)->withLock('transaction:'.$transaction->id, function () use ($customer, $laboratoryBrand, $address, $contact, $transaction, $laboratoryAppointment, $laboratoryCartItems, $gdaBrandValue, $couponId, $promoValidationToken, $cartHash, $cart, $clientContext) {
            $existing = $transaction->laboratoryPurchases()->useWritePdo()->withTrashed()->first();
            if ($existing) {
                if (in_array($existing->gda_status, [GdaOrderStatus::Pending, GdaOrderStatus::Uncertain], true)) {
                    $safe = new GdaOrderResultUncertainException('gda_replay_unresolved', $existing);
                    $this->markGdaUncertain($existing, $safe);
                    throw $safe;
                }
                return $existing;
            }
            return $this->fulfill($customer, $laboratoryBrand, $address, $contact, $transaction, $laboratoryAppointment, $laboratoryCartItems, $gdaBrandValue, $couponId, $promoValidationToken, $cartHash, $cart, $clientContext);
        });
    }

    private function fulfill(
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
        $cart ??= $this->syncMonitoringCartService->activeLaboratoryCart($customer, $laboratoryBrand);

        if (! $cart && $customer->user_id && $laboratoryCartItems->isNotEmpty()) {
            Log::warning('[CartTraceability] Laboratory purchase could not resolve monitoring cart', [
                'customer_id' => $customer->id,
                'user_id' => $customer->user_id,
                'laboratory_cart_items_count' => $laboratoryCartItems->count(),
                'brand' => $laboratoryBrand->value,
            ]);
        }

        $initialTransactionLevel = DB::transactionLevel();
        if ($initialTransactionLevel > 0 && ! app()->runningUnitTests()) {
            throw new GdaOrderDefinitiveException;
        }
        $durable = false;
        $gdaQuotation = null;
        $laboratoryPurchase = null;
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
                $this->cartAbandonmentService->maybeRecordResumed($cart, $clientContext);

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

            // Persist the payment linkage before any possibly ambiguous external order request.
            DB::commit();
            $durable = true;
            $gdaQuotation = ($this->createGDAQuotationAction)(
                $customer, $address, $contact, $gdaBrandValue, $laboratoryCartItems, $laboratoryPurchase->id,
            );
            if (! GdaOrderConfirmation::valid($gdaQuotation)) {
                throw new GdaOrderResultUncertainException('gda_invalid_identifiers', $laboratoryPurchase);
            }
            DB::beginTransaction();

            $laboratoryPurchase->update([
                'gda_order_id' => $gdaQuotation['id'],
                'gda_status' => GdaOrderStatus::Confirmed,
                'gda_consecutivo' => $gdaQuotation['infogda_consecutivo'] ?? null,
                'gda_acuse' => $gdaQuotation['gda_acuse'] ?? null,
                'gda_response' => $gdaQuotation['gda_response'] ?? null,
                'gda_code_http' => $gdaQuotation['gda_code_http'] ?? null,
                'gda_mensaje' => $gdaQuotation['gda_mensaje'] ?? null,
                'gda_description' => $gdaQuotation['gda_description'] ?? null,
                'pdf_base64' => $gdaQuotation['pdf_base64'] ?? null,
            ]);

            $this->assertGdaConfirmedBeforeCompletingCart($laboratoryPurchase);

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
        } catch (\Throwable $exception) {
            try {
                if (DB::transactionLevel() > $initialTransactionLevel) {
                    DB::rollBack($initialTransactionLevel);
                }
            } catch (\Throwable) {
                // A failed rollback must never route a submitted order into automatic refund.
            }
            if ($durable && $laboratoryPurchase) {
                if ($exception instanceof GdaOrderDefinitiveException) {
                    $laboratoryPurchase->update(['gda_status' => GdaOrderStatus::Failed]);
                    throw $exception;
                }
                if (is_array($gdaQuotation) && GdaOrderConfirmation::valid($gdaQuotation)) {
                    $laboratoryPurchase->forceFill(['gda_order_id' => $gdaQuotation['id'], 'gda_consecutivo' => $gdaQuotation['infogda_consecutivo']]);
                }
                $safe = $exception instanceof GdaOrderResultUncertainException
                    ? $exception->withPurchase($laboratoryPurchase)
                    : new GdaOrderResultUncertainException('gda_persistence_failed', $laboratoryPurchase);
                $this->markGdaUncertain($laboratoryPurchase, $safe);
                throw $safe;
            }
            throw $exception;
        }

        // Communication registration cannot roll back a confirmed financial operation.
        try {
            $coordinatedConfirmation = app(\App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator::class)
                ->enrollAfterCommit($laboratoryPurchase);
        } catch (Throwable) {
            // Even dependency resolution is post-commit. Unknown ownership must not replay legacy mail.
            $coordinatedConfirmation = true;
            \App\Services\Laboratory\GdaPaymentDiagnostics::record('warning', 'laboratory_confirmation_registration_unavailable', ['purchase_id' => $laboratoryPurchase->id]);
        }

        try {
            // Post-commit only: purchase + transaction are durable. Never rollback checkout on automation failure.
            $this->dispatchLaboratoryPreparationSummaryGeneration($laboratoryPurchase);
            ($this->recordMarketingCampaignConversionAction)($laboratoryPurchase);
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

            if (! $coordinatedConfirmation) {
                $laboratoryPurchase->customer->user->notify(new LaboratoryPurchaseCreated($laboratoryPurchase));
            }

            if (! $coordinatedConfirmation && $laboratoryAppointment?->laboratory_purchase_id === $laboratoryPurchase->id) {
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

        } catch (Throwable) {
            \App\Services\Laboratory\GdaPaymentDiagnostics::record('warning', 'laboratory_purchase_post_commit_failed', ['purchase_id' => $laboratoryPurchase->id, 'error_code' => 'post_commit_delivery_failed']);
        }

        return $laboratoryPurchase;
    }

    private function assertGdaConfirmedBeforeCompletingCart(LaboratoryPurchase $laboratoryPurchase): void
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

    private function markGdaUncertain(LaboratoryPurchase $purchase, GdaOrderResultUncertainException $safe): void
    {
        $purchase->forceFill(['gda_status' => GdaOrderStatus::Uncertain,
            'has_gda_warning' => true, 'gda_warning_message' => $safe->getMessage(),
            'gda_code_http' => $safe->httpStatus(), 'gda_mensaje' => 'uncertain',
            'gda_description' => $safe->reason()]);
        try {
            $purchase->save();
        } catch (Throwable) {
            // The previously durable pending row remains a retry barrier if storage is unavailable.
            \App\Services\Laboratory\GdaPaymentDiagnostics::record('error', 'gda_uncertain_state_persistence_failed', $safe->context());
        }
        \App\Services\Laboratory\GdaPaymentDiagnostics::record('warning', 'gda_order_uncertain', $safe->context());
        try {
            $this->laboratoryGdaFailureLogService->recordUncertain($safe, LaboratoryGdaFailureOperation::Checkout, purchase: $purchase);
        } catch (Throwable) {
            \App\Services\Laboratory\GdaPaymentDiagnostics::record('error', 'gda_failure_log_persistence_failed', $safe->context());
        }
    }

    private function dispatchLaboratoryPreparationSummaryGeneration(LaboratoryPurchase $laboratoryPurchase): void
    {
        try {
            $v3Enabled = $this->laboratoryPreparationSummaryService->deterministicV3Enabled();
            $shadowEnabled = $this->laboratoryPreparationSummaryService->deterministicV3ShadowEnabled();

            if (! $v3Enabled && ! $shadowEnabled) {
                return;
            }

            $laboratoryPurchase->refresh();
            $laboratoryPurchase->loadMissing('laboratoryPurchaseItems');

            $eligibility = $this->laboratoryPreparationEligibility->evaluate($laboratoryPurchase);
            if (! $eligibility->eligible) {
                Log::info('[Laboratory Preparation AI] Skipped queueing for ineligible purchase', [
                    'purchase_id' => $laboratoryPurchase->id,
                    'reason' => $eligibility->reason,
                    'payment_method' => $eligibility->paymentMethod,
                ]);
                return;
            }

            if ($v3Enabled) {
                if (! $this->laboratoryPreparationSummaryService->shouldQueueGeneration($laboratoryPurchase)) {
                    return;
                }

                $confirmationCoordinator = app(\App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator::class);
                $coordinated = $confirmationCoordinator->appliesTo($laboratoryPurchase);
                if ($coordinated && ! $confirmationCoordinator->asyncConfigurationValid()) {
                    \App\Services\Laboratory\GdaPaymentDiagnostics::record('warning', 'laboratory_confirmation_generation_queue_invalid', ['purchase_id' => $laboratoryPurchase->id]);
                    return;
                }
                $preparationJob = GenerateLaboratoryPurchasePreparationSummaryJob::dispatch(
                    $laboratoryPurchase->id,
                    null,
                    $this->laboratoryPreparationSummaryService->expectedSourceHash($laboratoryPurchase),
                    GenerateLaboratoryPurchasePreparationSummaryJob::MODE_GENERATE,
                )->afterCommit();
                if ($coordinated) {
                    $preparationJob->onConnection(config('laboratory-confirmation.connection', 'database'))
                        ->onQueue(config('laboratory-confirmation.generation_queue', 'laboratory-preparation'));
                }

                return;
            }

            GenerateLaboratoryPurchasePreparationSummaryJob::dispatch(
                $laboratoryPurchase->id,
                null,
                $this->laboratoryPreparationSummaryService->deterministicV3SourceHash($laboratoryPurchase),
                GenerateLaboratoryPurchasePreparationSummaryJob::MODE_SHADOW_ONLY,
            )->afterCommit();
        } catch (Throwable $e) {
            Log::warning('[Laboratory Preparation AI] Failed to queue summary generation', [
                'purchase_id' => $laboratoryPurchase->id,
                ...(new \App\Services\LaboratoryPreparation\LaboratoryPreparationOperationalException('job', 'persistence'))->context(),
            ]);
        }
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
            'gda_status' => GdaOrderStatus::Pending,
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
        if (\App\Support\LocalCheckoutQa::enabled()) { return; }
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
