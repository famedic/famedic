<?php

namespace App\Support\Api\V1;

/**
 * Central retryable classification for Akubica API v1 error codes (P1-A6).
 *
 * Default is non-retryable. Only explicitly transient / rate-limit codes are true.
 * HTTP status alone is never enough — unknown 500s stay false.
 */
final class ApiErrorRetryability
{
    /**
     * Domain codes that are safe for client retry (after Retry-After when present).
     *
     * @var list<string>
     */
    private const RETRYABLE_CODES = [
        'TOO_MANY_REQUESTS',
        'OTP_COOLDOWN',
        'OTP_RATE_LIMITED',
        'OTP_BLOCKED',
        'OTP_TEMPORARY_UNAVAILABLE',
        'DELIVERY_FAILED',
        'DOCUMENT_STORAGE_UNAVAILABLE',
        'CATALOG_UNAVAILABLE',
        'IDEMPOTENCY_REQUEST_IN_PROGRESS',
    ];

    /**
     * Explicit non-retryable codes (documentation / override safety).
     * Unknown codes fall through to false as well.
     *
     * @var list<string>
     */
    private const NON_RETRYABLE_CODES = [
        'VALIDATION_ERROR',
        'UNAUTHENTICATED',
        'FORBIDDEN',
        'NOT_FOUND',
        'INTERNAL_ERROR',
        'FEATURE_DISABLED',
        'OTP_CONFIGURATION_INVALID',
        'INVALID_CODE',
        'NO_ACTIVE_CODE',
        'CODE_EXPIRED',
        'CODE_ALREADY_USED',
        'CODE_INVALIDATED',
        'ATTEMPTS_EXHAUSTED',
        'OTP_MAX_ATTEMPTS',
        'LOGIN_REQUIRED',
        'EMAIL_ALREADY_REGISTERED',
        'PHONE_ALREADY_REGISTERED',
        'ORDER_NOT_FOUND',
        'INVOICE_NOT_FOUND',
        'RESULTS_NOT_AVAILABLE',
        'RESULT_NOT_READY',
        'INVOICE_NOT_READY',
        'STEP_UP_REQUIRED',
        'STEP_UP_GRANT_INVALID',
        'STEP_UP_EXPIRED',
        'STEP_UP_REVOKED',
        'SECURE_LINK_NOT_FOUND',
        'SECURE_LINK_EXPIRED',
        'SECURE_LINK_CONSUMED',
        'SECURE_LINK_REVOKED',
        'LAB_TEST_NOT_FOUND',
        'CART_ITEM_NOT_FOUND',
        'ITEM_ALREADY_IN_CART',
        'COUPON_NOT_FOUND',
        'COUPON_EXPIRED',
        'COUPON_NOT_APPLICABLE',
        'EMPTY_CART',
        'CHECKOUT_NOT_READY',
        'APPOINTMENT_REQUIRED',
        'APPOINTMENT_NOT_FOUND',
        'APPOINTMENT_ALREADY_EXISTS',
        'APPOINTMENT_NOT_REQUIRED',
        'INVOICE_ALREADY_EXISTS',
        'INVOICE_REQUEST_ALREADY_EXISTS',
        'ORDER_NOT_INVOICEABLE',
        'TAX_PROFILE_NOT_FOUND',
        'ADDRESS_NOT_FOUND',
        'CONTACT_NOT_FOUND',
        'RFC_ALREADY_EXISTS',
        'OTP_CHALLENGE_ERROR',
        'IDEMPOTENCY_KEY_CONFLICT',
        'IDEMPOTENCY_OPERATION_UNCERTAIN',
    ];

    public static function isRetryable(string $code, ?int $httpStatus = null): bool
    {
        if (in_array($code, self::RETRYABLE_CODES, true)) {
            return true;
        }

        if (in_array($code, self::NON_RETRYABLE_CODES, true)) {
            return false;
        }

        // Unknown domain codes: never invent retry behaviour from status alone.
        // 503 without a known transient code stays false (e.g. permanent FEATURE_DISABLED variants).
        unset($httpStatus);

        return false;
    }
}
