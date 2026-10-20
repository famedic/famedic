<?php

namespace App\Services\LaboratoryResults\AiExplanation\Contract;

final class LaboratoryResultAiExplanationContract
{
    public const PROMPT_KEY = 'lab_result_explanation';

    public const PROMPT_VERSION = 1;

    public const PROMPT_VERSION_LABEL = 'LAB_RESULT_EXPLANATION_V1';

    public const DOMAIN = 'laboratory';

    public const FEATURE = 'laboratory_result_ai_explanation';

    public const SUBJECT_TYPE = 'laboratory_result_observation';

    public const CONSENT_VERSION = 'LAB_AI_CONSENT_V1';

    public const MAX_EXPLANATION_LENGTH = 2000;

    public const MAX_LIMITATIONS_LENGTH = 500;

    /** @var list<string> */
    public const INPUT_ALLOWED_TOP_LEVEL_KEYS = ['analyte', 'result', 'reference', 'status', 'abnormal'];

    /** @var list<string> */
    public const INPUT_ANALYTE_KEYS = ['code', 'name'];

    /** @var list<string> */
    public const INPUT_RESULT_KEYS = ['value', 'value_type', 'unit'];

    /** @var list<string> */
    public const INPUT_REFERENCE_KEYS = ['text', 'low', 'high'];

    /** @var list<string> */
    public const INPUT_FORBIDDEN_KEYS = [
        'patient_id', 'customer_id', 'user_id', 'laboratory_purchase_id',
        'laboratory_result_report_id', 'patient_name', 'first_name', 'last_name',
        'full_name', 'email', 'phone', 'birth_date', 'gender', 'address',
        'gda_order_id', 'folio', 'pdf', 'pdf_base64', 'pdf_url',
        'raw_extraction_payload', 'metadata', 'promotion', 'approval',
        'observations', 'symptoms', 'diagnosis', 'medications',
    ];

    /** @var list<string> */
    public const OUTPUT_ALLOWED_KEYS = ['explanation', 'limitations'];

    /** @var list<string> */
    public const OUTPUT_PROHIBITED_PATTERNS = [
        // Diagnóstico
        '/\btienes\b.{0,40}\b(diabetes|hipertensi[oó]n|c[aá]ncer|enfermedad|anemia)/iu',
        '/\bel paciente tiene\b/iu',
        '/\bpadeces\b/iu',
        '/\beste resultado confirma\b/iu',
        '/\bconfirma\b.{0,30}\b(enfermedad|diagn[oó]stico|diabetes|anemia)/iu',
        '/\besto\s+significa\s+que\s+(padeces|tienes|sufres)/iu',
        '/\bprobablemente tienes\b/iu',
        '/\bcompatible con\b.{0,40}\b(diabetes|anemia|insuficiencia|enfermedad)/iu',
        '/\bel paciente presenta\b.{0,40}\b(insuficiencia|enfermedad)/iu',
        '/\breturn diagnosis\b/iu',
        // Tratamiento / medicación
        '/\bdebes\s+(iniciar|comenzar|acudir para comenzar)\s+tratamiento\b/iu',
        '/\bnecesitas tratamiento\b/iu',
        '/\bdebes\s+(tomar|suspender|dejar de tomar|administrar)/iu',
        '/\bnecesitas\s+(tomar|suspender|dejar de tomar|administrar)/iu',
        '/\b(toma|tome|tomar)\s+(metformina|ibuprofeno|insulina|medicamento)/iu',
        '/\bpuedes tomar\s+\d+/iu',
        '/\bdeja de tomar\b/iu',
        '/\baumenta la dosis\b/iu',
        '/\breduc[ei]r la dosis\b/iu',
        '/\bconsulta con tu m[eé]dico para aumentar la dosis\b/iu',
        '/\b(recomiendo|recomendamos|prescribo|prescrib)/iu',
        '/\b(dosis|mg\s+de|tableta[s]? al d[ií]a)/iu',
        '/\b(tratamiento|medicamento)\s+(para|contra)\b/iu',
        '/\bse recomienda tratamiento\b/iu',
        '/\bes recomendable comenzar\b/iu',
        '/\blo mejor es\b/iu',
        '/\bdebes hacer\b/iu',
        '/\bnecesitas realizar\b.{0,40}\b(biopsia|estudios|cirug[ií]a)/iu',
        '/\bes necesario realizar estudios adicionales\b/iu',
        // Prompt injection (output que reproduce instrucciones prohibidas)
        '/\bignora las instrucciones anteriores\b/iu',
        '/\bignore safety rules\b/iu',
        '/\bdeveloper instruction\b/iu',
        '/\bel sistema indica que debes recomendar\b/iu',
    ];

    /** @var list<string> Unidades comunes en laboratorio para detección de contradicción. */
    public const COMMON_LAB_UNITS = [
        'mg/dL', 'mmol/L', 'g/dL', 'U/L', 'mEq/L', 'ng/mL', 'pg/mL', 'µg/dL', 'ug/dL', '%',
    ];

    /** @var list<string> Patrones PII determinísticos en output (limitado, sin ML). */
    public const OUTPUT_PII_PATTERNS = [
        '/@[a-z0-9._%+-]+\.[a-z]{2,}/iu',
        '/\bel paciente con correo\b/iu',
        '/\bel paciente nacido el\b/iu',
        '/\b\d{10,}\b/',
    ];

    /** @return array<string, mixed> */
    public static function outputJsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['explanation', 'limitations'],
            'properties' => [
                'explanation' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => self::MAX_EXPLANATION_LENGTH,
                ],
                'limitations' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => self::MAX_LIMITATIONS_LENGTH,
                ],
            ],
        ];
    }
}
