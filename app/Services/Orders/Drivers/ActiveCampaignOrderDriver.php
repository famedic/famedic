<?php

namespace App\Services\Orders\Drivers;

use App\DTOs\Orders\OrderAutomationContext;
use App\DTOs\Orders\OrderAutomationResult;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sole ActiveCampaign bridge for completed-order automations.
 * Evaluates ActiveCampaignOperationResult.success explicitly — never assumes success.
 */
class ActiveCampaignOrderDriver
{
    public function __construct(
        private ActiveCampaignService $activeCampaign,
    ) {
    }

    public function handleLaboratoryOrder(OrderAutomationContext $context): OrderAutomationResult
    {
        $started = hrtime(true);
        $operations = [];

        Log::info('[ActiveCampaign Order Driver] handleLaboratoryOrder started', [
            'context' => $context->toArray(),
        ]);

        $purchase = $context->laboratoryPurchase;
        if (! $purchase instanceof LaboratoryPurchase) {
            return $this->finish(
                handler: 'handleLaboratoryOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: false,
                executed: false,
                message: 'Laboratory purchase missing from context.',
                error: 'missing_laboratory_purchase',
                operation: 'laboratoryPurchase',
            );
        }

        try {
            $purchase->loadMissing(['customer.user', 'laboratoryPurchaseItems']);

            $labResult = $this->activeCampaign->laboratoryPurchase($purchase);
            $operations[] = $labResult->toArray();

            Log::info('[ActiveCampaign Order Sync] laboratoryPurchase', $labResult->toArray());

            if (! $labResult->success) {
                return $this->finish(
                    handler: 'handleLaboratoryOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Laboratory order sync failed on laboratoryPurchase.',
                    error: $labResult->error,
                    operation: 'laboratoryPurchase',
                    retryable: $labResult->retryable,
                    contactId: $labResult->contactId,
                );
            }

            $email = $purchase->customer->user->email ?? null;
            if (! is_string($email) || trim($email) === '') {
                return $this->finish(
                    handler: 'handleLaboratoryOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Laboratory order sync incomplete — missing email for completedPurchase.',
                    error: 'missing_email',
                    operation: 'completedPurchase',
                    retryable: false,
                    contactId: $labResult->contactId,
                );
            }

            $products = $purchase->laboratoryPurchaseItems->map(fn ($item) => [
                'name' => $item->name,
                'price' => $item->price_cents / 100,
                'quantity' => 1,
                'category' => 'Laboratorio',
            ])->toArray();

            $completedResult = $this->activeCampaign->completedPurchase(
                email: trim($email),
                externalId: 'COMPLETE-LAB-'.$purchase->id,
                total: ((int) $purchase->total_cents) / 100,
                products: $products,
                category: 'Laboratorio',
            );
            $operations[] = $completedResult->toArray();

            Log::info('[ActiveCampaign Order Sync] completedPurchase', $completedResult->toArray());

            if (! $completedResult->success) {
                return $this->finish(
                    handler: 'handleLaboratoryOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Laboratory order sync failed on completedPurchase.',
                    error: $completedResult->error,
                    operation: 'completedPurchase',
                    retryable: $completedResult->retryable,
                    contactId: $labResult->contactId,
                );
            }

            return $this->finish(
                handler: 'handleLaboratoryOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: true,
                executed: true,
                message: 'Laboratory order synced to ActiveCampaign.',
                error: null,
                operation: 'laboratoryPurchase+completedPurchase',
                retryable: false,
                contactId: $labResult->contactId,
            );
        } catch (Throwable $e) {
            Log::error('[ActiveCampaign Order Driver] handleLaboratoryOrder exception', [
                'error' => $e->getMessage(),
                'context' => $context->toArray(),
            ]);

            return $this->finish(
                handler: 'handleLaboratoryOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: false,
                executed: true,
                message: 'Laboratory order sync raised an exception.',
                error: $e->getMessage(),
                operation: 'laboratoryPurchase',
                retryable: true,
            );
        }
    }

