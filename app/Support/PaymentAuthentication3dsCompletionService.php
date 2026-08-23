<?php

namespace App\Support;

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Services\EfevooPayService;
use Illuminate\Support\Facades\Log;

class PaymentAuthentication3dsCompletionService
{
    public function __construct(
        private EfevooPayGateway $gateway,
        private PaymentAuthenticationSensitiveCardDataStore $cardDataStore,
        private PaymentAuthenticationAttemptRecorder $recorder,
        private PaymentAuthentication3dsExternalCallGuard $externalCallGuard,
        private PaymentAuthenticationEfevooPayMonetaryTracer $monetaryTracer
    ) {}

    /**
     * @return array{
     *     final: bool,
     *     status: string,
     *     message: string,
     *     error_type?: string|null,
     *     raw?: mixed
     * }
     */
    public function poll(
        Customer $customer,
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttempt $attempt
    ): array {
        $sessionId = (int) $session->id;
        $terminalStatuses = ['completed', 'declined', 'tokenization_failed', 'cancelled', 'error', 'failed'];

        if (in_array($session->status, $terminalStatuses, true)) {
            $this->cardDataStore->purge($sessionId, 'terminal_session_repoll', $attempt, [
                'stage' => 'poll_terminal_revisit',
            ]);

            return [
                'final' => true,
                'status' => $session->status,
                'message' => $session->error_message ?: $this->publicMessageForStatus($session->status),
            ];
        }

        if ($attempt && in_array($attempt->status, [
            PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
        ], true)) {
            return [
                'final' => true,
                'status' => $attempt->status,
                'message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
                'error_type' => 'system',
            ];
        }

        $cardData = $this->cardDataStore->readForCustomer($customer, $sessionId);

        if ($cardData === null) {
            return $this->handleMissingCardData($customer, $session, $attempt);
        }

        try {
            $guardResult = $this->externalCallGuard->withGetStatusLock(
                $session,
                $attempt,
                fn () => $this->gateway->poll3DSAuthentication($session, $cardData)
            );
        } catch (\Throwable $e) {
            return $this->handleAmbiguousProviderFailure($session, $attempt, $e);
        }

        if ($guardResult['duplicate'] ?? false) {
            return [
                'final' => false,
                'status' => 'pending',
                'message' => 'Consulta en curso. Espera unos segundos.',
                'error_type' => 'pending',
            ];
        }

        if (($guardResult['blocked'] ?? false) && isset($guardResult['result'])) {
            $blocked = $guardResult['result'];

            return [
                'final' => (bool) ($blocked['final'] ?? true),
                'status' => (string) ($blocked['status'] ?? 'expired'),
                'message' => (string) ($blocked['message'] ?? config('efevoopay.sensitive_card_data.messages.missing_or_expired')),
                'error_type' => $blocked['error_type'] ?? null,
            ];
        }

        $statusResult = $guardResult['result'] ?? ['phase' => 'unknown'];
        $phase = (string) ($statusResult['phase'] ?? 'unknown');

        if ($phase === 'pending') {
            return [
                'final' => false,
                'status' => 'pending',
                'message' => $statusResult['message'] ?? '3DS aún pendiente',
                'error_type' => 'pending',
            ];
        }

        if (in_array($phase, ['declined', 'rejected', 'cancelled', 'expired', 'failed', 'error'], true)) {
            $reason = $phase === 'error' && ($statusResult['error_type'] ?? null) === EfevooPayService::ERROR_NETWORK
                ? 'provider_confirmation_pending'
                : $phase;

            if ($reason === 'provider_confirmation_pending') {
                $this->cardDataStore->purge($sessionId, $reason, $attempt, [
                    'stage' => 'poll_status_network',
                ]);

                return $this->handleAmbiguousProviderFailure($session, $attempt, new \RuntimeException('GetStatus network failure'));
            }

            $this->cardDataStore->purge($sessionId, $reason, $attempt, [
                'stage' => 'poll_status_terminal',
            ]);

            return [
                'final' => true,
                'status' => $session->fresh()->status,
                'message' => $statusResult['message'] ?? $this->publicMessageForStatus($session->status),
                'error_type' => $statusResult['error_type'] ?? null,
            ];
        }

        if ($phase === 'already_processed') {
            $this->cardDataStore->purge($sessionId, 'already_processed', $attempt, [
                'stage' => 'poll_idempotent',
            ]);

            return [
                'final' => true,
                'status' => $session->status,
                'message' => $statusResult['message'] ?? '3DS ya procesado',
                'error_type' => $statusResult['error_type'] ?? null,
            ];
        }

        if ($phase !== 'authenticated') {
            $this->cardDataStore->purge($sessionId, 'unknown_status', $attempt, [
                'stage' => 'poll_unknown',
            ]);

            return [
                'final' => true,
                'status' => 'error',
                'message' => config('efevoopay.sensitive_card_data.messages.generic_failure'),
                'error_type' => $statusResult['error_type'] ?? 'gateway',
            ];
        }

        $tokenizePayload = PaymentAuthenticationEfevooPayAmounts::forTokenization(
            $this->cardDataStore->stripCvv($cardData)
        );
        $this->cardDataStore->purge($sessionId, 'authenticated_before_tokenize', $attempt, [
            'stage' => 'pre_tokenize',
        ]);

        if (! $attempt) {
            try {
                $tokenResult = $this->gateway->finalize3DSTokenization($session, $tokenizePayload);
            } catch (\Throwable $e) {
                return $this->handleAmbiguousProviderFailure($session, $attempt, $e);
            }
        } else {
            try {
                $tokenGuard = $this->externalCallGuard->withTokenizationClaim(
                    $session,
                    $attempt,
                    fn () => $this->gateway->finalize3DSTokenization($session, $tokenizePayload)
                );
            } catch (\Throwable $e) {
                return $this->handleAmbiguousProviderFailure($session, $attempt, $e);
            }

            if ($tokenGuard['duplicate'] ?? false) {
                if ($tokenGuard['confirmation_pending'] ?? false) {
                    return [
                        'final' => true,
                        'status' => PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
                        'message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
                        'error_type' => 'system',
                    ];
                }

                return [
                    'final' => true,
                    'status' => $session->fresh()->status,
                    'message' => 'Tokenización ya en curso.',
                    'error_type' => 'system',
                ];
            }

            $tokenResult = $tokenGuard['result'] ?? ['success' => false];
        }

        if (($tokenResult['confirmation_pending'] ?? false) === true) {
            return [
                'final' => true,
                'status' => PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
                'message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
                'error_type' => 'system',
            ];
        }

        if (! ($tokenResult['success'] ?? false)) {
            return [
                'final' => true,
                'status' => $session->fresh()->status,
                'message' => $tokenResult['message'] ?? config('efevoopay.sensitive_card_data.messages.generic_failure'),
                'error_type' => $tokenResult['error_type'] ?? null,
                'raw' => $tokenResult['raw'] ?? null,
            ];
        }

        if ($attempt) {
            $analysis = app(\App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationEfevooPayOperationAnalyzer::class)
                ->analyze($attempt->fresh(), $session->fresh());

            if ($analysis['possible_duplicate_verification_operation'] ?? false) {
                $this->monetaryTracer->recordPossibleDuplicate($attempt->fresh(), [
                    'stage' => 'post_tokenize',
                    'session_id' => $session->id,
                ]);
            }
        }

        return [
            'final' => true,
            'status' => 'completed',
            'message' => $tokenResult['message'] ?? '3DS completado correctamente',
        ];
    }

