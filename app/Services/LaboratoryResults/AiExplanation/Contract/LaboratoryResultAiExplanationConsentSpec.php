<?php

namespace App\Services\LaboratoryResults\AiExplanation\Contract;

/**
 * Modelo conceptual de consentimiento para generación de explicaciones AI (8C-21B).
 *
 * Separado de:
 * - OTP / acceso a resultados PDF
 * - Autenticación de cuenta
 * - Ver structured results publicados
 */
final class LaboratoryResultAiExplanationConsentSpec
{
    public const STATUS_NOT_REQUESTED = 'not_requested';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_ACCEPTED = 'accepted';

    /**
     * @return list<string>
     */
    public static function recordFields(): array
    {
        return [
            'ai_explanation_consent_status',
            'ai_explanation_consent_version',
            'ai_explanation_consented_at',
            'ai_explanation_consent_user_id',
            'ai_explanation_consent_customer_id',
        ];
    }

    /**
     * Consentimiento significa autorización explícita para invocar OpenAI
     * con el payload PII-safe de una observación publicada.
     */
    public static function meaning(): string
    {
        return 'El paciente autoriza generar una explicación educativa orientativa '
            .'sobre un resultado ya determinado por Famedic, sin diagnóstico ni tratamiento.';
    }

    /**
     * Cuándo solicitar: antes del primer request de generación por purchase/sesión o por observation.
     * Decisión de producto pendiente — documentado como open question en 8C-21A.
     */
    public static function whenToRequest(): string
    {
        return 'Inmediatamente antes de la primera generación de explicación AI en la UI de resultados estructurados.';
    }

    /**
     * El paciente puede rechazar y seguir viendo valor/referencia/status/PDF sin explicación AI.
     */
    public static function declineBehavior(): string
    {
        return 'structured-results permanece disponible; explicaciones AI no se generan ni muestran.';
    }
}