    public function handlePharmacyOrder(OrderAutomationContext $context): OrderAutomationResult
    {
        $started = hrtime(true);
        $operations = [];

        Log::info('[ActiveCampaign Order Driver] handlePharmacyOrder started', [
            'context' => $context->toArray(),
        ]);

        $purchase = $context->pharmacyOrder;
        if (! $purchase instanceof OnlinePharmacyPurchase) {
            return $this->finish(
                handler: 'handlePharmacyOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: false,
                executed: false,
                message: 'Pharmacy order missing from context.',
                error: 'missing_pharmacy_order',
                operation: 'pharmacyPurchase',
            );
        }

        try {
            $purchase->loadMissing(['customer.user', 'onlinePharmacyPurchaseItems']);

            $pharmacyResult = $this->activeCampaign->pharmacyPurchase($purchase);
            $operations[] = $pharmacyResult->toArray();

            Log::info('[ActiveCampaign Order Sync] pharmacyPurchase', $pharmacyResult->toArray());

            if (! $pharmacyResult->success) {
                return $this->finish(
                    handler: 'handlePharmacyOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Pharmacy order sync failed on pharmacyPurchase.',
                    error: $pharmacyResult->error,
                    operation: 'pharmacyPurchase',
                    retryable: $pharmacyResult->retryable,
                    contactId: $pharmacyResult->contactId,
                );
            }

            $email = $purchase->customer->user->email ?? null;
            if (! is_string($email) || trim($email) === '') {
                return $this->finish(
                    handler: 'handlePharmacyOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Pharmacy order sync incomplete — missing email for completedPurchase.',
                    error: 'missing_email',
                    operation: 'completedPurchase',
                    retryable: false,
                    contactId: $pharmacyResult->contactId,
                );
            }

            $products = $purchase->onlinePharmacyPurchaseItems->map(fn ($item) => [
                'name' => $item->name,
                'price' => $item->price_cents / 100,
                'quantity' => 1,
                'category' => 'Farmacia',
            ])->toArray();

            $completedResult = $this->activeCampaign->completedPurchase(
                email: trim($email),
                externalId: 'COMPLETE-PHARM-'.$purchase->id,
                total: ((int) $purchase->total_cents) / 100,
                products: $products,
                category: 'Farmacia',
            );
            $operations[] = $completedResult->toArray();

            Log::info('[ActiveCampaign Order Sync] completedPurchase', $completedResult->toArray());

            if (! $completedResult->success) {
                return $this->finish(
                    handler: 'handlePharmacyOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Pharmacy order sync failed on completedPurchase.',
                    error: $completedResult->error,
                    operation: 'completedPurchase',
                    retryable: $completedResult->retryable,
                    contactId: $pharmacyResult->contactId,
                );
            }

            return $this->finish(
                handler: 'handlePharmacyOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: true,
                executed: true,
                message: 'Pharmacy order synced to ActiveCampaign.',
                error: null,
                operation: 'pharmacyPurchase+completedPurchase',
                retryable: false,
                contactId: $pharmacyResult->contactId,
            );
        } catch (Throwable $e) {
            Log::error('[ActiveCampaign Order Driver] handlePharmacyOrder exception', [
                'error' => $e->getMessage(),
                'context' => $context->toArray(),
            ]);

            return $this->finish(
                handler: 'handlePharmacyOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: false,
                executed: true,
                message: 'Pharmacy order sync raised an exception.',
                error: $e->getMessage(),
                operation: 'pharmacyPurchase',
                retryable: true,
            );
        }
    }

