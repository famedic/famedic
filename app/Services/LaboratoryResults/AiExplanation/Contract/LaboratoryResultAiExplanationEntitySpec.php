<?php

namespace App\Services\LaboratoryResults\AiExplanation\Contract;

/**
 * Especificación conceptual de la futura entidad LaboratoryResultAiExplanation (8C-21B).
 *
 * No persiste datos — documenta el contrato de almacenamiento propuesto.
 */
final class LaboratoryResultAiExplanationEntitySpec
{
    /**
     * Identidad y relación (obtenible de LaboratoryResultObservation en runtime).
     *
     * @return array<string, string>
     */
    public static function identityFields(): array
    {
        return [
            'id' => 'PK futura',
            'laboratory_result_observation_id' => 'FK → observation publicada (única por explicación activa)',
        ];
    }

    /**
     * Snapshot mínimo del input determinístico (opcional en persistencia; preferir reconstruir desde observation + hash).
     *
     * @return list<string>
     */
    public static function inputSnapshotFields(): array
    {
        return [
            'analyte_code',
            'analyte_name',
            'value',
            'value_type',
            'unit',
            'reference_text',
            'reference_low',
            'reference_high',
            'reference_status',
            'abnormal_flag',
        ];
    }

    /**
     * Resultado generado por AI (única fuente patient-visible cuando status=ready).
     *
     * @return list<string>
     */
    public static function generatedFields(): array
    {
        return [
            'explanation',
            'limitations',
        ];
    }

    /**
     * Metadata de auditoría e idempotencia.
     *
     * @return list<string>
     */
    public static function auditFields(): array
    {
        return [
            'status',
            'prompt_version',
            'model',
            'input_hash',
            'ai_execution_id',
            'generated_at',
            'failed_at',
            'invalidated_at',
            'error_code',
            'error_message_sanitized',
        ];
    }

    /**
     * Campos que NO deben duplicarse si pueden leerse de LaboratoryResultObservation.
     *
     * @return list<string>
     */
    public static function preferObservationSourceFields(): array
    {
        return [
            'analyte_code',
            'analyte_name',
            'value',
            'value_type',
            'unit',
            'reference_text',
            'reference_low',
            'reference_high',
            'reference_status',
            'abnormal_flag',
        ];
    }
}
