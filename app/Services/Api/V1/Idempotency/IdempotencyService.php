<?php

namespace App\Services\Api\V1\Idempotency;

use App\Models\Api\V1\IdempotencyRecord;
use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Begin / complete / replay / reclaim helpers for API v1 HTTP idempotency.
 *
 * Concurrency barrier: unique (actor_key, method, path, key_hash).
 * Never auto-reexecutes expired processing leases (failed_uncertain).
 */
final class IdempotencyService
{
    /** @var list<string> */
    private const PERSISTABLE_RESPONSE_HEADERS = [
        'Content-Type',
    ];

    public function __construct(
        private readonly IdempotencyRequestHasher $hasher,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('api_v1.idempotency.enabled', false);
    }

    public function ttlHours(): int
    {
        return max(1, (int) config('api_v1.idempotency.ttl_hours', 24));
    }

    public function leaseSeconds(): int
    {
        return max(5, (int) config('api_v1.idempotency.processing_lease_seconds', 60));
    }

    public function maxResponseBytes(): int
    {
        return max(1, (int) config('api_v1.idempotency.max_response_bytes', 65536));
    }

    public function normalizedPath(\Illuminate\Http\Request $request): string
    {
        return $this->hasher->normalizedPath($request);
    }

    public function requestHash(\Illuminate\Http\Request $request): string
    {
        return $this->hasher->hash($request);
    }

    /**
     * Attempt to create a processing record. Returns existing row on unique conflict.
     *
     * @return array{created: bool, record: IdempotencyRecord}
     */
    public function begin(
        string $actorKey,
        string $method,
        string $path,
        string $keyHash,
        string $requestHash,
        string $correlationId,
    ): array {
        $now = now();
        $lease = $now->copy()->addSeconds($this->leaseSeconds());
        $expires = $now->copy()->addHours($this->ttlHours());

        try {
            $record = IdempotencyRecord::query()->create([
                'actor_key' => $actorKey,
                'method' => strtoupper($method),
                'path' => $path,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'status' => IdempotencyRecord::STATUS_PROCESSING,
                'http_status' => null,
                'response_body' => null,
                'response_headers' => null,
                'correlation_id' => $correlationId,
                'lease_expires_at' => $lease,
                'expires_at' => $expires,
            ]);

            return ['created' => true, 'record' => $record];
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = IdempotencyRecord::query()
                ->where('actor_key', $actorKey)
                ->where('method', strtoupper($method))
                ->where('path', $path)
                ->where('key_hash', $keyHash)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return ['created' => false, 'record' => $existing];
        }
    }

    public function markCompleted(IdempotencyRecord $record, JsonResponse $response): void
    {
        $body = $response->getContent();
        if (! is_string($body)) {
            $this->markUncertain($record);

            return;
        }

        if (strlen($body) > $this->maxResponseBytes()) {
            $this->markUncertain($record);

            return;
        }

        $headers = $this->extractPersistableHeaders($response);

        $record->forceFill([
            'status' => IdempotencyRecord::STATUS_COMPLETED,
            'http_status' => $response->getStatusCode(),
            'response_body' => $body,
            'response_headers' => $headers,
            'lease_expires_at' => null,
        ])->save();
    }

    public function markFailedFinal(IdempotencyRecord $record, JsonResponse $response): void
    {
        $body = $response->getContent();
        if (! is_string($body) || strlen($body) > $this->maxResponseBytes()) {
            $this->markUncertain($record);

            return;
        }

        $record->forceFill([
            'status' => IdempotencyRecord::STATUS_FAILED_FINAL,
            'http_status' => $response->getStatusCode(),
            'response_body' => $body,
            'response_headers' => $this->extractPersistableHeaders($response),
            'lease_expires_at' => null,
        ])->save();
    }

    public function markUncertain(IdempotencyRecord $record): void
    {
        if ($record->status === IdempotencyRecord::STATUS_COMPLETED
            || $record->status === IdempotencyRecord::STATUS_FAILED_FINAL
            || $record->status === IdempotencyRecord::STATUS_FAILED_UNCERTAIN
        ) {
            return;
        }

        $record->forceFill([
            'status' => IdempotencyRecord::STATUS_FAILED_UNCERTAIN,
            'http_status' => null,
            'response_body' => null,
            'response_headers' => null,
            'lease_expires_at' => null,
        ])->save();
    }

    /**
     * Atomically transition expired processing → failed_uncertain.
     * Returns true when this caller performed (or observed) the uncertain state.
     */
    public function markExpiredProcessingUncertain(IdempotencyRecord $record): bool
    {
        $updated = IdempotencyRecord::query()
            ->whereKey($record->id)
            ->where('status', IdempotencyRecord::STATUS_PROCESSING)
            ->where(function ($q) {
                $q->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->update([
                'status' => IdempotencyRecord::STATUS_FAILED_UNCERTAIN,
                'http_status' => null,
                'response_body' => null,
                'response_headers' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $record->refresh();

            return true;
        }

        $record->refresh();

        return $record->isFailedUncertain();
    }

    public function buildReplayResponse(IdempotencyRecord $record): Response
    {
        $status = (int) ($record->http_status ?: 200);
        $body = $record->response_body ?? '{}';

        $response = response($body, $status)->header('Content-Type', 'application/json');

        $storedHeaders = is_array($record->response_headers) ? $record->response_headers : [];
        foreach (self::PERSISTABLE_RESPONSE_HEADERS as $name) {
            if (isset($storedHeaders[$name]) && is_string($storedHeaders[$name])) {
                $response->headers->set($name, $storedHeaders[$name]);
            }
        }

        $response->headers->set(AkubicaCorrelationId::HEADER, $record->correlation_id);
        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    /**
     * Persist outcome of a first-execution response. Returns possibly unmodified response.
     */
    public function finalizeFromResponse(IdempotencyRecord $record, Response $response): Response
    {
        if (! $response instanceof JsonResponse) {
            $this->markUncertain($record);

            return $response;
        }

        $status = $response->getStatusCode();

        if ($status >= 500) {
            $this->markUncertain($record);

            return $response;
        }

        if ($status >= 200 && $status < 300) {
            $this->markCompleted($record, $response);

            return $response;
        }

        if ($status >= 400 && $status < 500) {
            // Domain 4xx after actor resolved — replayable as failed_final.
            $this->markFailedFinal($record, $response);

            return $response;
        }

        $this->markUncertain($record);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function extractPersistableHeaders(JsonResponse $response): array
    {
        $out = [];
        foreach (self::PERSISTABLE_RESPONSE_HEADERS as $name) {
            $value = $response->headers->get($name);
            if (is_string($value) && $value !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    private function isUniqueViolation(Throwable $e): bool
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return true;
        }

        if ($e instanceof QueryException) {
            $code = (string) $e->getCode();
            $sqlState = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            $message = $e->getMessage();

            // MySQL 1062 / SQLSTATE 23000; SQLite UNIQUE
            if ($driverCode === 1062 || $sqlState === '23000' || $code === '23000') {
                return true;
            }

            if (str_contains($message, 'UNIQUE constraint failed')
                || str_contains($message, 'Duplicate entry')
            ) {
                return true;
            }
        }

        return false;
    }
}