    public function handleMembershipOrder(OrderAutomationContext $context): OrderAutomationResult
    {
        $started = hrtime(true);
        $operations = [];

        Log::info('[ActiveCampaign Order Driver] handleMembershipOrder started', [
            'context' => $context->toArray(),
        ]);

        $membership = $context->membership;
        if (! $membership instanceof MedicalAttentionSubscription) {
            return $this->finish(
                handler: 'handleMembershipOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: false,
                executed: false,
                message: 'Membership missing from context.',
                error: 'missing_membership',
                operation: 'activateMembership',
            );
        }

        try {
            $membership->loadMissing(['customer.user']);

            $activateResult = $this->activeCampaign->activateMembership($membership);
            $operations[] = $activateResult->toArray();

            Log::info('[ActiveCampaign Order Sync] activateMembership', $activateResult->toArray());

            if (! $activateResult->success) {
                return $this->finish(
                    handler: 'handleMembershipOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Membership order sync failed on activateMembership.',
                    error: $activateResult->error,
                    operation: 'activateMembership',
                    retryable: $activateResult->retryable,
                    contactId: $activateResult->contactId,
                );
            }

            $email = $membership->customer->user->email ?? null;
            if (! is_string($email) || trim($email) === '') {
                return $this->finish(
                    handler: 'handleMembershipOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Membership order sync incomplete — missing email for completedPurchase.',
                    error: 'missing_email',
                    operation: 'completedPurchase',
                    retryable: false,
                    contactId: $activateResult->contactId,
                );
            }

            $total = $context->amountCents / 100;
            $products = [[
                'name' => 'Membresía Atención Médica',
                'price' => $total,
                'quantity' => 1,
                'category' => 'Membresía',
            ]];

            $completedResult = $this->activeCampaign->completedPurchase(
                email: trim($email),
                externalId: 'COMPLETE-MEM-'.$membership->id,
                total: $total,
                products: $products,
                category: 'Membresía',
            );
            $operations[] = $completedResult->toArray();

            Log::info('[ActiveCampaign Order Sync] completedPurchase', $completedResult->toArray());

            if (! $completedResult->success) {
                return $this->finish(
                    handler: 'handleMembershipOrder',
                    context: $context,
                    started: $started,
                    operations: $operations,
                    success: false,
                    executed: true,
                    message: 'Membership order sync failed on completedPurchase.',
                    error: $completedResult->error,
                    operation: 'completedPurchase',
                    retryable: $completedResult->retryable,
                    contactId: $activateResult->contactId,
                );
            }

            return $this->finish(
                handler: 'handleMembershipOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: true,
                executed: true,
                message: 'Membership order synced to ActiveCampaign.',
                error: null,
                operation: 'activateMembership+completedPurchase',
                retryable: false,
                contactId: $activateResult->contactId,
            );
        } catch (Throwable $e) {
            Log::error('[ActiveCampaign Order Driver] handleMembershipOrder exception', [
                'error' => $e->getMessage(),
                'context' => $context->toArray(),
            ]);

            return $this->finish(
                handler: 'handleMembershipOrder',
                context: $context,
                started: $started,
                operations: $operations,
                success: false,
                executed: true,
                message: 'Membership order sync raised an exception.',
                error: $e->getMessage(),
                operation: 'activateMembership',
                retryable: true,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function finish(
        string $handler,
        OrderAutomationContext $context,
        int $started,
        array $operations,
        bool $success,
        bool $executed,
        string $message,
        ?string $error,
        string $operation,
        bool $retryable = false,
        ?int $contactId = null,
    ): OrderAutomationResult {
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $anyRetryable = $retryable || collect($operations)->contains(
            fn (array $op) => (bool) ($op['retryable'] ?? false)
        );

        $activecampaign = [
            'executed' => $executed,
            'success' => $success,
            'operation' => $operation,
            'duration_ms' => $durationMs,
            'error' => $error,
            'retryable' => $success ? false : $anyRetryable,
            'contact_id' => $contactId,
            'operations' => $operations,
        ];

        Log::info('[Order Automation] '.$handler, [
            'success' => $success,
            'operation' => $operation,
            'duration_ms' => $durationMs,
            'error' => $error,
            'retryable' => $activecampaign['retryable'],
            'operations_count' => count($operations),
            'context' => $context->toArray(),
            'activecampaign' => $activecampaign,
        ]);

        return new OrderAutomationResult(
            handler: $handler,
            status: $success ? 'synced' : 'failed',
            handled: true,
            message: $message,
            context: $context->toArray(),
            automationsExecuted: $executed && $success,
            activecampaign: $activecampaign,
        );
    }
}
