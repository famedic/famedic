<?php

namespace App\Services\Otp\Monitoring;

use App\Enums\Otp\OtpMovementFlow;
use App\Enums\Otp\OtpMovementStage;
use App\Enums\Otp\OtpMovementStatus;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Api\V1\Audit\AuditOutcome;
use Illuminate\Http\Request;

/**
 * Maps Auth/OTP controller outcomes into otp_movement_events.
 */
final class OtpMovementAuthBridge
{
    public function __construct(
        private readonly OtpMovementRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function fromLoginRequest(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $challengePublicId = null,
        bool $isDecoy = false,
        bool $isResend = false,
        ?string $destinationMasked = null,
        ?int $userId = null,
        array $metadata = [],
    ): void {
        [$stage, $status, $operation] = $this->mapRequestOutcome($outcome, $httpStatus, $errorCode, $isDecoy, $isResend);

        $this->recorder->record([
            'flow' => OtpMovementFlow::AkubicaLogin->value,
            'operation' => $operation,
            'stage' => $stage->value,
            'status' => $status->value,
            'correlation_id' => $request->header('X-Correlation-Id'),
            'challenge_public_id' => $challengePublicId,
            'destination_masked' => $destinationMasked,
            'user_id' => $userId,
            'channel' => 'sms',
            'endpoint' => $request->path(),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'is_decoy' => $isDecoy,
            'is_resend' => $isResend,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'otp_challenge_id' => $this->challengeRowId($challengePublicId),
            'technical_message' => $this->requestMessage($stage, $isDecoy, $errorCode, $metadata),
            'meta' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function fromLoginVerify(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $challengePublicId = null,
        ?User $user = null,
        array $metadata = [],
    ): void {
        [$stage, $status] = $this->mapVerifyOutcome($outcome, $httpStatus, $errorCode);

        $this->recorder->record([
            'flow' => OtpMovementFlow::AkubicaLogin->value,
            'operation' => 'verify',
            'stage' => $stage->value,
            'status' => $status->value,
            'correlation_id' => $request->header('X-Correlation-Id'),
            'challenge_public_id' => $challengePublicId,
            'user_id' => $user?->id,
            'customer_id' => $user?->customer?->id,
            'channel' => 'sms',
            'endpoint' => $request->path(),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'otp_challenge_id' => $this->challengeRowId($challengePublicId),
            'technical_message' => $this->verifyMessage($stage, $errorCode),
            'meta' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function fromRegisterRequest(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $challengePublicId = null,
        bool $isDecoy = false,
        bool $isResend = false,
        ?string $destinationMasked = null,
        array $metadata = [],
    ): void {
        [$stage, $status, $operation] = $this->mapRequestOutcome($outcome, $httpStatus, $errorCode, $isDecoy, $isResend);

        $this->recorder->record([
            'flow' => OtpMovementFlow::AkubicaRegister->value,
            'operation' => $operation,
            'stage' => $stage->value,
            'status' => $status->value,
            'correlation_id' => $request->header('X-Correlation-Id'),
            'challenge_public_id' => $challengePublicId,
            'destination_masked' => $destinationMasked,
            'channel' => 'sms',
            'endpoint' => $request->path(),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'is_decoy' => $isDecoy,
            'is_resend' => $isResend,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'otp_challenge_id' => $this->challengeRowId($challengePublicId),
            'technical_message' => $this->requestMessage($stage, $isDecoy, $errorCode, $metadata),
            'meta' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function fromRegisterVerify(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $challengePublicId = null,
        ?User $user = null,
        array $metadata = [],
    ): void {
        [$stage, $status] = $this->mapVerifyOutcome($outcome, $httpStatus, $errorCode);

        $this->recorder->record([
            'flow' => OtpMovementFlow::AkubicaRegister->value,
            'operation' => 'verify',
            'stage' => $stage->value,
            'status' => $status->value,
            'correlation_id' => $request->header('X-Correlation-Id'),
            'challenge_public_id' => $challengePublicId,
            'user_id' => $user?->id,
            'customer_id' => $user?->customer?->id,
            'channel' => 'sms',
            'endpoint' => $request->path(),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'otp_challenge_id' => $this->challengeRowId($challengePublicId),
            'technical_message' => $this->verifyMessage($stage, $errorCode),
            'meta' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function fromStepUpRequest(
        Request $request,
        OtpMovementFlow $flow,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $challengePublicId = null,
        ?int $userId = null,
        ?string $destinationMasked = null,
        array $metadata = [],
    ): void {
        [$stage, $status] = $this->mapRequestOutcome($outcome, $httpStatus, $errorCode, false, false);

        $this->recorder->record([
            'flow' => $flow->value,
            'operation' => 'step_up_request',
            'stage' => $stage->value,
            'status' => $status->value,
            'correlation_id' => $request->header('X-Correlation-Id'),
            'challenge_public_id' => $challengePublicId,
            'destination_masked' => $destinationMasked,
            'user_id' => $userId,
            'channel' => 'sms',
            'endpoint' => $request->path(),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'otp_challenge_id' => $this->challengeRowId($challengePublicId),
            'technical_message' => $this->requestMessage($stage, false, $errorCode, $metadata),
            'meta' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function fromStepUpVerify(
        Request $request,
        OtpMovementFlow $flow,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $challengePublicId = null,
        ?int $userId = null,
        array $metadata = [],
    ): void {
        [$stage, $status] = $this->mapVerifyOutcome($outcome, $httpStatus, $errorCode);

        $this->recorder->record([
            'flow' => $flow->value,
            'operation' => 'step_up_verify',
            'stage' => $stage->value,
            'status' => $status->value,
            'correlation_id' => $request->header('X-Correlation-Id'),
            'challenge_public_id' => $challengePublicId,
            'user_id' => $userId,
            'channel' => 'sms',
            'endpoint' => $request->path(),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'otp_challenge_id' => $this->challengeRowId($challengePublicId),
            'technical_message' => $this->verifyMessage($stage, $errorCode),
            'meta' => $metadata,
        ]);
    }

    public function fromRateLimited(
        Request $request,
        OtpMovementFlow $flow,
        string $operation,
        ?string $errorCode,
        int $httpStatus,
        ?string $challengePublicId = null,
    ): void {
        $this->recorder->record([
            'flow' => $flow->value,
            'operation' => $operation,
            'stage' => OtpMovementStage::RateLimited->value,
            'status' => OtpMovementStatus::Blocked->value,
            'correlation_id' => $request->header('X-Correlation-Id'),
            'challenge_public_id' => $challengePublicId,
            'endpoint' => $request->path(),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'technical_message' => 'Rate limit o cooldown aplicado por política anti-abuso.',
        ]);
    }

    /**
     * @return array{0: OtpMovementStage, 1: OtpMovementStatus, 2: string}
     */
    private function mapRequestOutcome(
        string $outcome,
        int $httpStatus,
        ?string $errorCode,
        bool $isDecoy,
        bool $isResend,
    ): array {
        if ($isDecoy) {
            return [OtpMovementStage::DecoyIssued, OtpMovementStatus::Decoy, $isResend ? 'resend' : 'request'];
        }

        if ($isResend) {
            if ($outcome === AuditOutcome::SUCCEEDED && $httpStatus < 400) {
                return [OtpMovementStage::ResendRequested, OtpMovementStatus::InProgress, 'resend'];
            }
        }

        if (in_array($errorCode, ['OTP_CONFIGURATION_INVALID', 'FEATURE_DISABLED'], true)) {
            return [OtpMovementStage::ConfigurationError, OtpMovementStatus::Failed, $isResend ? 'resend' : 'request'];
        }

        if ($httpStatus === 429 || $errorCode === 'OTP_RATE_LIMIT_EXCEEDED' || $errorCode === 'OTP_TEMPORARILY_BLOCKED') {
            return [OtpMovementStage::RateLimited, OtpMovementStatus::Blocked, $isResend ? 'resend' : 'request'];
        }

        if ($httpStatus >= 400) {
            return [OtpMovementStage::DeliveryFailed, OtpMovementStatus::Failed, $isResend ? 'resend' : 'request'];
        }

        if ($outcome === AuditOutcome::SUCCEEDED) {
            return [
                $isResend ? OtpMovementStage::ResendRequested : OtpMovementStage::ChallengeCreated,
                OtpMovementStatus::InProgress,
                $isResend ? 'resend' : 'request',
            ];
        }

        return [OtpMovementStage::RequestReceived, OtpMovementStatus::InProgress, $isResend ? 'resend' : 'request'];
    }

    /**
     * @return array{0: OtpMovementStage, 1: OtpMovementStatus}
     */
    private function mapVerifyOutcome(string $outcome, int $httpStatus, ?string $errorCode): array
    {
        if ($outcome === AuditOutcome::SUCCEEDED && $httpStatus < 400) {
            return [OtpMovementStage::VerifySucceeded, OtpMovementStatus::Verified];
        }

        return match ($errorCode) {
            'OTP_CHALLENGE_EXPIRED' => [OtpMovementStage::VerifyExpired, OtpMovementStatus::Expired],
            'OTP_MAX_ATTEMPTS_EXCEEDED', 'OTP_CHALLENGE_EXHAUSTED' => [OtpMovementStage::VerifyExhausted, OtpMovementStatus::Blocked],
            'OTP_TEMPORARILY_BLOCKED' => [OtpMovementStage::VerifyBlocked, OtpMovementStatus::Blocked],
            'OTP_INVALID_CODE' => [OtpMovementStage::VerifyFailed, OtpMovementStatus::Failed],
            default => [OtpMovementStage::VerifyFailed, OtpMovementStatus::Failed],
        };
    }

    private function challengeRowId(?string $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $id = OtpChallenge::query()->where('public_id', $publicId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function requestMessage(OtpMovementStage $stage, bool $isDecoy, ?string $errorCode, array $metadata = []): string
    {
        if ($isDecoy) {
            $reason = $this->closedDecoyReason($metadata['decoy_reason'] ?? null);

            return 'Respuesta protegida/decoy: proveedor no llamado. Razón interna: '.$reason.'.';
        }

        return match ($stage) {
            OtpMovementStage::ChallengeCreated => 'Challenge creado: proveedor aceptó o entrega registrada en la línea de tiempo.',
            OtpMovementStage::ResendRequested => 'Reenvío procesado; nuevo código generado si aplica.',
            OtpMovementStage::RateLimited => 'Solicitud rechazada por rate limit o cooldown.',
            OtpMovementStage::ConfigurationError => 'Configuración OTP incompleta o feature deshabilitada.',
            default => $errorCode ? 'Fallo antes del envío: '.$errorCode : 'Solicitud recibida.',
        };
    }

    private function closedDecoyReason(mixed $reason): string
    {
        if (! is_string($reason)) {
            return 'unknown';
        }

        return in_array($reason, [
            'user_not_found',
            'phone_not_verified',
            'user_inactive',
            'customer_missing',
            'ambiguous_match',
            'phone_format_mismatch',
            'unknown',
        ], true) ? $reason : 'unknown';
    }

    private function verifyMessage(OtpMovementStage $stage, ?string $errorCode): string
    {
        return match ($stage) {
            OtpMovementStage::VerifySucceeded => 'Verificación completada correctamente.',
            OtpMovementStage::VerifyExpired => 'El challenge expiró.',
            OtpMovementStage::VerifyExhausted => 'Se agotaron los intentos de verificación.',
            OtpMovementStage::VerifyBlocked => 'Identidad bloqueada temporalmente.',
            default => $errorCode ? 'Verificación fallida: '.$errorCode : 'Código incorrecto.',
        };
    }
}
