<?php

namespace App\Services\Otp\Monitoring;

use App\Enums\Otp\OtpMovementStage;
use App\Enums\Otp\OtpMovementStatus;

final class OtpMovementDiagnosisBuilder
{
    /**
     * @param  array<int, array<string, mixed>>  $timeline
     * @return array{summary: string, detail: string, severity: string, partial_traceability: bool}
     */
    public function build(array $timeline, bool $partialTraceability): array
    {
        if ($timeline === []) {
            return [
                'summary' => 'Sin eventos registrados',
                'detail' => 'No hay evidencia suficiente para reconstruir este movimiento.',
                'severity' => 'neutral',
                'partial_traceability' => true,
            ];
        }

        $stages = collect($timeline)->pluck('stage')->all();

        if (in_array(OtpMovementStage::DecoyIssued->value, $stages, true)) {
            return $this->result(
                'No se creó challenge: respuesta decoy',
                'La API respondió de forma uniforme sin crear challenge, intento de entrega ni SMS.',
                'info',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::IdempotencyReplay->value, $stages, true)) {
            return $this->result(
                'Solicitud reutilizada por idempotencia',
                'No se generó un SMS nuevo; se devolvió la respuesta almacenada de la operación original.',
                'warning',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::IdempotencyConflict->value, $stages, true)) {
            return $this->result(
                'Conflicto de idempotencia',
                'La misma Idempotency-Key se usó con un payload diferente.',
                'error',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::RateLimited->value, $stages, true)) {
            return $this->result(
                'Rate limit / cooldown aplicado',
                'La solicitud fue rechazada por política anti-abuso o throttle HTTP.',
                'warning',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::ConfigurationError->value, $stages, true)) {
            return $this->result(
                'Configuración OTP incompleta',
                'Faltan flags o proveedor requerido para completar el flujo.',
                'error',
                $partialTraceability,
            );
        }

        $hasChallenge = in_array(OtpMovementStage::ChallengeCreated->value, $stages, true)
            || collect($timeline)->contains(fn (array $e) => ($e['is_historical'] ?? false) === true
                && ($e['stage'] ?? '') === OtpMovementStage::ChallengeCreated->value);

        $hasDeliveryAccepted = in_array(OtpMovementStage::DeliveryAccepted->value, $stages, true);
        $hasDeliveryFailed = in_array(OtpMovementStage::DeliveryFailed->value, $stages, true);
        $hasDeliverySkipped = in_array(OtpMovementStage::DeliverySkipped->value, $stages, true);

        if ($hasChallenge && $hasDeliverySkipped) {
            return $this->result(
                'Challenge creado, pero delivery deshabilitado',
                'El challenge se persistió, pero la entrega SMS estaba deshabilitada por configuración.',
                'warning',
                $partialTraceability,
            );
        }

        if ($hasChallenge && $hasDeliveryAccepted) {
            return $this->result(
                'Entrega intentada y aceptada por proveedor',
                'FAMEDIC intentó el envío y el proveedor aceptó la solicitud. Entrega final al dispositivo no confirmada.',
                'success',
                $partialTraceability,
            );
        }

        if ($hasChallenge && $hasDeliveryFailed) {
            return $this->result(
                'Falló el proveedor',
                'FAMEDIC intentó el envío, pero el proveedor rechazó o no aceptó la solicitud.',
                'error',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::VerifySucceeded->value, $stages, true)) {
            return $this->result(
                'Verificación exitosa',
                'El código OTP fue verificado correctamente.',
                'success',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::VerifyFailed->value, $stages, true)) {
            return $this->result(
                'Código incorrecto',
                'Se recibió un intento de verificación con código inválido.',
                'warning',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::VerifyExpired->value, $stages, true)) {
            return $this->result(
                'Challenge expirado',
                'El challenge expiró antes de completar la verificación.',
                'warning',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::VerifyExhausted->value, $stages, true)) {
            return $this->result(
                'Intentos agotados',
                'Se alcanzó el máximo de intentos de verificación.',
                'error',
                $partialTraceability,
            );
        }

        if (in_array(OtpMovementStage::VerifyBlocked->value, $stages, true)) {
            return $this->result(
                'Bloqueado temporalmente',
                'La identidad fue bloqueada por política anti-abuso.',
                'error',
                $partialTraceability,
            );
        }

        if ($hasChallenge) {
            return $this->result(
                'Challenge creado; flujo incompleto',
                'El challenge fue creado, pero no hay evidencia de entrega o verificación final.',
                'warning',
                $partialTraceability,
            );
        }

        return $this->result(
            'Flujo en proceso o trazabilidad parcial',
            $partialTraceability
                ? 'Parte de la línea de tiempo fue reconstruida desde tablas históricas.'
                : 'El movimiento aún no alcanza un resultado terminal.',
            'neutral',
            $partialTraceability,
        );
    }

    /**
     * @return array{summary: string, detail: string, severity: string, partial_traceability: bool}
     */
    private function result(string $summary, string $detail, string $severity, bool $partial): array
    {
        return [
            'summary' => $summary,
            'detail' => $detail,
            'severity' => $severity,
            'partial_traceability' => $partial,
        ];
    }
}
