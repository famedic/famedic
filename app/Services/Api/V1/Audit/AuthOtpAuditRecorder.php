<?php

namespace App\Services\Api\V1\Audit;

use App\Models\OtpChallenge;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Support\Api\V1\ApiErrorRetryability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Typed Auth/OTP audit emitter (Block 2).
 *
 * Controllers call this after confirmed outcomes. Fail-soft via AuditEventWriter.
 * Never accepts Request/Response bodies as metadata. Never stores OTP, phone,
 * email, bearer, public challenge/grant credentials, or Idempotency-Key.
 */
final class AuthOtpAuditRecorder
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_REGISTER = 'register';

    public const STEP_UP_PURPOSE_RESULTS = 'results';

    public const STEP_UP_PURPOSE_INVOICES = 'invoices';

    public function __construct(
        private readonly AuditEventWriter $writer,
        private readonly AuditActorResolver $actors,
    ) {}

    public function enabled(): bool
    {
        return $this->writer->enabled();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordLoginCodeRequested(
        Request $request,
        string $phoneComparisonKey,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $challengePublicId = null,
        bool $isDecoy = false,
        bool $isResend = false,
        array $metadata = [],
    ): void {
        $this->emit(
            eventName: $isResend
                ? AuditEventDefinitions::EVENT_LOGIN_CODE_RESENT
                : AuditEventDefinitions::EVENT_LOGIN_CODE_REQUESTED,
            outcome: $outcome,
            actor: $this->safePublicActor(self::PURPOSE_LOGIN, $phoneComparisonKey),
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            metadata: array_merge([
                'delivery_channel' => 'sms',
                'delivery_result_class' => $this->deliveryResultClass($outcome, $isDecoy),
                'is_resend' => $isResend,
                'is_decoy' => $isDecoy,
                'challenge_row_id' => $this->challengeRowId($challengePublicId),
            ], $metadata),
            markTerminal: false,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordLoginVerified(
        Request $request,
        string $phoneComparisonKey,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?User $authenticatedUser = null,
        ?string $challengePublicId = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $actor = null;
        if ($outcome === AuditOutcome::SUCCEEDED && $authenticatedUser !== null) {
            // Bind the just-issued Sanctum PAT when present (not on the HTTP request yet).
            $latestPat = $authenticatedUser->tokens()->latest('id')->first();
            if ($latestPat instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $authenticatedUser->withAccessToken($latestPat);
            }
            $actor = $this->safeAuthenticatedActor($request, $authenticatedUser);
        }
        $actor ??= $this->safePublicActor(self::PURPOSE_LOGIN, $phoneComparisonKey);

        $this->emit(
            eventName: AuditEventDefinitions::EVENT_LOGIN_VERIFIED,
            outcome: $outcome,
            actor: $actor,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            metadata: array_merge([
                'delivery_channel' => 'sms',
                'session_issued' => $outcome === AuditOutcome::SUCCEEDED,
                'challenge_row_id' => $this->challengeRowId($challengePublicId),
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordRegistrationCodeRequested(
        Request $request,
        string $normalizedMaterial,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $challengePublicId = null,
        bool $isDecoy = false,
        bool $isResend = false,
        array $metadata = [],
    ): void {
        $this->emit(
            eventName: $isResend
                ? AuditEventDefinitions::EVENT_REGISTRATION_CODE_RESENT
                : AuditEventDefinitions::EVENT_REGISTRATION_CODE_REQUESTED,
            outcome: $outcome,
            actor: $this->safePublicActor(self::PURPOSE_REGISTER, $normalizedMaterial),
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            metadata: array_merge([
                'delivery_channel' => 'sms',
                'delivery_result_class' => $this->deliveryResultClass($outcome, $isDecoy),
                'is_resend' => $isResend,
                'is_decoy' => $isDecoy,
                'challenge_row_id' => $this->challengeRowId($challengePublicId),
            ], $metadata),
            markTerminal: false,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordRegistrationCompleted(
        Request $request,
        string $normalizedMaterial,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?User $provisionedUser = null,
        ?string $challengePublicId = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $actor = null;
        if (in_array($outcome, [AuditOutcome::SUCCEEDED, AuditOutcome::UNCERTAIN], true)
            && $provisionedUser !== null
        ) {
            $latestPat = $provisionedUser->tokens()->latest('id')->first();
            if ($latestPat instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $provisionedUser->withAccessToken($latestPat);
            }
            $actor = $this->safeAuthenticatedActor($request, $provisionedUser);
        }
        $actor ??= $this->safePublicActor(self::PURPOSE_REGISTER, $normalizedMaterial);

        $this->emit(
            eventName: AuditEventDefinitions::EVENT_REGISTRATION_COMPLETED,
            outcome: $outcome,
            actor: $actor,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            metadata: array_merge([
                'delivery_channel' => 'sms',
                'session_issued' => $outcome === AuditOutcome::SUCCEEDED && $errorCode === null,
                'challenge_row_id' => $this->challengeRowId($challengePublicId),
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordStepUpRequested(
        Request $request,
        string $stepUpPurpose,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceType = null,
        ?string $resourceKey = null,
        ?string $challengePublicId = null,
        ?int $orderRowId = null,
        ?int $laboratoryPurchaseRowId = null,
        ?int $invoiceRowId = null,
        array $metadata = [],
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_STEP_UP_REQUESTED,
            outcome: $outcome,
            actor: $this->safeAuthenticatedActor($request),
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'purpose' => $stepUpPurpose,
                'delivery_channel' => 'sms',
                'delivery_result_class' => $this->deliveryResultClass($outcome, false),
                'is_resend' => false,
                'challenge_row_id' => $this->challengeRowId($challengePublicId),
                'order_row_id' => $orderRowId,
                'laboratory_purchase_row_id' => $laboratoryPurchaseRowId,
                'invoice_row_id' => $invoiceRowId,
            ], $metadata),
            markTerminal: false,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordStepUpVerified(
        Request $request,
        string $stepUpPurpose,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceType = null,
        ?string $resourceKey = null,
        ?string $challengePublicId = null,
        ?string $grantPublicId = null,
        ?int $orderRowId = null,
        ?int $laboratoryPurchaseRowId = null,
        ?int $invoiceRowId = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_STEP_UP_VERIFIED,
            outcome: $outcome,
            actor: $this->safeAuthenticatedActor($request),
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'purpose' => $stepUpPurpose,
                'delivery_channel' => 'sms',
                'challenge_row_id' => $this->challengeRowId($challengePublicId),
                // Internal row only — never grant_public_id (credential-like).
                'step_up_row_id' => $this->stepUpRowId($grantPublicId),
                'order_row_id' => $orderRowId,
                'laboratory_purchase_row_id' => $laboratoryPurchaseRowId,
                'invoice_row_id' => $invoiceRowId,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * Map a mapped OTP JsonResponse into outcome + error fields.
     *
     * @return array{outcome: string, http_status: int, error_code: string|null, retryable: bool|null}
     */
    public function classifyErrorResponse(JsonResponse $response): array
    {
        $status = $response->getStatusCode();
        $payload = $response->getData(true);
        $errorCode = is_array($payload) && is_array($payload['error'] ?? null)
            ? (is_string($payload['error']['code'] ?? null) ? $payload['error']['code'] : null)
            : null;

        $retryable = $errorCode !== null
            ? ApiErrorRetryability::isRetryable($errorCode, $status)
            : null;

        $outcome = match (true) {
            $status >= 500 => AuditOutcome::FAILED,
            $status === 429 => AuditOutcome::REJECTED,
            $status >= 400 => AuditOutcome::REJECTED,
            default => AuditOutcome::FAILED,
        };

        // Delivery / temporary infra: failed (not a client rejection).
        if (in_array($errorCode, ['DELIVERY_FAILED', 'OTP_TEMPORARY_UNAVAILABLE', 'OTP_CONFIGURATION_INVALID', 'FEATURE_DISABLED'], true)) {
            $outcome = AuditOutcome::FAILED;
        }

        return [
            'outcome' => $outcome,
            'http_status' => $status,
            'error_code' => $errorCode,
            'retryable' => $retryable,
        ];
    }

    /**
     * Build register actor material from already-normalized identity parts.
     */
    public function registerActorMaterial(string $phoneComparisonKey, string $emailComparisonKey): string
    {
        return $phoneComparisonKey.'|'.$emailComparisonKey;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function emit(
        string $eventName,
        string $outcome,
        ?AuditActor $actor,
        Request $request,
        int $httpStatus,
        ?string $errorCode,
        array $metadata,
        bool $markTerminal,
        ?string $resourceType = null,
        ?string $resourceKey = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        if ($actor === null) {
            return;
        }

        $context = ApiV1AuditContext::fromRequest($request);
        if ($context->actor() === null) {
            $context->setActor($actor);
        }

        // Drop null metadata entries before allowlist normalization.
        $cleanMeta = [];
        foreach ($metadata as $k => $v) {
            if ($v === null) {
                continue;
            }
            $cleanMeta[$k] = $v;
        }

        $retryable = $errorCode !== null
            ? ApiErrorRetryability::isRetryable($errorCode, $httpStatus)
            : ($httpStatus < 400 ? false : null);

        $this->writer->write([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'actor' => $actor,
            'context' => $context,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'retryable' => $retryable,
            'resource_type' => $resourceType,
            'resource_key' => $resourceKey,
            'metadata' => $cleanMeta,
            'ip_hash' => $this->actors->hashIp($request->ip()),
            'user_agent_hash' => $this->actors->hashUserAgent($request->userAgent()),
            'mark_terminal' => $markTerminal,
        ]);
    }

    private function safePublicActor(string $purpose, string $normalizedMaterial): ?AuditActor
    {
        try {
            return $this->actors->resolvePublic($purpose, $normalizedMaterial);
        } catch (InvalidArgumentException|Throwable) {
            return null;
        }
    }

    private function safeAuthenticatedActor(Request $request, ?User $user = null): ?AuditActor
    {
        try {
            if ($user !== null && $request->user() === null) {
                $request->setUserResolver(static fn () => $user);
            }

            return $this->actors->resolveAuthenticated($request);
        } catch (InvalidArgumentException|Throwable) {
            return null;
        }
    }

    private function challengeRowId(?string $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $id = OtpChallenge::query()->where('public_id', $publicId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function stepUpRowId(?string $grantPublicId): ?int
    {
        if (! is_string($grantPublicId) || $grantPublicId === '') {
            return null;
        }

        $id = OtpStepUpGrant::query()->where('public_id', $grantPublicId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function deliveryResultClass(string $outcome, bool $isDecoy): string
    {
        if ($isDecoy) {
            return 'decoy';
        }

        return match ($outcome) {
            AuditOutcome::SUCCEEDED => 'accepted',
            AuditOutcome::REJECTED => 'rejected',
            AuditOutcome::FAILED => 'failed',
            default => 'uncertain',
        };
    }
}
