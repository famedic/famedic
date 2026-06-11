<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

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

        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status = 400,
        ?array $fields = null,
        mixed $details = null,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
        ], $status);
    }
}
