<?php

namespace App\Services\PaymentAuthenticationAttempts;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Support\PaymentAuthenticationEfevooPayAmounts;
use App\Support\PaymentAuthenticationEfevooPayMonetaryTracer;
use Illuminate\Support\Collection;

class PaymentAuthenticationEfevooPayOperationAnalyzer
{
    public function __construct(
        private PaymentAuthenticationEfevooPayMonetaryTracer $tracer
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(PaymentAuthenticationAttempt $attempt, ?Efevoo3dsSession $session = null): array
    {
        $session = $session ?? $attempt->efevoo3dsSession;

        $getLinkAmount = PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmount();
        $tokenAmount = PaymentAuthenticationEfevooPayAmounts::tokenizationVerificationAmount();

        $linkCalls = (int) $attempt->provider_link_call_count;
        $statusCalls = (int) $attempt->status_poll_call_count;
        $tokenCalls = (int) $attempt->tokenization_call_count;

        $linkOrderId = $session?->order_id ?? $attempt->provider_order_id;
        $tokenTransactionId = $this->latestProcessorTransactionId($attempt);

        $possibleDuplicate = $this->possibleDuplicateVerificationOperation(
            $linkCalls,
            $statusCalls,
            $tokenCalls,
            $linkOrderId,
            $tokenTransactionId,
            $attempt->events()->whereIn('event_type', [
                PaymentAuthenticationAttemptEventType::PossibleDuplicateVerificationOperation->value,
                PaymentAuthenticationAttemptEventType::DuplicateExternalCallBlocked->value,
            ])->exists(),
        );

        $authSucceeded = $attempt->events()
            ->where('event_type', PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value)
            ->exists();
        $tokenFailed = $session?->status === 'tokenization_failed'
            || $attempt->failure_category === \App\Support\EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED;

        return [
            'get_link' => [
                'call_count' => $linkCalls,
                'amount' => $getLinkAmount,
                'currency' => PaymentAuthenticationEfevooPayAmounts::currency(),
                'order_id_masked' => $this->tracer->maskIdentifier($linkOrderId),
                'result' => $this->operationResultLabel($linkCalls, $attempt->status, 'link'),
            ],
            'get_status' => [
                'call_count' => $statusCalls,
                'last_result' => $this->lastStatusResult($attempt),
                'excessive' => $statusCalls > (int) config('efevoopay.polling.max_external_status_polls', 60),
            ],
            'token_card' => [
                'call_count' => $tokenCalls,
                'amount' => $tokenCalls > 0 ? $tokenAmount : null,
                'currency' => $tokenCalls > 0 ? PaymentAuthenticationEfevooPayAmounts::currency() : null,
                'transaction_id_masked' => $this->tracer->maskIdentifier($tokenTransactionId),
                'result' => $this->tokenCardResultLabel($tokenCalls, $attempt->status, $tokenFailed),
                'confirmation_pending' => $attempt->status === PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
                'http_business_outcome' => $tokenFailed && $tokenCalls > 0 ? 'http_200_business_failed' : null,
                'provider_code' => $tokenFailed ? $attempt->provider_code : null,
                'provider_message' => $tokenFailed
                    ? \App\Support\EfevooPayLogSanitizer::providerMessage($attempt->provider_message)
                    : null,
            ],
            'authentication_3ds' => [
                'result' => $authSucceeded || in_array($session?->status, ['authenticated', 'completed', 'tokenization_failed'], true)
                    ? 'approved'
                    : ($statusCalls > 0 ? 'unknown' : 'not_called'),
            ],
            'card_storage' => [
                'result' => match (true) {
                    $attempt->status === PaymentAuthenticationAttemptStatus::Completed->value => 'saved',
                    $tokenFailed => 'failed',
                    $tokenCalls > 0 => 'attempted',
                    default => 'not_called',
                },
            ],
            'overall_result_label' => $authSucceeded && $tokenFailed
                ? 'Autenticación aprobada; tokenización fallida'
                : null,
            'possible_duplicate_verification_operation' => $possibleDuplicate,
            'disclaimer' => 'Una operación registrada no demuestra por sí misma un cargo confirmado.',
        ];
    }

    /**
     * @param  Collection<int, PaymentAuthenticationAttempt>  $attempts
     * @return array<int, bool>
     */
    public function batchPossibleDuplicateFlags(Collection $attempts): array
    {
        if ($attempts->isEmpty()) {
            return [];
        }

        $needsEventScan = $attempts->contains(function (PaymentAuthenticationAttempt $attempt): bool {
            $linkCalls = (int) $attempt->provider_link_call_count;
            $tokenCalls = (int) $attempt->tokenization_call_count;

            return $linkCalls > 1 || $tokenCalls > 1;
        });

        if (! $needsEventScan) {
            return $attempts->mapWithKeys(fn (PaymentAuthenticationAttempt $attempt) => [
                $attempt->id => false,
            ])->all();
        }

        $attemptIds = $attempts->pluck('id')->all();

        $attemptsWithDuplicateEvents = PaymentAuthenticationAttemptEvent::query()
            ->whereIn('payment_authentication_attempt_id', $attemptIds)
            ->whereIn('event_type', [
                PaymentAuthenticationAttemptEventType::PossibleDuplicateVerificationOperation->value,
                PaymentAuthenticationAttemptEventType::DuplicateExternalCallBlocked->value,
            ])
            ->distinct()
            ->pluck('payment_authentication_attempt_id')
            ->flip();

        $processorTransactionIds = $this->batchLatestProcessorTransactionIds($attemptIds);

        $flags = [];

        foreach ($attempts as $attempt) {
            $linkCalls = (int) $attempt->provider_link_call_count;
            $tokenCalls = (int) $attempt->tokenization_call_count;
            $linkOrderId = $attempt->relationLoaded('efevoo3dsSession')
                ? $attempt->efevoo3dsSession?->order_id
                : null;
            $linkOrderId ??= $attempt->provider_order_id;

            $flags[$attempt->id] = $this->possibleDuplicateVerificationOperation(
                $linkCalls,
                (int) $attempt->status_poll_call_count,
                $tokenCalls,
                $linkOrderId,
                $processorTransactionIds[$attempt->id] ?? null,
                isset($attemptsWithDuplicateEvents[$attempt->id]),
            );
        }

        return $flags;
    }

    /**
     * @param  list<int>  $attemptIds
     * @return array<int, string|null>
     */
    private function batchLatestProcessorTransactionIds(array $attemptIds): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $events = PaymentAuthenticationAttemptEvent::query()
            ->whereIn('payment_authentication_attempt_id', $attemptIds)
            ->whereIn('event_type', [
                PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded->value,
                PaymentAuthenticationAttemptEventType::TokenizationSucceeded->value,
            ])
            ->orderByDesc('id')
            ->get(['payment_authentication_attempt_id', 'metadata']);

        $map = [];

        foreach ($events as $event) {
            $attemptId = (int) $event->payment_authentication_attempt_id;

            if (isset($map[$attemptId])) {
                continue;
            }

            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $map[$attemptId] = isset($metadata['processor_transaction_id'])
                ? (string) $metadata['processor_transaction_id']
                : null;
        }

        return $map;
    }

