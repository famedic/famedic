<?php

namespace App\Services\Payments\Drivers;

use App\DTOs\ActiveCampaign\ActiveCampaignOperationResult;
use App\DTOs\Payments\PaymentAutomationContext;
use App\DTOs\Payments\PaymentAutomationResult;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sole ActiveCampaign bridge for the payment automation flow.
 * Evaluates ActiveCampaignOperationResult.success explicitly — never assumes success.
 */
class ActiveCampaignPaymentDriver
{
    public const TAG_PAYMENT_DECLINED = 'WA-PagoRechazado';

    public function __construct(
        private ActiveCampaignService $activeCampaign,
    ) {
    }

    public function handleApproved(PaymentAutomationContext $context): PaymentAutomationResult
    {
        $started = hrtime(true);
        $tagName = self::TAG_PAYMENT_DECLINED;

        try {
            $email = $this->resolveCustomerEmail($context);
            if ($email === null) {
                return $this->finish(
                    handler: 'handleApproved',
                    context: $context,
                    started: $started,
                    action: 'remove_tag',
                    tag: $tagName,
                    success: false,
                    executed: false,
                    automationsExecuted: false,
                    message: 'Approved automation skipped — customer email missing.',
                    error: 'missing_email',
                    reason: 'missing_email',
                    logLevel: 'warning',
                    logTitle: '[Payment Automation] [APPROVED] Missing email — no AC sync',
                );
            }

            $contactResult = $this->activeCampaign->getContactIdByEmailPublic($email);
            if (! $contactResult->success || ! $contactResult->contactId) {
                return $this->finishFromOperation(
                    handler: 'handleApproved',
                    context: $context,
                    started: $started,
                    action: 'remove_tag',
                    tag: $tagName,
                    operation: $contactResult,
                    automationsExecuted: false,
                    message: 'Approved automation skipped — AC contact resolve failed.',
                    reason: $contactResult->error ?? 'contact_not_found',
                    logLevel: 'warning',
                    logTitle: '[Payment Automation] [APPROVED] Contact resolve unsuccessful',
                    email: $email,
                );
            }

            $contactId = $contactResult->contactId;

            $tagResult = $this->activeCampaign->getTagIdByName($tagName);
            if (! $tagResult->success || ! $tagResult->tagId) {
                return $this->finishFromOperation(
                    handler: 'handleApproved',
                    context: $context,
                    started: $started,
                    action: 'remove_tag',
                    tag: $tagName,
                    operation: $tagResult,
                    automationsExecuted: false,
                    message: 'Approved automation skipped — tag resolve failed.',
                    reason: $tagResult->error ?? 'tag_not_found',
                    contactId: $contactId,
                    logLevel: 'warning',
                    logTitle: '[Payment Automation] [APPROVED] Tag resolve unsuccessful',
                    email: $email,
                );
            }

            $tagId = $tagResult->tagId;

            $hasTagResult = $this->activeCampaign->contactHasTag($contactId, $tagId);
            if (! $hasTagResult->success) {
                return $this->finishFromOperation(
                    handler: 'handleApproved',
                    context: $context,
                    started: $started,
                    action: 'remove_tag',
                    tag: $tagName,
                    operation: $hasTagResult,
                    automationsExecuted: false,
                    message: 'Approved automation failed — could not verify contact tags.',
                    reason: $hasTagResult->error ?? 'check_contact_tag_failed',
                    contactId: $contactId,
                    logLevel: 'error',
                    logTitle: '[Payment Automation] [APPROVED] Tag check unsuccessful',
                    email: $email,
                );
            }

            $hasTag = (bool) data_get($hasTagResult->response, 'has_tag', false);
            if (! $hasTag) {
                return $this->finish(
                    handler: 'handleApproved',
                    context: $context,
                    started: $started,
                    action: 'already_removed',
                    tag: $tagName,
                    success: true,
                    executed: true,
                    automationsExecuted: true,
                    message: 'Approved automation — tag already absent (idempotent).',
                    contactId: $contactId,
                    operation: $hasTagResult,
                    logLevel: 'info',
                    logTitle: '[Payment Automation] [APPROVED] Tag already removed',
                    email: $email,
                );
            }

            $removeResult = $this->activeCampaign->removeTagFromContact($contactId, $tagId);
            if (! $removeResult->success) {
                return $this->finishFromOperation(
                    handler: 'handleApproved',
                    context: $context,
                    started: $started,
                    action: 'remove_tag',
                    tag: $tagName,
                    operation: $removeResult,
                    automationsExecuted: false,
                    message: 'Approved automation failed — tag remove unsuccessful.',
                    reason: $removeResult->error ?? 'remove_tag_failed',
                    contactId: $contactId,
                    logLevel: 'error',
                    logTitle: '[Payment Automation] [APPROVED] Tag remove unsuccessful',
                    email: $email,
                );
            }

            return $this->finishFromOperation(
                handler: 'handleApproved',
                context: $context,
                started: $started,
                action: $removeResult->operation === 'already_removed' ? 'already_removed' : 'tag_removed',
                tag: $tagName,
                operation: $removeResult,
                automationsExecuted: true,
                message: 'Approved automation — declined tag removed.',
                contactId: $contactId,
                logLevel: 'info',
                logTitle: '[Payment Automation] [APPROVED] Tag removed',
                email: $email,
            );
        } catch (Throwable $e) {
            return $this->finish(
                handler: 'handleApproved',
                context: $context,
                started: $started,
                action: 'remove_tag',
                tag: $tagName,
                success: false,
                executed: true,
                automationsExecuted: false,
                message: 'Approved automation failed during ActiveCampaign sync.',
                error: $e->getMessage(),
                reason: 'exception',
                logLevel: 'error',
                logTitle: '[Payment Automation] [APPROVED] ActiveCampaign exception',
            );
        }
    }

