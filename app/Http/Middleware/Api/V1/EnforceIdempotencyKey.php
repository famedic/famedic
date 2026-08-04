<?php

namespace App\Http\Middleware\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Models\Api\V1\IdempotencyRecord;
use App\Services\Api\V1\Idempotency\IdempotencyActorResolver;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Services\Api\V1\Idempotency\IdempotencyService;
use App\Support\Api\V1\AkubicaCorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Optional Idempotency-Key enforcement for selected API v1 write routes (phase 1).
 *
 * Must run after api.correlation and (for protected routes) auth:sanctum + api.customer.
 */
class EnforceIdempotencyKey
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly IdempotencyActorResolver $actors,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->idempotency->enabled()) {
            return $next($request);
        }

        $rawKey = $request->headers->get(IdempotencyKey::HEADER);
        if (! is_string($rawKey) || $rawKey === '') {
            return $next($request);
        }

        if (! IdempotencyKey::isValid($rawKey)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Los datos enviados no son válidos.',
                422,
                ['Idempotency-Key' => ['El encabezado Idempotency-Key no es válido.']],
            );
        }

        if ($request->isMethod('POST') && $this->looksLikeMultipart($request)) {
            // Phase 1 routes are JSON-only; do not persist multipart bodies.
            return $next($request);
        }

        $actorKey = $this->actors->resolve($request);
        if ($actorKey === null) {
            // Cannot safely bind actor (e.g. missing phone before validation) — no durable record.
            return $next($request);
        }

        $path = $this->idempotency->normalizedPath($request);
        $method = strtoupper($request->getMethod());
        $keyHash = IdempotencyKey::hash($rawKey);
        $requestHash = $this->idempotency->requestHash($request);
        $correlationId = AkubicaCorrelationId::fromRequest($request);

        $begin = $this->idempotency->begin(
            $actorKey,
            $method,
            $path,
            $keyHash,
            $requestHash,
            $correlationId,
        );

        $record = $begin['record'];

        if (! $begin['created']) {
            return $this->handleExisting($record, $requestHash, $correlationId);
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            $this->idempotency->markUncertain($record);
            throw $e;
        }

        return $this->idempotency->finalizeFromResponse($record, $response);
    }

    private function handleExisting(
        IdempotencyRecord $record,
        string $requestHash,
        string $replayCorrelationId,
    ): Response {
        if ($record->request_hash !== $requestHash) {
            Log::info('akubica_idempotency_key_conflict', [
                'original_correlation_id' => $record->correlation_id,
                'replay_correlation_id' => $replayCorrelationId,
                'path' => $record->path,
                'status' => $record->status,
            ]);

            return ApiResponse::error(
                'IDEMPOTENCY_KEY_CONFLICT',
                'La Idempotency-Key ya fue usada con un payload diferente.',
                409,
            );
        }

        if ($record->isFailedUncertain()) {
            return $this->uncertainResponse($record);
        }

        if ($record->isCompleted() || $record->isFailedFinal()) {
            if ($record->response_body === null || $record->http_status === null) {
                return $this->uncertainResponse($record);
            }

            Log::info('akubica_idempotency_replay', [
                'original_correlation_id' => $record->correlation_id,
                'replay_correlation_id' => $replayCorrelationId,
                'path' => $record->path,
                'http_status' => $record->http_status,
            ]);

            AkubicaCorrelationId::bind(request(), $record->correlation_id);

            return $this->idempotency->buildReplayResponse($record);
        }

        if ($record->isProcessing()) {
            if ($record->leaseIsActive()) {
                $retryAfter = 1;
                if ($record->lease_expires_at !== null) {
                    $retryAfter = max(1, $record->lease_expires_at->getTimestamp() - now()->getTimestamp());
                } else {
                    $retryAfter = $this->idempotency->leaseSeconds();
                }

                $response = ApiResponse::error(
                    'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                    'Ya hay una solicitud en curso con esta Idempotency-Key.',
                    409,
                );
                $response->headers->set('Retry-After', (string) $retryAfter);

                return $response;
            }

            // Expired lease: do NOT re-execute. Mark uncertain.
            $this->idempotency->markExpiredProcessingUncertain($record);

            Log::info('akubica_idempotency_operation_uncertain', [
                'original_correlation_id' => $record->correlation_id,
                'replay_correlation_id' => $replayCorrelationId,
                'path' => $record->path,
            ]);

            return $this->uncertainResponse($record->fresh() ?? $record);
        }

        return $this->uncertainResponse($record);
    }

    private function uncertainResponse(IdempotencyRecord $record): Response
    {
        // 409 groups idempotency key semantics (conflict / in-progress / uncertain).
        // retryable=false for the same key while the record remains within TTL.
        return ApiResponse::error(
            'IDEMPOTENCY_OPERATION_UNCERTAIN',
            'No se pudo confirmar el resultado final de la operación. Use una Idempotency-Key nueva si debe reintentar.',
            409,
            null,
            null,
            false,
            $record->correlation_id,
        );
    }

    private function looksLikeMultipart(Request $request): bool
    {
        $contentType = (string) $request->header('Content-Type', '');

        return str_contains(strtolower($contentType), 'multipart/form-data')
            || $request->allFiles() !== [];
    }
}