    private function possibleDuplicateVerificationOperation(
        int $linkCalls,
        int $statusCalls,
        int $tokenCalls,
        ?string $linkOrderId,
        ?string $tokenTransactionId,
        bool $hasDuplicateEvent,
    ): bool {
        if ($linkCalls > 1 || $tokenCalls > 1) {
            return true;
        }

        return $hasDuplicateEvent;
    }

    private function latestProcessorTransactionId(PaymentAuthenticationAttempt $attempt): ?string
    {
        $event = $attempt->events()
            ->whereIn('event_type', [
                PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded->value,
                PaymentAuthenticationAttemptEventType::TokenizationSucceeded->value,
            ])
            ->orderByDesc('id')
            ->first();

        if (! $event) {
            return null;
        }

        $metadata = is_array($event->metadata) ? $event->metadata : [];

        return isset($metadata['processor_transaction_id']) ? (string) $metadata['processor_transaction_id'] : null;
    }

    private function lastStatusResult(PaymentAuthenticationAttempt $attempt): string
    {
        return match ($attempt->status) {
            PaymentAuthenticationAttemptStatus::Completed->value => 'completed',
            PaymentAuthenticationAttemptStatus::Declined->value => 'declined',
            PaymentAuthenticationAttemptStatus::Cancelled->value => 'cancelled',
            PaymentAuthenticationAttemptStatus::Expired->value => 'expired',
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value => 'provider_confirmation_pending',
            PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value => 'tokenization_confirmation_pending',
            PaymentAuthenticationAttemptStatus::Pending->value,
            PaymentAuthenticationAttemptStatus::ChallengeRequired->value => 'pending',
            default => $attempt->status,
        };
    }

    private function tokenCardResultLabel(int $calls, string $status, bool $tokenFailed): string
    {
        if ($calls === 0) {
            return 'not_called';
        }

        if ($status === PaymentAuthenticationAttemptStatus::Completed->value) {
            return 'succeeded';
        }

        if ($status === PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value) {
            return 'confirmation_pending';
        }

        if ($tokenFailed || $status === PaymentAuthenticationAttemptStatus::TechnicalError->value) {
            return 'business_failed';
        }

        return $calls > 1 ? 'multiple_calls' : 'attempted';
    }

    private function operationResultLabel(int $calls, string $status, string $operation): string
    {
        if ($calls === 0) {
            return 'not_called';
        }

        if ($operation === 'link') {
            return $calls > 1 ? 'multiple_calls' : 'called_once';
        }

        if ($operation === 'token') {
            if ($status === PaymentAuthenticationAttemptStatus::Completed->value) {
                return 'succeeded';
            }

            if ($status === PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value) {
                return 'confirmation_pending';
            }

            if ($status === PaymentAuthenticationAttemptStatus::TechnicalError->value) {
                return 'failed';
            }

            return $calls > 1 ? 'multiple_calls' : 'attempted';
        }

        return 'unknown';
    }
}