    public function handleDeclined(PaymentAutomationContext $context): PaymentAutomationResult
    {
        $started = hrtime(true);
        $tagName = self::TAG_PAYMENT_DECLINED;

        try {
            $email = $this->resolveCustomerEmail($context);
            if ($email === null) {
                return $this->finish(
                    handler: 'handleDeclined',
                    context: $context,
                    started: $started,
                    action: 'add_tag',
                    tag: $tagName,
                    success: false,
                    executed: false,
                    automationsExecuted: false,
                    message: 'Declined automation skipped — customer email missing.',
                    error: 'missing_email',
                    reason: 'missing_email',
                    logLevel: 'warning',
                    logTitle: '[Payment Automation] [DECLINED] Missing email — no AC sync',
                );
            }

            $contactResult = $this->activeCampaign->getContactIdByEmailPublic($email);
            if (! $contactResult->success || ! $contactResult->contactId) {
                return $this->finishFromOperation(
                    handler: 'handleDeclined',
                    context: $context,
                    started: $started,
                    action: 'add_tag',
                    tag: $tagName,
                    operation: $contactResult,
                    automationsExecuted: false,
                    message: 'Declined automation skipped — AC contact resolve failed.',
                    reason: $contactResult->error ?? 'contact_not_found',
                    logLevel: 'warning',
                    logTitle: '[Payment Automation] [DECLINED] Contact resolve unsuccessful',
                    email: $email,
                );
            }

            $contactId = $contactResult->contactId;

            $tagResult = $this->activeCampaign->getTagIdByName($tagName);
            if (! $tagResult->success || ! $tagResult->tagId) {
                return $this->finishFromOperation(
                    handler: 'handleDeclined',
                    context: $context,
                    started: $started,
                    action: 'add_tag',
                    tag: $tagName,
                    operation: $tagResult,
                    automationsExecuted: false,
                    message: 'Declined automation skipped — tag resolve failed.',
                    reason: $tagResult->error ?? 'tag_not_found',
                    contactId: $contactId,
                    logLevel: 'warning',
                    logTitle: '[Payment Automation] [DECLINED] Tag resolve unsuccessful',
                    email: $email,
                );
            }

            $tagId = $tagResult->tagId;

            $hasTagResult = $this->activeCampaign->contactHasTag($contactId, $tagId);
            if (! $hasTagResult->success) {
                return $this->finishFromOperation(
                    handler: 'handleDeclined',
                    context: $context,
                    started: $started,
                    action: 'add_tag',
                    tag: $tagName,
                    operation: $hasTagResult,
                    automationsExecuted: false,
                    message: 'Declined automation failed — could not verify contact tags.',
                    reason: $hasTagResult->error ?? 'check_contact_tag_failed',
                    contactId: $contactId,
                    logLevel: 'error',
                    logTitle: '[Payment Automation] [DECLINED] Tag check unsuccessful',
                    email: $email,
                );
            }

            $hasTag = (bool) data_get($hasTagResult->response, 'has_tag', false);
            if ($hasTag) {
                return $this->finish(
                    handler: 'handleDeclined',
                    context: $context,
                    started: $started,
                    action: 'already_present',
                    tag: $tagName,
                    success: true,
                    executed: true,
                    automationsExecuted: true,
                    message: 'Declined automation — tag already present (idempotent).',
                    contactId: $contactId,
                    operation: $hasTagResult,
                    logLevel: 'info',
                    logTitle: '[Payment Automation] [DECLINED] Tag already present — skip duplicate',
                    email: $email,
                );
            }

            $addResult = $this->activeCampaign->addTagToContact($contactId, $tagId);
            if (! $addResult->success) {
                return $this->finishFromOperation(
                    handler: 'handleDeclined',
                    context: $context,
                    started: $started,
                    action: 'add_tag',
                    tag: $tagName,
                    operation: $addResult,
                    automationsExecuted: false,
                    message: 'Declined automation failed — tag add unsuccessful.',
                    reason: $addResult->error ?? 'add_tag_failed',
                    contactId: $contactId,
                    logLevel: 'error',
                    logTitle: '[Payment Automation] [DECLINED] Tag add unsuccessful',
                    email: $email,
                );
            }

            return $this->finishFromOperation(
                handler: 'handleDeclined',
                context: $context,
                started: $started,
                action: 'tag_added',
                tag: $tagName,
                operation: $addResult,
                automationsExecuted: true,
                message: 'Declined automation — declined tag added.',
                contactId: $contactId,
                logLevel: 'info',
                logTitle: '[Payment Automation] [DECLINED] Tag added',
                email: $email,
            );
        } catch (Throwable $e) {
            return $this->finish(
                handler: 'handleDeclined',
                context: $context,
                started: $started,
                action: 'add_tag',
                tag: $tagName,
                success: false,
                executed: true,
                automationsExecuted: false,
                message: 'Declined automation failed during ActiveCampaign sync.',
                error: $e->getMessage(),
                reason: 'exception',
                logLevel: 'error',
                logTitle: '[Payment Automation] [DECLINED] ActiveCampaign exception',
            );
        }
    }

