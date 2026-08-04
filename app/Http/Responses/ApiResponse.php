<?php

namespace App\Http\Responses;

use App\Support\Api\V1\AkubicaCorrelationId;
use App\Support\Api\V1\ApiErrorRetryability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        if ($data === null) {
            $data = new \stdClass;
        }

        if ($message !== null && is_array($data)) {
            $data['message'] = $message;
        }

        $response = response()->json([
            'success' => true,
            'data' => $data,
        ], $status);

        return self::withCorrelationHeader($response);
    }

    public static function error(
        string $code,
        string $message,
        int $status = 400,
        ?array $fields = null,
        mixed $details = null,
        ?bool $retryable = null,
        ?string $correlationId = null,
    ): JsonResponse {
        $resolvedCorrelationId = $correlationId ?? AkubicaCorrelationId::currentOrGenerate();

        self::rememberCorrelationId($resolvedCorrelationId);

        $error = [
            'code' => $code,
            'message' => $message,
        ];

        // Optional keys omitted when null (legacy rule).
        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        if ($details !== null) {
            $error['details'] = $details;
        }

        // Additive P1-A6 fields — always present on errors.
        $error['retryable'] = $retryable ?? ApiErrorRetryability::isRetryable($code, $status);
        $error['correlation_id'] = $resolvedCorrelationId;

        $response = response()->json([
            'success' => false,
            'error' => $error,
        ], $status);

        return self::withCorrelationHeader($response, $resolvedCorrelationId);
    }

    private static function rememberCorrelationId(string $correlationId): void
    {
        $request = request();

        if ($request instanceof Request) {
            AkubicaCorrelationId::bind($request, $correlationId);
            // Keep logging context aligned when middleware did not run (e.g. unmatched route).
            \Illuminate\Support\Facades\Log::shareContext([
                'correlation_id' => $correlationId,
            ]);
        }
    }

    private static function withCorrelationHeader(JsonResponse $response, ?string $correlationId = null): JsonResponse
    {
        $id = $correlationId ?? AkubicaCorrelationId::currentOrGenerate();
        $response->headers->set(AkubicaCorrelationId::HEADER, $id);

        return $response;
    }
}
