<?php

namespace App\Http\Middleware\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Models\AtixPhoneLookupOperation;
use Closure;
use Illuminate\Http\Request;
use App\Support\Api\V1\AkubicaCorrelationId;
use App\Services\Api\V1\Integrations\AtixAuditRecorder;
use App\Services\Api\V1\Integrations\AtixPayloadFingerprint;
use App\Services\Api\V1\Integrations\AtixResponseSerializer;
use Illuminate\Database\QueryException;

final class EnforceAtixPhoneLookupIdempotency
{
    public function __construct(
        private readonly AtixPayloadFingerprint $fingerprints,
        private readonly AtixResponseSerializer $responses,
        private readonly AtixAuditRecorder $audit,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $event = (string) $request->input('channel_event_id', '');
        if (! preg_match('/^[A-Za-z0-9._-]{8,128}$/', $key)) {
            return $this->failure($request, 'ATIX_IDEMPOTENCY_INVALID', 'Idempotency-Key invalido.', 422, false);
        }
        if ($event === '') {
            return $this->failure($request, 'ATIX_IDEMPOTENCY_REQUIRED', 'channel_event_id es obligatorio.', 422, false);
        }

        $integration = (string) $request->attributes->get('atix.integration_key_hash');
        $eventHash = hash('sha256', $event);
        $keyHash = hash('sha256', $key);
        $fingerprint = $this->fingerprints->hash($request->all());
        $existing = AtixPhoneLookupOperation::query()
            ->where('integration_key_hash', $integration)
            ->where('expires_at', '>', now())
            ->where(function ($query) use ($eventHash, $keyHash) {
                $query->where('channel_event_hash', $eventHash)->orWhere('idempotency_key_hash', $keyHash);
            })->first();

        if ($existing !== null) {
            if ($existing->fingerprint !== $fingerprint) {
                return $this->failure($request, 'ATIX_EVENT_REPLAY_CONFLICT', 'El evento ya fue procesado con otra carga.', 409, false);
            }
            if ($existing->status === AtixPhoneLookupOperation::STATUS_PROCESSING) {
                return $this->failure($request, 'ATIX_OPERATION_IN_PROGRESS', 'La operacion sigue en proceso.', 409, true);
            }
            if ($existing->status === AtixPhoneLookupOperation::STATUS_FAILED_UNCERTAIN) {
                return $this->failure($request, 'ATIX_OPERATION_UNCERTAIN', 'No es posible confirmar el resultado de la operacion.', 409, false);
            }
            $response = response()->json($this->responses->decode($existing->response_body), $existing->http_status ?: 200);
            foreach ((array) $existing->response_headers as $name => $value) $response->headers->set($name, $value);
            $response->headers->set('Idempotency-Replayed', 'true');
            return $response;
        }

        try {
            $operation = AtixPhoneLookupOperation::create([
                'integration_key_hash' => $integration,
                'channel_event_hash' => $eventHash,
                'fingerprint' => $fingerprint,
                'idempotency_key_hash' => $keyHash,
                'status' => AtixPhoneLookupOperation::STATUS_PROCESSING,
                'correlation_id' => AkubicaCorrelationId::currentOrGenerate(),
                'expires_at' => now()->addHours(min(24, (int) config('atix.sandbox.binding_ttl_hours', 24))),
            ]);
        } catch (QueryException $exception) {
            $active = AtixPhoneLookupOperation::query()
                ->where('integration_key_hash', $integration)
                ->where('expires_at', '>', now())
                ->where(function ($query) use ($eventHash, $keyHash) {
                    $query->where('channel_event_hash', $eventHash)->orWhere('idempotency_key_hash', $keyHash);
                })->exists();
            if ($active) {
                return $this->failure($request, 'ATIX_OPERATION_IN_PROGRESS', 'La operacion sigue en proceso.', 409, true);
            }
            report($exception);
            return $this->failure($request, 'ATIX_INFRASTRUCTURE_UNAVAILABLE', 'Servicio temporalmente no disponible.', 503, true);
        }

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            if ($status >= 500) {
                $operation->update(['status' => AtixPhoneLookupOperation::STATUS_FAILED_UNCERTAIN]);
                return $response;
            }
            $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $serializedBody = $this->responses->encode($body);
            $operation->update([
                'status' => $status >= 400 ? AtixPhoneLookupOperation::STATUS_FAILED_FINAL : AtixPhoneLookupOperation::STATUS_COMPLETED,
                'http_status' => $status,
                'response_body' => $serializedBody,
                'response_headers' => ['X-Correlation-ID' => (string) $response->headers->get('X-Correlation-ID')],
            ]);
            $response->setContent($serializedBody);
            return $response;
        } catch (\Throwable $exception) {
            $operation->update(['status' => AtixPhoneLookupOperation::STATUS_FAILED_UNCERTAIN]);
            throw $exception;
        }
    }

    private function failure(Request $request, string $code, string $message, int $status, bool $retryable): mixed
    {
        $this->audit->record($request, 'rejected', $status, $code);
        return ApiResponse::error($code, $message, $status, null, null, $retryable);
    }
}