    /**
     * @return array{final: bool, status: string, message: string, error_type?: string|null}
     */
    private function handleMissingCardData(
        Customer $customer,
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttempt $attempt
    ): array {
        $message = config('efevoopay.sensitive_card_data.messages.missing_or_expired');
        $raw = $this->cardDataStore->rawPayload((int) $session->id);
        $wasExpired = is_array($raw)
            && (int) ($raw['customer_id'] ?? 0) === (int) $customer->id
            && $this->cardDataStore->payloadExpired($raw);

        if ($attempt) {
            if ($wasExpired) {
                $this->cardDataStore->recordExpiredEvent($attempt, ['stage' => 'poll_missing']);
                $classification = EfevooPay3dsResultClassifier::localExpiration();
                $this->transitionAttempt($attempt, PaymentAuthenticationAttemptStatus::Expired, PaymentAuthenticationAttemptEventType::AuthenticationExpired, $classification);
            } else {
                $this->cardDataStore->recordMissing($attempt, 'missing_before_ttl', ['stage' => 'poll']);
                $this->transitionAttempt($attempt, PaymentAuthenticationAttemptStatus::TechnicalError, PaymentAuthenticationAttemptEventType::TechnicalError, [
                    'result_category' => EfevooPay3dsResultClassifier::CATEGORY_UNKNOWN,
                    'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC,
                    'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
                ]);
            }
        }

        $session->update([
            'status' => $wasExpired ? 'failed' : 'error',
            'error_message' => $message,
        ]);

        return [
            'final' => true,
            'status' => $wasExpired ? 'expired' : 'error',
            'message' => $message,
            'error_type' => $wasExpired ? 'expired' : 'system',
        ];
    }

