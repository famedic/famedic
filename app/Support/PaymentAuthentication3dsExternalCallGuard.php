<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentAuthentication3dsExternalCallGuard
{
    public function __construct(
        private PaymentAuthenticationEfevooPayMonetaryTracer $tracer
    ) {}

    /**
     * @param  callable(Efevoo3dsSession, ?PaymentAuthenticationAttempt): array<string, mixed>  $callback
     * @return array{blocked: bool, duplicate: bool, processing_status?: string, result?: array<string, mixed>}
     */
    public function withSessionPollCycleLock(
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttempt $attempt,
        callable $callback
    ): array {
        if (! $attempt) {
            return ['blocked' => false, 'duplicate' => false, 'result' => $callback($session, null)];
        }

        $lockSeconds = max(
            (int) config('efevoopay.polling.get_status_lock_seconds', 45),
            (int) config('efevoopay.polling.tokenize_lock_seconds', 90)
        );
        $lock = Cache::lock('efevoo_3ds_poll_cycle_'.$session->id, $lockSeconds);

        if (! $lock->get()) {
            $freshAttempt = $attempt->fresh();
            $this->tracer->recordDuplicateBlocked(
                $freshAttempt,
                PaymentAuthenticationEfevooPayMonetaryTracer::OP_GET_STATUS,
                'concurrent_poll_cycle',
                $session->id
            );

            $freshSession = $session->fresh();

            return [
                'blocked' => true,
                'duplicate' => true,
                'processing_status' => $this->processingStatusForBlockedPoll($freshAttempt, $freshSession),
            ];
        }

        try {
            $freshSession = $session->fresh();
            $freshAttempt = PaymentAuthenticationAttempt::query()->find($attempt->id);

            return [
                'blocked' => false,
                'duplicate' => false,
                'result' => $callback($freshSession, $freshAttempt),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array{blocked: bool, duplicate: bool, result?: array<string, mixed>}
     */
    public function withGetStatusLock(
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttempt $attempt,
        callable $callback,
        bool $lockAlreadyHeld = false
    ): array {
        if (! $attempt) {
            return ['blocked' => false, 'duplicate' => false, 'result' => $callback()];
        }

        if ($this->externalPollLimitReached($attempt)) {
            return [
                'blocked' => true,
                'duplicate' => false,
                'result' => [
                    'phase' => 'poll_limit_reached',
                    'final' => true,
                    'status' => 'expired',
                    'message' => config('efevoopay.sensitive_card_data.messages.missing_or_expired'),
                ],
            ];
        }

        if ($lockAlreadyHeld) {
            return $this->executeGetStatusWithTracing($session, $attempt, $callback);
        }

        $lockSeconds = (int) config('efevoopay.polling.get_status_lock_seconds', 45);
        $lock = Cache::lock('efevoo_3ds_getstatus_'.$session->id, $lockSeconds);

        if (! $lock->get()) {
            $this->tracer->recordDuplicateBlocked($attempt, PaymentAuthenticationEfevooPayMonetaryTracer::OP_GET_STATUS, 'concurrent_poll', $session->id);

            return ['blocked' => true, 'duplicate' => true];
        }

        try {
            return $this->executeGetStatusWithTracing($session, $attempt, $callback);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array{blocked: bool, duplicate: bool, result: array<string, mixed>}
     */
    private function executeGetStatusWithTracing(
        Efevoo3dsSession $session,
        PaymentAuthenticationAttempt $attempt,
        callable $callback
    ): array {
        $started = microtime(true);

        try {
            $this->tracer->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::ProviderStatusRequestStarted, PaymentAuthenticationEfevooPayMonetaryTracer::OP_GET_STATUS, [
                'session_id' => $session->id,
                'provider_order_id' => $session->order_id,
                'call_number' => $attempt->fresh()->status_poll_call_count + 1,
            ]);

            $result = $callback();
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            $phase = (string) ($result['phase'] ?? 'unknown');
            $errorType = $result['error_type'] ?? null;
            $timedOut = ($errorType === 'timeout')
                || (($result['timed_out'] ?? false) === true);
            $eventType = $timedOut
                ? PaymentAuthenticationAttemptEventType::ProviderStatusRequestTimeout
                : (($phase === 'error')
                    ? PaymentAuthenticationAttemptEventType::ProviderStatusRequestFailed
                    : PaymentAuthenticationAttemptEventType::ProviderStatusRequestSucceeded);

            $this->tracer->record($attempt->fresh(), $eventType, PaymentAuthenticationEfevooPayMonetaryTracer::OP_GET_STATUS, [
                'session_id' => $session->id,
                'provider_order_id' => $session->order_id,
                'duration_ms' => $durationMs,
                'call_number' => $attempt->fresh()->status_poll_call_count,
                'failure_stage' => $result['failure_stage'] ?? ($phase === 'error' ? 'poll_result' : null),
                'exception_category' => $result['exception_category'] ?? null,
                'request_dispatched' => $result['request_dispatched'] ?? null,
                'response_received' => $result['response_received'] ?? ($phase !== 'error'),
                'timed_out' => $timedOut,
            ]);

            return ['blocked' => false, 'duplicate' => false, 'result' => $result];
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $providerException = $e instanceof PaymentAuthentication3dsProviderCallException ? $e : null;
            $exceptionCategory = $providerException?->exceptionCategory() ?? 'technical_error_before_dispatch';
            $eventType = $exceptionCategory === 'network_timeout_after_dispatch'
                ? PaymentAuthenticationAttemptEventType::ProviderStatusRequestTimeout
                : PaymentAuthenticationAttemptEventType::ProviderStatusRequestFailed;

            $this->tracer->record($attempt->fresh(), $eventType, PaymentAuthenticationEfevooPayMonetaryTracer::OP_GET_STATUS, [
                'session_id' => $session->id,
                'provider_order_id' => $session->order_id,
                'duration_ms' => $providerException?->durationMs() ?? $durationMs,
                'http_status' => $providerException?->httpStatus(),
                'stage' => 'exception',
                'failure_stage' => $providerException?->failureStage() ?? 'callback',
                'exception_category' => $exceptionCategory,
                'request_dispatched' => $providerException?->requestDispatched() ?? false,
                'response_received' => $providerException?->responseReceived() ?? false,
            ]);

            throw $e;
        }
    }

    private function processingStatusForBlockedPoll(
        PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session
    ): string {
        if ($attempt->status === PaymentAuthenticationAttemptStatus::Tokenizing->value) {
            return PaymentAuthenticationAttemptStatus::Tokenizing->value;
        }

        if ($attempt->status === PaymentAuthenticationAttemptStatus::Authenticated->value) {
            return PaymentAuthenticationAttemptStatus::Authenticated->value;
        }

        if (in_array($session->status, ['completed', 'declined', 'tokenization_failed', 'cancelled', 'error', 'failed'], true)) {
            return $session->status;
        }

        if (in_array($attempt->status, PaymentAuthenticationAttemptStatus::terminalValues(), true)) {
            return $attempt->status;
        }

        return 'processing';
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array{blocked: bool, duplicate: bool, confirmation_pending?: bool, result?: array<string, mixed>}
     */
    public function withTokenizationClaim(
        Efevoo3dsSession $session,
        PaymentAuthenticationAttempt $attempt,
        callable $callback
    ): array {
        if ($attempt->status === PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value) {
            return [
                'blocked' => true,
                'duplicate' => true,
                'confirmation_pending' => true,
                'result' => [
                    'success' => false,
                    'message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
                    'error_type' => 'system',
                ],
            ];
        }

        $claimed = DB::transaction(function () use ($attempt) {
            $locked = PaymentAuthenticationAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            if ((int) $locked->tokenization_call_count > 0
                || $locked->status === PaymentAuthenticationAttemptStatus::Completed->value
                || $locked->status === PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value) {
                return false;
            }

            if (! in_array($locked->status, [
                PaymentAuthenticationAttemptStatus::Authenticated->value,
                PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
                PaymentAuthenticationAttemptStatus::Pending->value,
                PaymentAuthenticationAttemptStatus::Tokenizing->value,
            ], true)) {
                return false;
            }

            $locked->forceFill(['status' => PaymentAuthenticationAttemptStatus::Tokenizing->value])->save();

            return true;
        });

        if (! $claimed) {
            $this->tracer->recordDuplicateBlocked($attempt, PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE, 'tokenization_already_started', $session->id);

            return ['blocked' => true, 'duplicate' => true];
        }

        $started = microtime(true);

        try {
            app(PaymentAuthenticationAttemptRecorder::class)->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationRequestStarted, [
                'source' => 'backend',
                'dedupe_key' => 'tokenization_request_started:intent:'.$session->id,
                'metadata' => [
                    'session_id' => $session->id,
                    'operation' => PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE,
                    'provider_order_id' => $session->order_id,
                    'amount' => PaymentAuthenticationEfevooPayAmounts::tokenizationVerificationAmount(),
                    'currency' => PaymentAuthenticationEfevooPayAmounts::currency(),
                    'call_number' => 1,
                ],
            ]);

            $result = $callback();
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $externalAttempted = (bool) ($result['external_tokenization_attempted'] ?? ! ($result['reused'] ?? false));

            if (($result['success'] ?? false) && ($result['reused'] ?? false) && ! $externalAttempted) {
                app(PaymentAuthenticationAttemptRecorder::class)->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::ExistingTokenReused, [
                    'source' => 'backend',
                    'dedupe_key' => 'existing_token_reused:'.$session->id.':'.($result['token_id'] ?? 'unknown'),
                    'metadata' => [
                        'session_id' => $session->id,
                        'reused_token_id' => $result['token_id'] ?? null,
                        'external_tokenization_attempted' => false,
                    ],
                ]);
            } elseif (($result['success'] ?? false) && $externalAttempted) {
                $descriptorKeys = EfevooPayTokenizeContract::TOKENIZE_DESCRIPTOR_KEYS;
                $descriptor = array_intersect_key($result, array_flip($descriptorKeys));

                app(PaymentAuthenticationAttemptRecorder::class)->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded, [
                    'source' => 'backend',
                    'external_operation' => PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE,
                    'dedupe_key' => 'tokenization_request_succeeded:'.PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE.':1',
                    'duration_ms' => $durationMs,
                    'metadata' => array_merge([
                        'session_id' => $session->id,
                        'operation' => PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE,
                        'provider_order_id' => $session->order_id,
                        'processor_transaction_id' => $result['transaction_id'] ?? null,
                        'external_tokenization_attempted' => true,
                        'response_received' => $result['response_received'] ?? true,
                        'http_status' => $result['http_status'] ?? null,
                    ], $descriptor),
                ]);
            } elseif (($result['confirmation_pending'] ?? false) === true) {
                $this->tracer->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationConfirmationPending, PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE, [
                    'session_id' => $session->id,
                    'provider_order_id' => $session->order_id,
                    'duration_ms' => $durationMs,
                    'stage' => 'timeout',
                ]);
                $this->markTokenizationConfirmationPending($attempt, $session);
            } else {
                $descriptorKeys = EfevooPayTokenizeContract::TOKENIZE_DESCRIPTOR_KEYS;
                $descriptor = array_intersect_key($result, array_flip($descriptorKeys));

                app(PaymentAuthenticationAttemptRecorder::class)->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationRequestFailed, [
                    'source' => 'backend',
                    'external_operation' => $externalAttempted ? PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE : null,
                    'dedupe_key' => 'tokenization_request_failed:'.($externalAttempted ? 'external' : 'local').':'.$session->id,
                    'duration_ms' => $durationMs,
                    'http_status' => $result['http_status'] ?? null,
                    'provider_code' => $result['provider_code'] ?? $result['error_code'] ?? null,
                    'provider_message' => EfevooPayLogSanitizer::providerMessage(
                        $result['admin_message'] ?? $result['provider_message'] ?? $result['message'] ?? null
                    ),
                    'metadata' => array_merge([
                        'session_id' => $session->id,
                        'operation' => PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE,
                        'provider_order_id' => $session->order_id,
                        'external_tokenization_attempted' => $externalAttempted,
                        'response_received' => $result['response_received'] ?? null,
                        'failure_stage' => $result['failure_stage'] ?? 'tokenize_response',
                        'exception_category' => $result['exception_category'] ?? null,
                        'processor_transaction_id' => $result['processor_transaction_id'] ?? null,
                        'http_status' => $result['http_status'] ?? null,
                        'duration_ms' => $durationMs,
                    ], $descriptor),
                ]);
            }

            return ['blocked' => false, 'duplicate' => false, 'result' => $result];
        } catch (LockTimeoutException $e) {
            $this->markTokenizationConfirmationPending($attempt, $session);

            return [
                'blocked' => false,
                'duplicate' => false,
                'confirmation_pending' => true,
                'result' => [
                    'success' => false,
                    'confirmation_pending' => true,
                    'message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
                    'error_type' => 'system',
                ],
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $this->tracer->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationRequestTimeout, PaymentAuthenticationEfevooPayMonetaryTracer::OP_TOKENIZE, [
                'session_id' => $session->id,
                'provider_order_id' => $session->order_id,
                'duration_ms' => $durationMs,
            ]);

            throw $e;
        }
    }

    public function externalPollLimitReached(PaymentAuthenticationAttempt $attempt): bool
    {
        $max = (int) config('efevoopay.polling.max_external_status_polls', 60);

        return (int) $attempt->status_poll_call_count >= $max;
    }

    private function markTokenizationConfirmationPending(
        PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session
    ): void {
        app(PaymentAuthenticationAttemptRecorder::class)->transition(
            $attempt->fresh(),
            PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending,
            PaymentAuthenticationAttemptEventType::TokenizationConfirmationPending,
            [
                'source' => 'backend',
                'dedupe_key' => 'tokenization_confirmation_pending:'.$attempt->id,
                'metadata' => [
                    'session_id' => $session->id,
                    'detected_by' => 'famedic',
                ],
            ]
        );
    }
}
