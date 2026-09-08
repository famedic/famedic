<?php

namespace App\Enums\Otp;

enum OtpMovementStage: string
{
    case RequestReceived = 'request_received';
    case UserValidated = 'user_validated';
    case ChallengeCreated = 'challenge_created';
    case DecoyIssued = 'decoy_issued';
    case DeliveryAttempted = 'delivery_attempted';
    case DeliveryAccepted = 'delivery_accepted';
    case DeliveryFailed = 'delivery_failed';
    case DeliverySuppressed = 'delivery_suppressed';
    case DeliverySkipped = 'delivery_skipped';
    case DeliveryDuplicateSuppressed = 'delivery_duplicate_suppressed';
    case DeliveryFallback = 'delivery_fallback';
    case ResendRequested = 'resend_requested';
    case VerifySucceeded = 'verify_succeeded';
    case VerifyFailed = 'verify_failed';
    case VerifyExpired = 'verify_expired';
    case VerifyBlocked = 'verify_blocked';
    case VerifyExhausted = 'verify_exhausted';
    case IdempotencyReplay = 'idempotency_replay';
    case IdempotencyConflict = 'idempotency_conflict';
    case IdempotencyUncertain = 'idempotency_uncertain';
    case RateLimited = 'rate_limited';
    case ConfigurationError = 'configuration_error';
    case FeatureDisabled = 'feature_disabled';

    public function label(): string
    {
        return match ($this) {
            self::RequestReceived => 'Solicitud recibida',
            self::UserValidated => 'Usuario validado',
            self::ChallengeCreated => 'Challenge creado',
            self::DecoyIssued => 'Respuesta decoy',
            self::DeliveryAttempted => 'Entrega intentada',
            self::DeliveryAccepted => 'Proveedor aceptó',
            self::DeliveryFailed => 'Entrega fallida',
            self::DeliverySuppressed => 'Entrega suprimida',
            self::DeliverySkipped => 'Entrega omitida',
            self::DeliveryDuplicateSuppressed => 'Entrega duplicada suprimida',
            self::DeliveryFallback => 'Fallback de canal',
            self::ResendRequested => 'Reenvío solicitado',
            self::VerifySucceeded => 'Verificación exitosa',
            self::VerifyFailed => 'Código incorrecto',
            self::VerifyExpired => 'Challenge expirado',
            self::VerifyBlocked => 'Bloqueado temporalmente',
            self::VerifyExhausted => 'Intentos agotados',
            self::IdempotencyReplay => 'Replay de idempotencia',
            self::IdempotencyConflict => 'Conflicto de idempotencia',
            self::IdempotencyUncertain => 'Idempotencia incierta',
            self::RateLimited => 'Rate limit / cooldown',
            self::ConfigurationError => 'Error de configuración',
            self::FeatureDisabled => 'Funcionalidad deshabilitada',
        };
    }
}
