<?php

namespace App\Http\Responses\Api\V1;

use App\Exceptions\Otp\OtpChallengeConsumedException;
use App\Exceptions\Otp\OtpChallengeException;
use App\Exceptions\Otp\OtpChallengeExpiredException;
use App\Exceptions\Otp\OtpChallengeInvalidatedException;
use App\Exceptions\Otp\OtpChallengeMismatchException;
use App\Exceptions\Otp\OtpChallengeNotFoundException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpInvalidCodeException;
use App\Exceptions\Otp\OtpRateLimitExceededException;
use App\Exceptions\Otp\OtpTemporarilyBlockedException;
use App\Exceptions\Otp\RegistrationIntentException;
use App\Exceptions\Otp\RegistrationIntentExpiredException;
use App\Exceptions\Otp\RegistrationIntentInvalidStateException;
use App\Exceptions\Otp\RegistrationIntentNotFoundException;
use App\Exceptions\Otp\RegistrationIntentPayloadException;
use App\Http\Responses\ApiResponse;
use App\Services\Otp\OtpRateLimitDecision;
use Illuminate\Http\JsonResponse;

/**
 * Maps OTP domain exceptions to Akubica API v1 JSON responses.
 * Keeps domain services free of HTTP/JSON concerns.
 */
final class OtpExceptionHttpMapper
{
    public function toResponse(\Throwable $e): JsonResponse
    {
        if ($e instanceof OtpRateLimitExceededException || $e instanceof OtpTemporarilyBlockedException) {
            return $this->rateLimitResponse($e->decision);
        }

        if ($e instanceof OtpConfigurationException) {
            return ApiResponse::error(
                'OTP_CONFIGURATION_INVALID',
                'El inicio de sesion OTP no esta disponible.',
                503,
            );
        }

        if ($e instanceof OtpChallengeNotFoundException) {
            return ApiResponse::error(
                'NO_ACTIVE_CODE',
                'No hay un codigo activo para esta solicitud.',
                422,
            );
        }

        if ($e instanceof OtpChallengeExpiredException) {
            return ApiResponse::error(
                'CODE_EXPIRED',
                'El codigo expiro. Solicita uno nuevo.',
                422,
            );
        }

        if ($e instanceof OtpChallengeConsumedException) {
            return ApiResponse::error(
                'CODE_ALREADY_USED',
                'El codigo ya fue utilizado. Solicita uno nuevo.',
                422,
            );
        }

        if ($e instanceof OtpChallengeInvalidatedException) {
            $code = str_contains(strtolower($e->getMessage()), 'intentos')
                || str_contains(strtolower($e->getMessage()), 'agotaron')
                ? 'ATTEMPTS_EXHAUSTED'
                : 'CODE_INVALIDATED';

            return ApiResponse::error(
                $code,
                $code === 'ATTEMPTS_EXHAUSTED'
                    ? 'Se agotaron los intentos. Solicita un codigo nuevo.'
                    : 'El codigo ya no es valido. Solicita uno nuevo.',
                422,
            );
        }

        if ($e instanceof OtpInvalidCodeException) {
            return ApiResponse::error(
                'INVALID_CODE',
                'El codigo ingresado no es valido.',
                422,
            );
        }

        if ($e instanceof OtpChallengeMismatchException) {
            return ApiResponse::error(
                'INVALID_CODE',
                'El codigo ingresado no es valido.',
                422,
            );
        }

        if ($e instanceof RegistrationIntentExpiredException) {
            return ApiResponse::error(
                'CODE_EXPIRED',
                'El codigo expiro. Solicita uno nuevo.',
                422,
            );
        }

        if ($e instanceof RegistrationIntentNotFoundException) {
            return ApiResponse::error(
                'NO_ACTIVE_CODE',
                'No hay un codigo activo para esta solicitud.',
                422,
            );
        }

        if ($e instanceof RegistrationIntentPayloadException) {
            return ApiResponse::error(
                'INVALID_CODE',
                'El codigo ingresado no es valido.',
                422,
            );
        }

        if ($e instanceof RegistrationIntentInvalidStateException) {
            return ApiResponse::error(
                'CODE_INVALIDATED',
                'El codigo ya no es valido. Solicita uno nuevo.',
                422,
            );
        }

        if ($e instanceof RegistrationIntentException) {
            return ApiResponse::error(
                'INVALID_CODE',
                'El codigo ingresado no es valido.',
                422,
            );
        }

        if ($e instanceof OtpChallengeException) {
            return ApiResponse::error(
                $e->errorCode !== '' ? $e->errorCode : 'OTP_CHALLENGE_ERROR',
                'No se pudo procesar la verificacion.',
                422,
            );
        }

        return ApiResponse::error(
            'OTP_CHALLENGE_ERROR',
            'No se pudo procesar la verificacion.',
            500,
        );
    }

    private function rateLimitResponse(OtpRateLimitDecision $decision): JsonResponse
    {
        $retryAfter = max(1, (int) ($decision->retryAfterSeconds ?? 60));
        $errorCode = $decision->errorCode ?? OtpRateLimitDecision::CODE_RATE_LIMITED;

        $details = [
            'retry_after' => $retryAfter,
        ];

        if ($decision->availableAt !== null) {
            $details['available_at'] = $decision->availableAt->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $response = ApiResponse::error(
            $errorCode,
            $decision->publicMessage !== ''
                ? $decision->publicMessage
                : 'Demasiados intentos. Intenta mas tarde.',
            429,
            null,
            $details,
        );

        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }
}
