<?php

namespace App\Services\Otp\Monitoring;

use App\Enums\Otp\OtpMovementFlow;
use App\Enums\Otp\OtpMovementStage;
use App\Enums\Otp\OtpMovementStatus;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpMovementEvent;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail-soft append-only recorder for OTP admin monitoring.
 *
 * Never stores OTP codes, tokens, secrets, or full Idempotency-Key values.
 */
final class OtpMovementRecorder
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(array $payload): void
    {
        try {
            $movementKey = $this->resolveMovementKey(
                $payload['correlation_id'] ?? null,
                $payload['challenge_public_id'] ?? null,
            );

            if ($movementKey === null) {
                return;
            }

            OtpMovementEvent::query()->create([
                'occurred_at' => $payload['occurred_at'] ?? now(),
                'movement_key' => $movementKey,
                'flow' => (string) ($payload['flow'] ?? 'unknown'),
                'operation' => (string) ($payload['operation'] ?? 'request'),
                'stage' => (string) ($payload['stage'] ?? OtpMovementStage::RequestReceived->value),
                'status' => (string) ($payload['status'] ?? OtpMovementStatus::InProgress->value),
                'channel' => $payload['channel'] ?? null,
                'destination_masked' => $payload['destination_masked'] ?? null,
                'user_id' => $payload['user_id'] ?? null,
                'customer_id' => $payload['customer_id'] ?? null,
                'challenge_public_id' => $payload['challenge_public_id'] ?? null,
                'correlation_id' => $payload['correlation_id'] ?? null,
                'idempotency_key_fingerprint' => $this->fingerprintIdempotencyKey($payload['idempotency_key'] ?? null),
                'provider_alias' => $payload['provider_alias'] ?? null,
                'provider_result_class' => $payload['provider_result_class'] ?? null,
                'http_status' => $payload['http_status'] ?? null,
                'attempt_number' => (int) ($payload['attempt_number'] ?? 1),
                'is_resend' => (bool) ($payload['is_resend'] ?? false),
                'is_decoy' => (bool) ($payload['is_decoy'] ?? false),
                'is_replay' => (bool) ($payload['is_replay'] ?? false),
                'is_idempotency_conflict' => (bool) ($payload['is_idempotency_conflict'] ?? false),
                'endpoint' => $payload['endpoint'] ?? null,
                'error_code' => $payload['error_code'] ?? null,
                'technical_message' => $this->safeMessage($payload['technical_message'] ?? null),
                'otp_challenge_id' => $payload['otp_challenge_id'] ?? null,
                'otp_delivery_operation_id' => $payload['otp_delivery_operation_id'] ?? null,
                'meta' => $this->safeMeta($payload['meta'] ?? null),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('otp_movement_record_failed', [
                'error' => $e->getMessage(),
                'stage' => $payload['stage'] ?? null,
                'flow' => $payload['flow'] ?? null,
            ]);
        }
    }

    public function recordFromRequest(
        Request $request,
        OtpMovementFlow $flow,
        string $operation,
        OtpMovementStage $stage,
        OtpMovementStatus $status,
        array $extra = [],
    ): void {
        $this->record(array_merge([
            'flow' => $flow->value,
            'operation' => $operation,
            'stage' => $stage->value,
            'status' => $status->value,
            'correlation_id' => $request->header('X-Correlation-Id'),
            'endpoint' => $request->path(),
            'idempotency_key' => $request->header(IdempotencyKey::HEADER),
            'http_status' => $extra['http_status'] ?? null,
        ], $extra));
    }

    public function recordDeliveryObserved(
        string $logEvent,
        array $dims,
        ?OtpDeliveryOperation $operation = null,
    ): void {
        try {
            $purpose = is_string($dims['purpose'] ?? null) ? $dims['purpose'] : null;
            $flow = $purpose !== null ? OtpMovementFlow::fromPurpose($purpose) : null;
            if ($flow === null) {
                return;
            }

            $resultClass = is_string($dims['result_class'] ?? null)
                ? $dims['result_class']
                : null;

            [$stage, $status] = $this->mapDeliveryEvent($logEvent, $resultClass);

            $challengePublicId = is_string($dims['otp_challenge_public_id'] ?? null)
                ? $dims['otp_challenge_public_id']
                : null;

            $challengeId = null;
            if ($challengePublicId !== null) {
                $challengeId = OtpChallenge::query()
                    ->where('public_id', $challengePublicId)
                    ->value('id');
            }

            $this->record([
                'flow' => $flow->value,
                'operation' => 'delivery',
                'stage' => $stage->value,
                'status' => $status->value,
                'channel' => is_string($dims['channel'] ?? null) ? $dims['channel'] : 'sms',
                'correlation_id' => is_string($dims['correlation_id'] ?? null) ? $dims['correlation_id'] : null,
                'challenge_public_id' => $challengePublicId,
                'provider_alias' => is_string($dims['provider_alias'] ?? null) ? $dims['provider_alias'] : null,
                'provider_result_class' => $resultClass,
                'attempt_number' => is_numeric($dims['attempt_number'] ?? null) ? (int) $dims['attempt_number'] : 1,
                'otp_challenge_id' => $challengeId,
                'otp_delivery_operation_id' => $operation?->id,
                'technical_message' => $this->deliveryTechnicalMessage($logEvent, $resultClass),
                'meta' => [
                    'http_status_class' => $dims['http_status_class'] ?? null,
                    'duration_bucket' => $dims['duration_bucket'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('otp_movement_delivery_observed_failed', [
                'error' => $e->getMessage(),
                'log_event' => $logEvent,
            ]);
        }
    }

    public function recordIdempotencyReplay(
        string $originalCorrelationId,
        string $replayCorrelationId,
        string $path,
        int $httpStatus,
        ?string $idempotencyKey = null,
    ): void {
        $flow = $this->flowFromPath($path);

        $this->record([
            'flow' => ($flow ?? OtpMovementFlow::AkubicaLogin)->value,
            'operation' => 'idempotency',
            'stage' => OtpMovementStage::IdempotencyReplay->value,
            'status' => OtpMovementStatus::Replay->value,
            'correlation_id' => $replayCorrelationId,
            'endpoint' => $path,
            'http_status' => $httpStatus,
            'is_replay' => true,
            'idempotency_key' => $idempotencyKey,
            'technical_message' => 'Solicitud reutilizada por idempotencia; respuesta almacenada devuelta.',
            'meta' => [
                'original_correlation_id' => $originalCorrelationId,
            ],
        ]);
    }

    public function recordIdempotencyConflict(
        string $originalCorrelationId,
        string $replayCorrelationId,
        string $path,
        ?string $idempotencyKey = null,
    ): void {
        $flow = $this->flowFromPath($path);

        $this->record([
            'flow' => ($flow ?? OtpMovementFlow::AkubicaLogin)->value,
            'operation' => 'idempotency',
            'stage' => OtpMovementStage::IdempotencyConflict->value,
            'status' => OtpMovementStatus::Failed->value,
            'correlation_id' => $replayCorrelationId,
            'endpoint' => $path,
            'http_status' => 409,
            'is_idempotency_conflict' => true,
            'idempotency_key' => $idempotencyKey,
            'error_code' => 'IDEMPOTENCY_KEY_CONFLICT',
            'technical_message' => 'La Idempotency-Key ya fue usada con un payload diferente.',
            'meta' => [
                'original_correlation_id' => $originalCorrelationId,
            ],
        ]);
    }

    public function recordIdempotencyUncertain(
        string $originalCorrelationId,
        string $replayCorrelationId,
        string $path,
        ?string $idempotencyKey = null,
    ): void {
        $flow = $this->flowFromPath($path);

        $this->record([
            'flow' => ($flow ?? OtpMovementFlow::AkubicaLogin)->value,
            'operation' => 'idempotency',
            'stage' => OtpMovementStage::IdempotencyUncertain->value,
            'status' => OtpMovementStatus::Failed->value,
            'correlation_id' => $replayCorrelationId,
            'endpoint' => $path,
            'http_status' => 409,
            'idempotency_key' => $idempotencyKey,
            'error_code' => 'IDEMPOTENCY_OPERATION_UNCERTAIN',
            'technical_message' => 'Resultado de idempotencia incierto; no se confirmó ejecución nueva.',
            'meta' => [
                'original_correlation_id' => $originalCorrelationId,
            ],
        ]);
    }

    /**
     * @return array{0: OtpMovementStage, 1: OtpMovementStatus}
     */
    private function mapDeliveryEvent(string $logEvent, ?string $resultClass): array
    {
        if ($logEvent === 'otp_delivery_duplicate_suppressed') {
            return [OtpMovementStage::DeliveryDuplicateSuppressed, OtpMovementStatus::InProgress];
        }

        if ($logEvent === 'otp_delivery_suppressed') {
            return [OtpMovementStage::DeliverySuppressed, OtpMovementStatus::Failed];
        }

        if ($logEvent === 'otp_delivery_fallback') {
            $accepted = $resultClass === OtpDeliveryResultClass::FallbackAccepted->value;

            return [
                OtpMovementStage::DeliveryFallback,
                $accepted ? OtpMovementStatus::Sent : OtpMovementStatus::Failed,
            ];
        }

        $accepted = $resultClass === OtpDeliveryResultClass::Accepted->value;
        if ($accepted) {
            return [OtpMovementStage::DeliveryAccepted, OtpMovementStatus::Sent];
        }

        if ($resultClass === OtpDeliveryResultClass::Suppressed->value) {
            return [OtpMovementStage::DeliverySkipped, OtpMovementStatus::InProgress];
        }

        return [OtpMovementStage::DeliveryFailed, OtpMovementStatus::Failed];
    }

    private function deliveryTechnicalMessage(string $logEvent, ?string $resultClass): string
    {
        return match ($logEvent) {
            'otp_delivery_duplicate_suppressed' => 'Entrega duplicada suprimida por reserva de operación.',
            'otp_delivery_suppressed' => 'Entrega suprimida: challenge obsoleto o no pendiente.',
            'otp_delivery_fallback' => $resultClass === OtpDeliveryResultClass::FallbackAccepted->value
                ? 'Fallback a email aceptado por el canal mail.'
                : 'Fallback a email fallido.',
            default => $resultClass === OtpDeliveryResultClass::Accepted->value
                ? 'Proveedor aceptó la solicitud de envío; entrega final al dispositivo no confirmada.'
                : 'El proveedor rechazó o no aceptó la solicitud de envío.',
        };
    }

    private function flowFromPath(string $path): ?OtpMovementFlow
    {
        return match (true) {
            str_contains($path, 'login') => OtpMovementFlow::AkubicaLogin,
            str_contains($path, 'register') => OtpMovementFlow::AkubicaRegister,
            str_contains($path, 'results/step-up') => OtpMovementFlow::StepUpResults,
            str_contains($path, 'invoices') && str_contains($path, 'step-up') => OtpMovementFlow::StepUpInvoices,
            default => null,
        };
    }

    private function resolveMovementKey(?string $correlationId, ?string $challengePublicId): ?string
    {
        if (is_string($correlationId) && $correlationId !== '') {
            return 'corr:'.$correlationId;
        }

        if (is_string($challengePublicId) && $challengePublicId !== '') {
            return 'chal:'.$challengePublicId;
        }

        return null;
    }

    private function fingerprintIdempotencyKey(?string $rawKey): ?string
    {
        if (! is_string($rawKey) || $rawKey === '') {
            return null;
        }

        return substr(IdempotencyKey::hash($rawKey), 0, 16);
    }

    private function safeMessage(?string $message): ?string
    {
        if (! is_string($message) || $message === '') {
            return null;
        }

        return mb_substr($message, 0, 512);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>|null
     */
    private function safeMeta(?array $meta): ?array
    {
        if ($meta === null || $meta === []) {
            return null;
        }

        unset($meta['otp_code'], $meta['plain_code'], $meta['code'], $meta['token'], $meta['secret']);

        return $meta;
    }
}
