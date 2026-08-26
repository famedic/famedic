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
    private const SESSION_TERMINAL_STATUSES = [
        'completed',
        'declined',
        'tokenization_failed',
        'cancelled',
        'error',
        'failed',
    ];

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
        if (! $attempt) {
            return $this->pollLegacySession($customer, $session);
        }

        $cycleResult = $this->externalCallGuard->withSessionPollCycleLock(
            $session,
            $attempt,
            fn (Efevoo3dsSession $lockedSession, ?PaymentAuthenticationAttempt $lockedAttempt) => $this->executePollCycle(
                $customer,
                $lockedSession,
                $lockedAttempt
            )
        );

        if (($cycleResult['duplicate'] ?? false) && ($cycleResult['blocked'] ?? false)) {
            $status = (string) ($cycleResult['processing_status'] ?? 'processing');

            return [
                'final' => $this->isTerminalPollStatus($status),
                'status' => $status,
                'message' => $this->processingMessageForStatus($status),
                'error_type' => in_array($status, ['declined', 'rejected', 'cancelled', 'expired', 'failed', 'error'], true)
                    ? 'gateway'
                    : 'pending',
            ];
        }

        return $cycleResult['result'] ?? [
            'final' => false,
            'status' => 'processing',
            'message' => 'Consulta en curso. Espera unos segundos.',
            'error_type' => 'pending',
        ];
    }

    /**
     * @return array{final: bool, status: string, message: string, error_type?: string|null, raw?: mixed}
     */
    private function executePollCycle(
        Customer $customer,
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttempt $attempt
    ): array {
        $sessionId = (int) $session->id;

        if (in_array($session->status, self::SESSION_TERMINAL_STATUSES, true)) {
            $this->cardDataStore->purge($sessionId, 'terminal_session_repoll', $attempt, [
                'stage' => 'poll_terminal_revisit',
            ]);

            return $this->terminalSessionResponse($session);
        }

        if ($attempt && in_array($attempt->status, PaymentAuthenticationAttemptStatus::terminalValues(), true)) {
            $this->cardDataStore->purge($sessionId, 'terminal_attempt_repoll', $attempt, [
                'stage' => 'poll_terminal_revisit',
            ]);

            return $this->terminalAttemptResponse($attempt, $session);
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

        if ($attempt && $attempt->status === PaymentAuthenticationAttemptStatus::Tokenizing->value) {
            return $this->finalizeAuthenticatedSession($customer, $session, $attempt);
        }

        if ($attempt && $attempt->status === PaymentAuthenticationAttemptStatus::Authenticated->value) {
            return $this->finalizeAuthenticatedSession($customer, $session, $attempt);
        }

        $cardData = $this->cardDataStore->readForCustomer($customer, $sessionId);

        if ($cardData === null) {
            return $this->handleMissingCardData($customer, $session, $attempt);
        }

        try {
            $guardResult = $this->externalCallGuard->withGetStatusLock(
                $session,
                $attempt,
                fn () => $this->gateway->poll3DSAuthentication($session, $cardData),
                lockAlreadyHeld: true
            );
        } catch (\Throwable $e) {
            return $this->handleAmbiguousProviderFailure($session, $attempt, $e);
        }

        if ($guardResult['duplicate'] ?? false) {
            return [
                'final' => false,
                'status' => 'processing',
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

                return $this->handleAmbiguousProviderFailure(
                    $session,
                    $attempt,
                    new PaymentAuthentication3dsProviderCallException(
                        (string) ($statusResult['failure_stage'] ?? 'poll_result'),
                        (string) ($statusResult['exception_category'] ?? 'network_error_after_dispatch'),
                        (bool) ($statusResult['request_dispatched'] ?? false),
                        (bool) ($statusResult['response_received'] ?? false),
                        isset($statusResult['http_status']) ? (int) $statusResult['http_status'] : null,
                        isset($statusResult['duration_ms']) ? (int) $statusResult['duration_ms'] : null
                    )
                );
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

        if ($phase === 'authenticated') {
            if ($attempt) {
                return $this->completeAuthenticationAndTokenize($customer, $session, $attempt, $cardData);
            }

            return $this->finalizeAuthenticatedSession($customer, $session, null, $cardData);
        }

        return $this->finalizeAuthenticatedSession($customer, $session, $attempt, $cardData);
    }

    /**
     * GetStatus aprobado → autenticación + TokenCard en el mismo ciclo protegido.
     *
     * @param  array<string, mixed>  $cardData
     * @return array{final: bool, status: string, message: string, error_type?: string|null, presentation?: string}
     */
    private function completeAuthenticationAndTokenize(
        Customer $customer,
        Efevoo3dsSession $session,
        PaymentAuthenticationAttempt $attempt,
        array $cardData
    ): array {
        if (! in_array($session->status, ['authenticated', 'approved', 'completed'], true)) {
            $session->update([
                'status' => 'authenticated',
                'status_checked_at' => now(),
            ]);
        }

        $this->recorder->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::AuthenticationSucceeded, [
            'source' => 'efevoopay',
            'provider_status' => $session->fresh()->status,
            'dedupe_key' => 'authentication_succeeded:'.$session->id,
            'metadata' => [
                'session_id' => $session->id,
                'response_received' => true,
            ],
        ]);

        if ($attempt->fresh()->status !== PaymentAuthenticationAttemptStatus::Tokenizing->value) {
            try {
                $this->recorder->transition(
                    $attempt->fresh(),
                    PaymentAuthenticationAttemptStatus::Tokenizing,
                    PaymentAuthenticationAttemptEventType::TokenizationStarted,
                    [
                        'source' => 'backend',
                        'dedupe_key' => 'tokenization_started:'.$session->id,
                        'metadata' => [
                            'session_id' => $session->id,
                            'stage' => 'post_getstatus_approved',
                        ],
                    ]
                );
            } catch (\DomainException) {
                // Otro ciclo concurrente ya reclamó la tokenización.
            }
        }

        return $this->finalizeAuthenticatedSession($customer, $session->fresh(), $attempt->fresh(), $cardData);
    }

    /**
     * @param  array<string, mixed>  $tokenResult
     */
    private function applyTokenizationFailure(
        PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session,
        array $tokenResult
    ): void {
        if (in_array($attempt->fresh()->status, PaymentAuthenticationAttemptStatus::terminalValues(), true)) {
            return;
        }

        $classification = EfevooPay3dsResultClassifier::tokenization($tokenResult);

        $descriptorKeys = EfevooPayTokenizeContract::TOKENIZE_DESCRIPTOR_KEYS;
        $descriptor = array_intersect_key($tokenResult, array_flip($descriptorKeys));

        $this->recorder->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationFailed, [
            'source' => 'efevoopay',
            'result_category' => $classification['result_category'],
            'failure_origin' => $classification['failure_origin'],
            'failure_certainty' => $classification['failure_certainty'],
            'provider_code' => $classification['provider_code'] ?? $tokenResult['provider_code'] ?? $tokenResult['error_code'] ?? null,
            'provider_message' => $classification['provider_message']
                ?? $tokenResult['admin_message']
                ?? $tokenResult['provider_message']
                ?? $tokenResult['message']
                ?? null,
            'http_status' => $tokenResult['http_status'] ?? null,
            'duration_ms' => $tokenResult['duration_ms'] ?? null,
            'dedupe_key' => 'tokenization_failed:'.$session->id,
            'metadata' => array_merge([
                'session_id' => $session->id,
                'response_received' => $tokenResult['response_received'] ?? null,
                'failure_stage' => $tokenResult['failure_stage'] ?? 'tokenize_response',
                'exception_category' => $tokenResult['exception_category'] ?? null,
                'processor_transaction_id' => $tokenResult['processor_transaction_id'] ?? null,
            ], $descriptor),
        ]);

        try {
            $this->recorder->transition($attempt->fresh(), PaymentAuthenticationAttemptStatus::TechnicalError, PaymentAuthenticationAttemptEventType::TechnicalError, [
                'source' => 'backend',
                'result_category' => $classification['result_category'],
                'failure_origin' => $classification['failure_origin'],
                'failure_certainty' => $classification['failure_certainty'],
                'provider_code' => $classification['provider_code'] ?? $tokenResult['provider_code'] ?? $tokenResult['error_code'] ?? null,
                'provider_message' => $classification['provider_message'] ?? $tokenResult['provider_message'] ?? $tokenResult['message'] ?? null,
                'dedupe_key' => 'technical_error:tokenization:'.$session->id,
                'metadata' => [
                    'session_id' => $session->id,
                    'failure_stage' => 'tokenization',
                    'exception_category' => $tokenResult['exception_category'] ?? null,
                ],
            ]);
        } catch (\DomainException) {
            // Terminal transition already applied.
        }

        app(PaymentAuthenticationRecoveryContextManager::class)->syncFromAttempt($attempt->fresh());
    }

    /**
     * @param  array<string, mixed>|null  $cardData
     * @return array{final: bool, status: string, message: string, error_type?: string|null, raw?: mixed}
     */
    private function finalizeAuthenticatedSession(
        Customer $customer,
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttempt $attempt,
        ?array $cardData = null
    ): array {
        $sessionId = (int) $session->id;
        $cardData ??= $this->cardDataStore->readForCustomer($customer, $sessionId);

        $tokenizePayload = PaymentAuthenticationEfevooPayAmounts::forTokenization(
            $this->cardDataStore->stripCvv(is_array($cardData) ? $cardData : [])
        );

        if (is_array($cardData) && $cardData !== []) {
            $this->cardDataStore->purge($sessionId, 'authenticated_before_tokenize', $attempt, [
                'stage' => 'pre_tokenize',
            ]);
        }

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
                    $attempt->fresh(),
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
                    'final' => false,
                    'status' => PaymentAuthenticationAttemptStatus::Tokenizing->value,
                    'message' => 'Tokenización ya en curso.',
                    'error_type' => 'pending',
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
            if ($attempt) {
                $this->applyTokenizationFailure($attempt, $session, $tokenResult);
            }

            return [
                'final' => true,
                'status' => $session->fresh()->status,
                'message' => $tokenResult['message'] ?? config('efevoopay.sensitive_card_data.messages.generic_failure'),
                'error_type' => $tokenResult['error_type'] ?? null,
                'presentation' => 'tokenization_failed',
            ];
        }

        $session->refresh();
        $localPersistence = app(PaymentAuthenticationLocalPaymentMethodPersistence::class);

        if ($attempt
            && filled($session->efevoo_token_id)
            && ! $localPersistence->sessionHasListableToken($session, $customer)) {
            return $this->handleLocalPersistenceFailure($attempt, $session, $tokenResult);
        }

        if ($attempt
            && ($tokenResult['token_usuario_present'] ?? false)
            && ! filled($session->efevoo_token_id)) {
            return $this->handleLocalPersistenceFailure($attempt, $session, $tokenResult);
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

            try {
                $this->recorder->transition(
                    $attempt->fresh(),
                    PaymentAuthenticationAttemptStatus::Completed,
                    PaymentAuthenticationAttemptEventType::AttemptCompleted,
                    [
                        'source' => 'backend',
                        'dedupe_key' => 'attempt_completed:'.$session->id,
                        'metadata' => [
                            'session_id' => $session->id,
                            'stage' => 'post_tokenize',
                        ],
                    ]
                );
            } catch (\DomainException) {
                // Terminal transition already applied.
            }

            app(PaymentAuthenticationRecoveryContextManager::class)->syncFromAttempt($attempt->fresh());
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
    private function pollLegacySession(Customer $customer, Efevoo3dsSession $session): array
    {
        return $this->executePollCycle($customer, $session, null);
    }

    /**
     * @return array{final: bool, status: string, message: string, error_type?: string|null}
     */
    private function terminalSessionResponse(Efevoo3dsSession $session): array
    {
        return [
            'final' => true,
            'status' => $session->status,
            'message' => $session->error_message ?: $this->publicMessageForStatus($session->status),
        ];
    }

    /**
     * @return array{final: bool, status: string, message: string, error_type?: string|null}
     */
    private function terminalAttemptResponse(
        PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session
    ): array {
        $status = $attempt->status;

        if ($status === PaymentAuthenticationAttemptStatus::Completed->value) {
            return [
                'final' => true,
                'status' => 'completed',
                'message' => 'Tarjeta verificada y guardada correctamente.',
            ];
        }

        if ($status === PaymentAuthenticationAttemptStatus::Declined->value) {
            return [
                'final' => true,
                'status' => 'declined',
                'message' => $attempt->provider_message
                    ?: 'El proveedor reportó que no se completó la autenticación.',
            ];
        }

        return [
            'final' => true,
            'status' => $status,
            'message' => $session->error_message ?: $this->publicMessageForStatus($session->status),
        ];
    }

    private function isTerminalPollStatus(string $status): bool
    {
        return in_array($status, array_merge(
            self::SESSION_TERMINAL_STATUSES,
            PaymentAuthenticationAttemptStatus::terminalValues(),
            [
                PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
                PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
            ]
        ), true);
    }

    private function processingMessageForStatus(string $status): string
    {
        return match ($status) {
            PaymentAuthenticationAttemptStatus::Tokenizing->value => 'Guardando tu tarjeta de forma segura...',
            PaymentAuthenticationAttemptStatus::Authenticated->value => 'Verificación aprobada. Guardando tarjeta...',
            'declined', 'rejected' => 'El proveedor reportó que no se completó la autenticación.',
            'completed' => 'Tarjeta verificada y guardada correctamente.',
            default => 'Consulta en curso. Espera unos segundos.',
        };
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

        $providerException = $e instanceof PaymentAuthentication3dsProviderCallException ? $e : null;
        $exceptionCategory = $providerException?->exceptionCategory() ?? 'provider_confirmation_pending';
        $failureStage = $providerException?->failureStage() ?? 'unknown';
        $attemptStatus = $exceptionCategory === 'technical_error_before_dispatch'
            ? PaymentAuthenticationAttemptStatus::TechnicalError
            : PaymentAuthenticationAttemptStatus::ProviderConfirmationPending;

        if ($attempt) {
            $eventType = $attemptStatus === PaymentAuthenticationAttemptStatus::TechnicalError
                ? PaymentAuthenticationAttemptEventType::TechnicalError
                : PaymentAuthenticationAttemptEventType::ProviderConfirmationPending;
            $classification = [
                'result_category' => match ($exceptionCategory) {
                    'technical_error_before_dispatch' => EfevooPay3dsResultClassifier::CATEGORY_CONFIGURATION_ERROR,
                    'invalid_response' => EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_ERROR,
                    default => EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT,
                },
                'failure_origin' => $exceptionCategory === 'technical_error_before_dispatch'
                    ? EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC
                    : EfevooPay3dsResultClassifier::ORIGIN_NETWORK,
                'failure_certainty' => $exceptionCategory === 'technical_error_before_dispatch'
                    ? EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED
                    : EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN,
                'provider_message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
            ];

            $this->recorder->transition($attempt->fresh(), $attemptStatus, $eventType, array_merge($classification, [
                'source' => 'backend',
                'dedupe_key' => $eventType->value.':poll:'.$session->id,
                'metadata' => [
                    'session_id' => $session->id,
                    'failure_stage' => $failureStage,
                    'exception_category' => $exceptionCategory,
                    'request_dispatched' => $providerException?->requestDispatched() ?? false,
                    'response_received' => $providerException?->responseReceived() ?? false,
                    'http_status' => $providerException?->httpStatus(),
                    'duration_ms' => $providerException?->durationMs(),
                    'detected_by' => 'famedic',
                ],
            ]));
        }

        return [
            'final' => true,
            'status' => $attemptStatus === PaymentAuthenticationAttemptStatus::TechnicalError
                ? PaymentAuthenticationAttemptStatus::TechnicalError->value
                : PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
            'message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
            'error_type' => 'system',
            'exception_category' => $exceptionCategory,
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
            'declined', 'rejected' => 'El proveedor reportó que no se completó la autenticación.',
            'tokenization_failed' => 'La tarjeta fue autenticada, pero no pudo guardarse.',
            'cancelled' => 'Verificación cancelada.',
            'expired' => config('efevoopay.sensitive_card_data.messages.missing_or_expired'),
            default => config('efevoopay.sensitive_card_data.messages.generic_failure'),
        };
    }

    /**
     * @param  array<string, mixed>  $tokenResult
     * @return array{final: bool, status: string, message: string, error_type?: string|null, presentation?: string}
     */
    private function handleLocalPersistenceFailure(
        PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session,
        array $tokenResult
    ): array {
        if (! in_array($attempt->fresh()->status, PaymentAuthenticationAttemptStatus::terminalValues(), true)) {
            try {
                $this->recorder->transition(
                    $attempt->fresh(),
                    PaymentAuthenticationAttemptStatus::ProviderConfirmationPending,
                    PaymentAuthenticationAttemptEventType::StatusPollFailed,
                    [
                        'source' => 'backend',
                        'result_category' => EfevooPay3dsResultClassifier::CATEGORY_UNKNOWN,
                        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC,
                        'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN,
                        'dedupe_key' => 'local_persistence_pending:'.$attempt->id,
                        'metadata' => [
                            'session_id' => $session->id,
                            'failure_stage' => 'local_payment_method_persistence',
                            'token_usuario_present' => (bool) ($tokenResult['token_usuario_present'] ?? false),
                            'efevoo_token_id' => $session->efevoo_token_id,
                        ],
                    ]
                );
            } catch (\DomainException) {
                // Another cycle already moved the attempt to a terminal state.
            }
        }

        return [
            'final' => true,
            'status' => PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
            'message' => 'Verificamos tu tarjeta con el banco, pero necesitamos confirmar que quedó guardada correctamente. '
                .'Actualiza el estado o contacta a soporte con tu referencia.',
            'error_type' => 'system',
            'presentation' => 'provider_confirmation_pending',
        ];
    }
}
