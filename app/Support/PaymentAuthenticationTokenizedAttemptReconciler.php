<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Services\EfevooPay\MockEfevooPayGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentAuthenticationTokenizedAttemptReconciler
{
    public function __construct(
        private PaymentAuthenticationLocalPaymentMethodPersistence $persistence,
        private EfevooTokenGatewayOriginPromotion $originPromotion
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(int $attemptId, string $targetOrigin, bool $apply = false): array
    {
        $targetOrigin = strtolower(trim($targetOrigin));

        if (! in_array($targetOrigin, EfevooTokenGatewayOriginPolicy::allowedOrigins(), true)) {
            return $this->blockedResult($attemptId, $targetOrigin, 'invalid_target_origin', $apply);
        }

        $attempt = PaymentAuthenticationAttempt::query()
            ->with(['customer', 'efevoo3dsSession', 'recoveryContext', 'events'])
            ->find($attemptId);

        if (! $attempt) {
            return $this->blockedResult($attemptId, $targetOrigin, 'attempt_not_found', $apply);
        }

        $analysis = $this->analyzeAttempt($attempt, $targetOrigin);

        if (($analysis['blocked'] ?? false) === true) {
            return array_merge($analysis, [
                'mode' => $apply ? 'apply' : 'dry-run',
                'changes_applied' => 0,
            ]);
        }

        if (! $apply) {
            return array_merge($analysis, [
                'mode' => 'dry-run',
                'changes_applied' => 0,
            ]);
        }

        return $this->applyPromotion($attempt, $targetOrigin, $analysis);
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeAttempt(PaymentAuthenticationAttempt $attempt, string $targetOrigin): array
    {
        $customer = $attempt->customer;

        if (! $customer) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'missing_customer');
        }

        $session = $attempt->efevoo3dsSession;

        if (! $session) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'missing_session');
        }

        if (! filled($session->efevoo_token_id)) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'missing_session_token_reference');
        }

        $token = EfevooToken::withTrashed()->find($session->efevoo_token_id);

        if (! $token) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'token_not_found');
        }

        $tokenCardCallCount = (int) $attempt->tokenization_call_count;

        if ($tokenCardCallCount !== 1) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'token_card_call_count_invalid', [
                'token_card_call_count' => $tokenCardCallCount,
            ]);
        }

        if (! $this->hasGetStatusApproved($attempt)) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'get_status_not_approved');
        }

        $tokenizationEvent = $this->latestTokenizationSucceededEvent($attempt);

        if ($tokenizationEvent === null) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'tokenization_not_succeeded');
        }

        if ($this->hasAmbiguousOrTerminalFailureAfterTokenization($attempt, $tokenizationEvent)) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'ambiguous_or_terminal_after_tokenization');
        }

        $tokenUsuarioPresent = $this->tokenUsuarioPresent($attempt, $tokenizationEvent, $token);

        if (! $tokenUsuarioPresent) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'token_usuario_absent');
        }

        if ((int) $token->customer_id !== (int) $customer->id) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'customer_mismatch');
        }

        if (! $this->last4Matches($attempt, $session, $token)) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'last4_mismatch');
        }

        $visibleBefore = $this->persistence->isListableForCustomerInGateway($token, $customer, $targetOrigin);

        if ($visibleBefore) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'already_visible_in_target', [
                'current_token_origin' => EfevooTokenGatewayOriginPolicy::resolvedOrigin($token),
                'visible_before' => true,
            ]);
        }

        $attemptOrigin = $this->resolveAttemptGatewayOrigin($attempt, $session, $tokenizationEvent, $token);

        if ($attemptOrigin === null) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'attempt_origin_ambiguous');
        }

        $currentTokenOrigin = EfevooTokenGatewayOriginPolicy::resolvedOrigin($token);

        if ($targetOrigin === EfevooPayGatewayMode::LIVE) {
            if ($attemptOrigin !== EfevooPayGatewayMode::LIVE) {
                return $this->blockedCandidate($attempt, $targetOrigin, 'attempt_origin_not_live', [
                    'attempt_origin' => $attemptOrigin,
                    'current_token_origin' => $currentTokenOrigin,
                ]);
            }

            if ($currentTokenOrigin !== EfevooPayGatewayMode::MOCK) {
                return $this->blockedCandidate($attempt, $targetOrigin, 'token_origin_not_mock', [
                    'attempt_origin' => $attemptOrigin,
                    'current_token_origin' => $currentTokenOrigin,
                ]);
            }
        }

        $ownershipConflicts = $this->countOwnershipConflicts($token);
        $referenceConflicts = $this->countReferenceConflicts($token, $customer);

        if ($ownershipConflicts > 0 || $referenceConflicts > 0) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'blocked_requires_manual_review', [
                'attempt_origin' => $attemptOrigin,
                'current_token_origin' => $currentTokenOrigin,
                'ownership_conflicts' => $ownershipConflicts,
                'reference_conflicts' => $referenceConflicts,
                'proposed_action' => 'blocked',
                'visible_before' => false,
            ]);
        }

        $proposedAction = $this->resolveProposedAction($token, $customer, $targetOrigin, $attemptOrigin, $currentTokenOrigin);

        if ($proposedAction === 'blocked') {
            return $this->blockedCandidate($attempt, $targetOrigin, 'blocked_requires_manual_review', [
                'attempt_origin' => $attemptOrigin,
                'current_token_origin' => $currentTokenOrigin,
                'ownership_conflicts' => $ownershipConflicts,
                'reference_conflicts' => $referenceConflicts,
                'proposed_action' => 'blocked',
            ]);
        }

        if (! $this->originPromotion->isTransitionAllowed($currentTokenOrigin, $targetOrigin, [
            'source' => 'reconcile-tokenized-attempts',
            'attempt_id' => $attempt->id,
        ])) {
            return $this->blockedCandidate($attempt, $targetOrigin, 'promotion_not_allowed', [
                'attempt_origin' => $attemptOrigin,
                'current_token_origin' => $currentTokenOrigin,
                'proposed_action' => 'blocked',
            ]);
        }

        return $this->safeCandidate($attempt, $session, $token, $customer, $targetOrigin, [
            'attempt_origin' => $attemptOrigin,
            'current_token_origin' => $currentTokenOrigin,
            'get_status_approved' => true,
            'token_card_call_count' => $tokenCardCallCount,
            'tokenization_succeeded' => true,
            'token_usuario_present' => true,
            'visible_before' => false,
            'ownership_conflicts' => $ownershipConflicts,
            'reference_conflicts' => $referenceConflicts,
            'proposed_action' => $proposedAction,
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function applyPromotion(
        PaymentAuthenticationAttempt $attempt,
        string $targetOrigin,
        array $analysis
    ): array {
        $lockKey = 'efevoo:reconcile-tokenized-attempt:'.$attempt->id;

        return Cache::lock($lockKey, 30)->block(10, function () use ($attempt, $targetOrigin, $analysis) {
            $freshAttempt = PaymentAuthenticationAttempt::query()
                ->with(['customer', 'efevoo3dsSession', 'events'])
                ->find($attempt->id);

            if (! $freshAttempt) {
                return $this->blockedResult($attempt->id, $targetOrigin, 'attempt_not_found', true);
            }

            $freshAnalysis = $this->analyzeAttempt($freshAttempt, $targetOrigin);

            if (($freshAnalysis['blocked'] ?? false) === true) {
                return array_merge($freshAnalysis, [
                    'mode' => 'apply',
                    'changes_applied' => 0,
                ]);
            }

            if (($freshAnalysis['proposed_action'] ?? '') !== ($analysis['proposed_action'] ?? '')) {
                return $this->blockedResult($attempt->id, $targetOrigin, 'state_changed_since_dry_run', true);
            }

            $tokenId = (int) ($freshAnalysis['efevoo_token_id'] ?? 0);
            $customerId = (int) ($freshAnalysis['customer_id'] ?? 0);
            $token = EfevooToken::query()->find($tokenId);
            $customer = Customer::query()->find($customerId);

            if (! $token || ! $customer) {
                return $this->blockedResult($attempt->id, $targetOrigin, 'token_or_customer_missing', true);
            }

            try {
                DB::transaction(function () use ($token, $targetOrigin, $freshAttempt, $customer) {
                    $lockedToken = EfevooToken::query()->whereKey($token->id)->lockForUpdate()->first();

                    if (! $lockedToken) {
                        throw new \RuntimeException('token_lock_failed');
                    }

                    $currentOrigin = EfevooTokenGatewayOriginPolicy::resolvedOrigin($lockedToken);

                    if ($currentOrigin !== $targetOrigin) {
                        $this->originPromotion->promote($lockedToken, $targetOrigin, [
                            'source' => 'reconcile-tokenized-attempts',
                            'attempt_id' => $freshAttempt->id,
                        ]);
                    }

                    $lockedToken = $lockedToken->fresh();

                    if (! $this->persistence->isListableForCustomerInGateway($lockedToken, $customer, $targetOrigin)) {
                        throw new \RuntimeException('visibility_not_confirmed');
                    }
                });
            } catch (\Throwable $e) {
                return array_merge($freshAnalysis, [
                    'mode' => 'apply',
                    'blocked' => true,
                    'block_reason' => $e->getMessage(),
                    'changes_applied' => 0,
                    'proposed_action' => 'blocked',
                ]);
            }

            $token = EfevooToken::query()->find($tokenId);
            $visibleAfter = $token
                && $this->persistence->isListableForCustomerInGateway($token, $customer, $targetOrigin);

            return array_merge($freshAnalysis, [
                'mode' => 'apply',
                'visible_after' => $visibleAfter,
                'changes_applied' => $visibleAfter ? 1 : 0,
                'blocked' => ! $visibleAfter,
                'block_reason' => $visibleAfter ? null : 'visibility_not_confirmed',
            ]);
        });
    }

    private function hasGetStatusApproved(PaymentAuthenticationAttempt $attempt): bool
    {
        return $attempt->events
            ->contains(fn (PaymentAuthenticationAttemptEvent $event) => $event->event_type === PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value);
    }

    private function latestTokenizationSucceededEvent(PaymentAuthenticationAttempt $attempt): ?PaymentAuthenticationAttemptEvent
    {
        return $attempt->events
            ->filter(fn (PaymentAuthenticationAttemptEvent $event) => in_array($event->event_type, [
                PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded->value,
                PaymentAuthenticationAttemptEventType::TokenizationSucceeded->value,
            ], true))
            ->sortByDesc('id')
            ->first();
    }

    private function hasAmbiguousOrTerminalFailureAfterTokenization(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationAttemptEvent $tokenizationEvent
    ): bool {
        $blockedTypes = [
            PaymentAuthenticationAttemptEventType::TokenizationRequestFailed->value,
            PaymentAuthenticationAttemptEventType::TokenizationRequestTimeout->value,
            PaymentAuthenticationAttemptEventType::TokenizationFailed->value,
            PaymentAuthenticationAttemptEventType::TokenizationConfirmationPending->value,
            PaymentAuthenticationAttemptEventType::ProviderConfirmationPending->value,
        ];

        $hasBlockedAfter = $attempt->events
            ->filter(fn (PaymentAuthenticationAttemptEvent $event) => $event->id > $tokenizationEvent->id)
            ->contains(fn (PaymentAuthenticationAttemptEvent $event) => in_array($event->event_type, $blockedTypes, true));

        if ($hasBlockedAfter) {
            return true;
        }

        return in_array($attempt->status, [
            PaymentAuthenticationAttemptStatus::Declined->value,
            PaymentAuthenticationAttemptStatus::TechnicalError->value,
            PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
        ], true);
    }

    private function tokenUsuarioPresent(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationAttemptEvent $tokenizationEvent,
        EfevooToken $token
    ): bool {
        $metadata = is_array($tokenizationEvent->metadata) ? $tokenizationEvent->metadata : [];

        if (array_key_exists('token_usuario_present', $metadata)) {
            return $metadata['token_usuario_present'] === true;
        }

        if ($attempt->events->contains(fn (PaymentAuthenticationAttemptEvent $event) => $event->event_type === PaymentAuthenticationAttemptEventType::ExistingTokenReused->value)) {
            return filled($token->card_token);
        }

        if (($metadata['external_tokenization_attempted'] ?? false) === true) {
            return filled($token->card_token);
        }

        return false;
    }

    private function last4Matches(
        PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session,
        EfevooToken $token
    ): bool {
        $values = array_values(array_filter([
            $session->card_last_four,
            $token->card_last_four,
        ], fn ($value) => is_string($value) && $value !== ''));

        if ($values === []) {
            return true;
        }

        return count(array_unique($values)) === 1;
    }

    private function resolveAttemptGatewayOrigin(
        PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session,
        PaymentAuthenticationAttemptEvent $tokenizationEvent,
        EfevooToken $token
    ): ?string {
        if (is_string($session->order_id)
            && str_starts_with($session->order_id, MockEfevooPayGateway::MOCK_3DS_ORDER_PREFIX)) {
            return EfevooPayGatewayMode::MOCK;
        }

        $metadata = is_array($tokenizationEvent->metadata) ? $tokenizationEvent->metadata : [];

        if (($metadata['external_tokenization_attempted'] ?? null) === false) {
            return EfevooPayGatewayMode::MOCK;
        }

        if (($metadata['external_tokenization_attempted'] ?? null) !== true) {
            if ($attempt->events->contains(fn (PaymentAuthenticationAttemptEvent $event) => $event->event_type === PaymentAuthenticationAttemptEventType::ExistingTokenReused->value)) {
                return EfevooPayGatewayMode::MOCK;
            }

            return null;
        }

        if ($token->environment === 'production') {
            return EfevooPayGatewayMode::LIVE;
        }

        if ($token->environment === 'test') {
            return EfevooPayGatewayMode::TEST;
        }

        return null;
    }

    private function countOwnershipConflicts(EfevooToken $token): int
    {
        if (! filled($token->card_token)) {
            return 0;
        }

        return EfevooToken::withTrashed()
            ->where('card_token', $token->card_token)
            ->where('customer_id', '!=', $token->customer_id)
            ->count();
    }

    private function countReferenceConflicts(EfevooToken $token, Customer $customer): int
    {
        $foreignSessions = Efevoo3dsSession::query()
            ->where('efevoo_token_id', $token->id)
            ->where('customer_id', '!=', $customer->id)
            ->count();

        $foreignTransactions = EfevooTransaction::query()
            ->where('efevoo_token_id', $token->id)
            ->whereHas('token', fn ($query) => $query->where('customer_id', '!=', $customer->id))
            ->count();

        return $foreignSessions + $foreignTransactions;
    }

    private function resolveProposedAction(
        EfevooToken $token,
        Customer $customer,
        string $targetOrigin,
        string $attemptOrigin,
        string $currentTokenOrigin
    ): string {
        if ($attemptOrigin === EfevooPayGatewayMode::LIVE
            && $currentTokenOrigin === EfevooPayGatewayMode::MOCK
            && $targetOrigin === EfevooPayGatewayMode::LIVE) {
            $duplicateLive = EfevooToken::query()
                ->where('customer_id', $customer->id)
                ->where('card_last_four', $token->card_last_four)
                ->where('card_expiration', $token->card_expiration)
                ->whereKeyNot($token->id)
                ->where('metadata->gateway_origin', EfevooPayGatewayMode::LIVE)
                ->active()
                ->exists();

            return $duplicateLive ? 'blocked' : 'promote_gateway_origin';
        }

        return 'blocked';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function safeCandidate(
        PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session,
        EfevooToken $token,
        Customer $customer,
        string $targetOrigin,
        array $extra
    ): array {
        return array_merge([
            'blocked' => false,
            'attempt_id' => $attempt->id,
            'support_reference' => $attempt->support_reference,
            'customer_id' => $customer->id,
            'session_id' => $session->id,
            'provider_order_id' => $session->order_id ?? $attempt->provider_order_id,
            'last4' => $session->card_last_four ?? $token->card_last_four,
            'efevoo_token_id' => $token->id,
            'target_origin' => $targetOrigin,
            'provider_calls' => 0,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function blockedCandidate(
        PaymentAuthenticationAttempt $attempt,
        string $targetOrigin,
        string $blockReason,
        array $extra = []
    ): array {
        $session = $attempt->efevoo3dsSession;
        $token = $session && $session->efevoo_token_id
            ? EfevooToken::withTrashed()->find($session->efevoo_token_id)
            : null;

        $tokenizationEvent = $this->latestTokenizationSucceededEvent($attempt);
        $attemptOrigin = null;

        if ($token && $session && $tokenizationEvent) {
            $attemptOrigin = $this->resolveAttemptGatewayOrigin($attempt, $session, $tokenizationEvent, $token);
        }

        return array_merge([
            'blocked' => true,
            'block_reason' => $blockReason,
            'attempt_id' => $attempt->id,
            'support_reference' => $attempt->support_reference,
            'customer_id' => $attempt->customer_id,
            'session_id' => $session?->id,
            'provider_order_id' => $session?->order_id ?? $attempt->provider_order_id,
            'last4' => $session?->card_last_four ?? $token?->card_last_four,
            'efevoo_token_id' => $token?->id,
            'attempt_origin' => $attemptOrigin,
            'current_token_origin' => $token ? EfevooTokenGatewayOriginPolicy::resolvedOrigin($token) : null,
            'target_origin' => $targetOrigin,
            'get_status_approved' => $this->hasGetStatusApproved($attempt),
            'token_card_call_count' => (int) $attempt->tokenization_call_count,
            'tokenization_succeeded' => $this->latestTokenizationSucceededEvent($attempt) !== null,
            'token_usuario_present' => $token && $this->latestTokenizationSucceededEvent($attempt)
                ? $this->tokenUsuarioPresent($attempt, $this->latestTokenizationSucceededEvent($attempt), $token)
                : false,
            'visible_before' => $token && $attempt->customer
                ? $this->persistence->isListableForCustomerInGateway($token, $attempt->customer, $targetOrigin)
                : false,
            'ownership_conflicts' => $token ? $this->countOwnershipConflicts($token) : 0,
            'reference_conflicts' => $token && $attempt->customer
                ? $this->countReferenceConflicts($token, $attempt->customer)
                : 0,
            'proposed_action' => 'blocked',
            'provider_calls' => 0,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedResult(int $attemptId, string $targetOrigin, string $blockReason, bool $apply): array
    {
        return [
            'blocked' => true,
            'block_reason' => $blockReason,
            'mode' => $apply ? 'apply' : 'dry-run',
            'attempt_id' => $attemptId,
            'target_origin' => $targetOrigin,
            'proposed_action' => 'blocked',
            'provider_calls' => 0,
            'changes_applied' => 0,
            'ownership_conflicts' => 0,
            'reference_conflicts' => 0,
        ];
    }
}
