<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptStatus;

class EfevooPay3dsResultClassifier
{
    public const CATEGORY_ISSUER_DECLINED = 'issuer_declined';

    public const CATEGORY_AUTHENTICATION_FAILED = 'authentication_failed';

    public const CATEGORY_CANCELLED = 'cancelled';

    public const CATEGORY_CANCELLED_BY_USER = 'cancelled_by_user';

    public const CATEGORY_CANCELLED_BY_PROVIDER = 'cancelled_by_provider';

    public const CATEGORY_CHALLENGE_EXPIRED = 'challenge_expired';

    public const CATEGORY_CARD_NOT_SUPPORTED = 'card_not_supported';

    public const CATEGORY_PROVIDER_TIMEOUT = 'provider_timeout';

    public const CATEGORY_PROVIDER_ERROR = 'provider_error';

    public const CATEGORY_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const CATEGORY_NETWORK_ERROR = 'network_error';

    public const CATEGORY_CONFIGURATION_ERROR = 'configuration_error';

    public const CATEGORY_TOKENIZATION_FAILED = 'tokenization_failed';

    public const CATEGORY_DUPLICATE_REQUEST = 'duplicate_request';

    public const CATEGORY_CONCURRENT_ATTEMPT = 'concurrent_attempt';

    public const CATEGORY_UNKNOWN = 'unknown';

    public const CATEGORY_SUCCESS = 'success';

    public const ORIGIN_USER = 'user';

    public const ORIGIN_ISSUER = 'issuer';

    public const ORIGIN_ACS = 'acs';

    public const ORIGIN_EFEVOOPAY = 'efevoopay';

    public const ORIGIN_FAMEDIC = 'famedic';

    public const ORIGIN_NETWORK = 'network';

    public const ORIGIN_UNKNOWN = 'unknown';

    public const CERTAINTY_CONFIRMED = 'confirmed';

    public const CERTAINTY_PROBABLE = 'probable';

    public const CERTAINTY_UNKNOWN = 'unknown';

    public static function providerLink(array $result): array
    {
        if ($result['success'] ?? false) {
            return self::classification(
                PaymentAuthenticationAttemptStatus::ChallengeRequired,
                'challenge_required',
                null,
                self::ORIGIN_EFEVOOPAY,
                self::CERTAINTY_CONFIRMED,
                false,
                false,
                false,
                $result
            );
        }

        $errorType = $result['error_type'] ?? null;
        $message = EfevooPayLogSanitizer::providerMessage($result['message'] ?? null);

        if (in_array($errorType, ['network', 'timeout'], true)) {
            return self::classification(
                PaymentAuthenticationAttemptStatus::ProviderConfirmationPending,
                $errorType === 'timeout' ? self::CATEGORY_PROVIDER_TIMEOUT : self::CATEGORY_NETWORK_ERROR,
                self::ORIGIN_NETWORK,
                self::ORIGIN_NETWORK,
                self::CERTAINTY_UNKNOWN,
                false,
                false,
                true,
                $result,
                $message
            );
        }

        if ($errorType === 'system') {
            return self::classification(
                PaymentAuthenticationAttemptStatus::TechnicalError,
                self::CATEGORY_CONFIGURATION_ERROR,
                self::ORIGIN_FAMEDIC,
                self::ORIGIN_FAMEDIC,
                self::CERTAINTY_CONFIRMED,
                true,
                true,
                false,
                $result,
                $message
            );
        }

        return self::classification(
            PaymentAuthenticationAttemptStatus::Declined,
            self::CATEGORY_PROVIDER_ERROR,
            self::ORIGIN_EFEVOOPAY,
            self::ORIGIN_EFEVOOPAY,
            self::CERTAINTY_PROBABLE,
            true,
            true,
            false,
            $result,
            $message
        );
    }

    public static function providerStatus(?string $payloadStatus, ?string $providerCode = null, ?string $message = null): array
    {
        $status = strtolower((string) $payloadStatus);

        return match ($status) {
            'authenticated', 'approved' => self::classification(
                PaymentAuthenticationAttemptStatus::Authenticated,
                null,
                self::ORIGIN_EFEVOOPAY,
                self::ORIGIN_EFEVOOPAY,
                self::CERTAINTY_CONFIRMED,
                false,
                false,
                false,
                ['status' => $status, 'code' => $providerCode],
                $message
            ),
            'completed' => self::classification(
                PaymentAuthenticationAttemptStatus::Completed,
                self::CATEGORY_SUCCESS,
                self::ORIGIN_EFEVOOPAY,
                self::ORIGIN_EFEVOOPAY,
                self::CERTAINTY_CONFIRMED,
                true,
                false,
                false,
                ['status' => $status, 'code' => $providerCode],
                $message
            ),
            'pending' => self::classification(
                PaymentAuthenticationAttemptStatus::Pending,
                null,
                self::ORIGIN_EFEVOOPAY,
                self::ORIGIN_EFEVOOPAY,
                self::CERTAINTY_CONFIRMED,
                false,
                false,
                false,
                ['status' => $status, 'code' => $providerCode],
                $message
            ),
            'declined' => self::classification(
                PaymentAuthenticationAttemptStatus::Declined,
                self::CATEGORY_AUTHENTICATION_FAILED,
                self::ORIGIN_UNKNOWN,
                self::ORIGIN_UNKNOWN,
                self::CERTAINTY_PROBABLE,
                true,
                true,
                false,
                ['status' => $status, 'code' => $providerCode],
                $message
            ),
            'rejected' => self::classification(
                PaymentAuthenticationAttemptStatus::Declined,
                self::CATEGORY_AUTHENTICATION_FAILED,
                self::ORIGIN_UNKNOWN,
                self::ORIGIN_UNKNOWN,
                self::CERTAINTY_PROBABLE,
                true,
                true,
                false,
                ['status' => $status, 'code' => $providerCode],
                $message
            ),
            'cancelled', 'canceled' => self::classification(
                PaymentAuthenticationAttemptStatus::Cancelled,
                self::CATEGORY_CANCELLED,
                self::ORIGIN_UNKNOWN,
                self::ORIGIN_UNKNOWN,
                self::CERTAINTY_UNKNOWN,
                true,
                true,
                false,
                ['status' => $status, 'code' => $providerCode],
                $message
            ),
            'expired' => self::classification(
                PaymentAuthenticationAttemptStatus::Expired,
                self::CATEGORY_CHALLENGE_EXPIRED,
                self::ORIGIN_ACS,
                self::ORIGIN_ACS,
                self::CERTAINTY_CONFIRMED,
                true,
                true,
                false,
                ['status' => $status, 'code' => $providerCode],
                $message
            ),
            default => self::classification(
                PaymentAuthenticationAttemptStatus::Unknown,
                self::CATEGORY_UNKNOWN,
                self::ORIGIN_UNKNOWN,
                self::ORIGIN_UNKNOWN,
                self::CERTAINTY_UNKNOWN,
                false,
                false,
                true,
                ['status' => $status, 'code' => $providerCode],
                $message
            ),
        };
    }