    public function handleError(PaymentAutomationContext $context): PaymentAutomationResult
    {
        $ac = PaymentAutomationResult::emptyActiveCampaignPayload('technical_error');
        $ac['executed'] = false;
        $ac['success'] = null;
        $ac['action'] = 'skipped';
        $ac['message'] = 'No ActiveCampaign sync for technical payment errors.';
        $ac['duration_ms'] = 0;

        Log::info('[Payment Automation] [ERROR] No AC sync', [
            'customer_id' => $context->customer?->id,
            'reference' => $context->reference,
            'reason' => 'technical_error',
            'processor_code' => $context->attempt->processor_code,
            'processor_message' => $context->attempt->processor_message,
            'activecampaign' => $ac,
        ]);

        return new PaymentAutomationResult(
            handler: 'handleError',
            status: 'skipped',
            handled: true,
            message: 'Technical payment error — ActiveCampaign sync skipped.',
            context: $context->toArray(),
            automationsExecuted: false,
            activecampaign: $ac,
        );
    }

    private function resolveCustomerEmail(PaymentAutomationContext $context): ?string
    {
        $customer = $context->customer;
        if (! $customer) {
            return null;
        }

        $customer->loadMissing('user');
        $email = $customer->user?->email;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
    }

    private function finishFromOperation(
        string $handler,
        PaymentAutomationContext $context,
        int $started,
        string $action,
        string $tag,
        ActiveCampaignOperationResult $operation,
        bool $automationsExecuted,
        string $message,
        ?string $reason = null,
        ?int $contactId = null,
        string $logLevel = 'info',
        string $logTitle = '[Payment Automation]',
        ?string $email = null,
    ): PaymentAutomationResult {
        return $this->finish(
            handler: $handler,
            context: $context,
            started: $started,
            action: $action,
            tag: $tag,
            success: $operation->success,
            executed: true,
            automationsExecuted: $automationsExecuted && $operation->success,
            message: $message,
            error: $operation->success ? null : ($operation->error ?? 'operation_failed'),
            reason: $reason ?? $operation->error,
            contactId: $contactId ?? $operation->contactId,
            operation: $operation,
            logLevel: $logLevel,
            logTitle: $logTitle,
            email: $email,
        );
    }

    private function finish(
        string $handler,
        PaymentAutomationContext $context,
        int $started,
        string $action,
        string $tag,
        ?bool $success,
        bool $executed,
        bool $automationsExecuted,
        string $message,
        ?string $error = null,
        ?string $reason = null,
        ?int $contactId = null,
        ?ActiveCampaignOperationResult $operation = null,
        string $logLevel = 'info',
        string $logTitle = '[Payment Automation]',
        ?string $email = null,
    ): PaymentAutomationResult {
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $ac = [
            'executed' => $executed,
            'success' => $success,
            'action' => $action,
            'tag' => $tag,
            'contact_id' => $contactId,
            'message' => $message,
            'error' => $error,
            'duration_ms' => $durationMs,
            'reason' => $reason,
            'operation' => $operation?->toArray(),
            'http_status' => $operation?->httpStatus,
            'retryable' => $operation?->retryable,
        ];

        $logPayload = [
            'customer_id' => $context->customer?->id,
            'email' => $email,
            'reference' => $context->reference,
            'tag' => $tag,
            'contact_id' => $contactId,
            'duration_ms' => $durationMs,
            'success' => $success,
            'http_status' => $operation?->httpStatus,
            'retryable' => $operation?->retryable,
            'activecampaign' => $ac,
        ];

        match ($logLevel) {
            'warning' => Log::warning($logTitle, $logPayload),
            'error' => Log::error($logTitle, $logPayload),
            default => Log::info($logTitle, $logPayload),
        };

        return new PaymentAutomationResult(
            handler: $handler,
            status: $context->status,
            handled: true,
            message: $message,
            context: $context->toArray(),
            automationsExecuted: $automationsExecuted,
            activecampaign: $ac,
        );
    }
}