    /**
     * @return array{final: bool, status: string, message: string, error_type?: string|null}
     */
    private function handleAmbiguousProviderFailure(
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttempt $attempt,
        \Throwable $e
    ): array {
        $this->cardDataStore->purge((int) $session->id, 'provider_confirmation_pending', $attempt, [
            'stage' => 'ambiguous_after_provider_call',
        ]);

        Log::warning('[3DS] Ambiguous provider completion', [
            'session_id' => $session->id,
            'exception_class' => $e::class,
        ]);

        if ($attempt) {
            $classification = [
                'result_category' => EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT,
                'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_NETWORK,
                'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN,
                'provider_message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
            ];

            $this->recorder->transition($attempt->fresh(), PaymentAuthenticationAttemptStatus::ProviderConfirmationPending, PaymentAuthenticationAttemptEventType::ProviderConfirmationPending, array_merge($classification, [
                'source' => 'backend',
                'dedupe_key' => 'provider_confirmation_pending:poll:'.$session->id,
                'metadata' => [
                    'session_id' => $session->id,
                    'exception_class' => $e::class,
                    'detected_by' => 'famedic',
                ],
            ]));
        }

        return [
            'final' => true,
            'status' => 'provider_confirmation_pending',
            'message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
            'error_type' => 'system',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transitionAttempt(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationAttemptStatus $status,
        PaymentAuthenticationAttemptEventType $eventType,
        array $attributes = []
    ): void {
        try {
            $this->recorder->transition($attempt->fresh(), $status, $eventType, array_merge([
                'source' => 'backend',
                'dedupe_key' => $eventType->value.':missing_card_data:'.$attempt->id,
            ], $attributes));
        } catch (\DomainException) {
            $this->recorder->record($attempt->fresh(), $eventType, array_merge([
                'source' => 'backend',
                'dedupe_key' => $eventType->value.':missing_card_data:'.$attempt->id.':record',
            ], $attributes));
        }
    }

    private function publicMessageForStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'Tarjeta verificada y guardada correctamente.',
            'declined' => 'La verificación fue rechazada por tu banco.',
            'tokenization_failed' => 'La tarjeta fue autenticada, pero no pudo guardarse.',
            'cancelled' => 'Verificación cancelada.',
            'expired' => config('efevoopay.sensitive_card_data.messages.missing_or_expired'),
            default => config('efevoopay.sensitive_card_data.messages.generic_failure'),
        };
    }
}