    public static function tokenization(array $result): array
    {
        if ($result['success'] ?? false) {
            return self::classification(
                PaymentAuthenticationAttemptStatus::Completed,
                null,
                self::ORIGIN_EFEVOOPAY,
                self::ORIGIN_EFEVOOPAY,
                self::CERTAINTY_CONFIRMED,
                true,
                false,
                false,
                $result
            );
        }

        $adminMessage = is_string($result['admin_message'] ?? null) ? $result['admin_message'] : null;
        $providerMessage = $adminMessage
            ?? ($result['provider_message'] ?? $result['message'] ?? null);
        $hasProviderEvidence = filled($result['error_code'] ?? null)
            || filled($result['provider_code'] ?? null)
            || filled($result['provider_code_string'] ?? null)
            || filled($providerMessage);
        $failureOrigin = ($result['normalized_reason'] ?? null) === 'invalid_track_data'
            || $hasProviderEvidence
            ? self::ORIGIN_EFEVOOPAY
            : self::ORIGIN_UNKNOWN;

        return self::classification(
            PaymentAuthenticationAttemptStatus::TechnicalError,
            self::CATEGORY_TOKENIZATION_FAILED,
            $failureOrigin,
            $failureOrigin,
            $hasProviderEvidence ? self::CERTAINTY_CONFIRMED : self::CERTAINTY_UNKNOWN,
            true,
            true,
            false,
            $result,
            $providerMessage
        );
    }

    public static function localExpiration(): array
    {
        return array_merge(
            self::classification(
                PaymentAuthenticationAttemptStatus::Expired,
                self::CATEGORY_CHALLENGE_EXPIRED,
                self::ORIGIN_UNKNOWN,
                self::ORIGIN_UNKNOWN,
                self::CERTAINTY_PROBABLE,
                true,
                true,
                false,
                ['status' => 'expired']
            ),
            [
                'metadata' => [
                    'detected_by' => 'famedic',
                ],
            ]
        );
    }

    /**
     * Use only when the provider payload explicitly attributes the cancellation
     * to the ACS/provider, not for a generic cancelled/canceled status.
     */
    public static function explicitProviderCancellation(?string $providerCode = null, ?string $message = null): array
    {
        return self::classification(
            PaymentAuthenticationAttemptStatus::Cancelled,
            self::CATEGORY_CANCELLED_BY_PROVIDER,
            self::ORIGIN_EFEVOOPAY,
            self::ORIGIN_EFEVOOPAY,
            self::CERTAINTY_CONFIRMED,
            true,
            true,
            false,
            ['status' => 'cancelled', 'code' => $providerCode],
            $message
        );
    }

    private static function classification(
        PaymentAuthenticationAttemptStatus $internalStatus,
        ?string $category,
        ?string $origin,
        ?string $failureOrigin,
        string $certainty,
        bool $terminal,
        bool $retryAllowed,
        bool $requiresProviderConfirmation,
        array $providerData,
        mixed $message = null
    ): array {
        return [
            'internal_status' => $internalStatus,
            'result_category' => $category,
            'origin' => $origin,
            'failure_origin' => $failureOrigin,
            'failure_certainty' => $certainty,
            'provider_status' => self::stringOrNull(data_get($providerData, 'status') ?? data_get($providerData, 'data.payload.status')),
            'provider_code' => self::stringOrNull(data_get($providerData, 'code') ?? data_get($providerData, 'raw.data.status.code') ?? data_get($providerData, 'error_code')),
            'provider_message' => EfevooPayLogSanitizer::providerMessage($message ?? data_get($providerData, 'message')),
            'terminal' => $terminal,
            'retry_allowed' => $retryAllowed,
            'requires_provider_confirmation' => $requiresProviderConfirmation,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return EfevooPayLogSanitizer::providerMessage((string) $value);
    }
}
