<?php

namespace App\Services\LaboratoryPreparation\Parsing;

/**
 * Catálogo determinístico de patrones documentados para indicaciones de laboratorio (v3).
 *
 * @internal
 */
final class LaboratoryInstructionRecognizerRules
{
    /**
     * Unidad de duración en contexto de ayuno (GDA: horas, hrs, h, h.).
     * Usar solo en patrones que ya exigen señal de ayuno en la misma cláusula.
     */
    private const FASTING_DURATION_HOUR_UNIT = '(?:horas?|hrs?|h\.?)';

    /**
     * @return list<array{
     *     category: string,
     *     requirement_type: string,
     *     pattern: string,
     *     status: string,
     *     builder: callable(array<int|string, string|int>): array{normalized_value: array<string, mixed>, unit: string|null, qualifiers: array<string, mixed>}
     * }>
     */
    public static function rules(): array
    {
        return [
            ...self::fastingRules(),
            ...self::ageRules(),
            ...self::hydrationRules(),
            ...self::urineRules(),
            ...self::stoolRules(),
            ...self::semenRules(),
            ...self::gynecologicalRules(),
            ...self::medicationRules(),
            ...self::creatinineContrastRules(),
            ...self::documentationRules(),
            ...self::appointmentRules(),
            ...self::companionRules(),
            ...self::metalRules(),
            ...self::dietRules(),
            ...self::timingRules(),
            ...self::noPrepRules(),
            ...self::containerRules(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fastingRules(): array
    {
        $hour = self::FASTING_DURATION_HOUR_UNIT;

        return [
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'no_fasting',
                '/\b(?:no|sin)\s+(?:requiere\s+)?ayuno\b|\bno\s+ayuno\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'none'],
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_range_hours',
                '/\bayuno\b.{0,40}?\b(\d+)\s*(?:a|y|-)\s*(\d+)\s*'.$hour.'\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'closed_range',
                    'min_hours' => (int) $m[1],
                    'max_hours' => (int) $m[2],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_range_hours_maximo',
                '/\b(?:de\s+)?(\d+)\s*(?:hrs?|h\.?),?\s*(?:maximo|m[aá]ximo)\s+(\d+)\s*(?:hrs?|h\.?)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'closed_range',
                    'min_hours' => (int) $m[1],
                    'max_hours' => (int) $m[2],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_solids_range_hours',
                '/\b(?:en\s+)?ayuno\s+a\s+solidos\b.{0,30}?\b(\d+)\s*(?:a|y|-)\s*(\d+)\s*'.$hour.'\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'closed_range',
                    'min_hours' => (int) $m[1],
                    'max_hours' => (int) $m[2],
                    'dietary_restrictions' => ['solid_foods'],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_solids_and_soda_maximo',
                '/\bayuno\s+total\s+a\s+alimentos\s+solidos\s+y\s+bebidas\s+gaseosas\b.{0,50}?\bde\s+(\d+)\s*(?:hrs?|h\.?),?\s*(?:maximo|m[aá]ximo)\s+(\d+)\s*(?:hrs?|h\.?)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'closed_range',
                    'min_hours' => (int) $m[1],
                    'max_hours' => (int) $m[2],
                    'dietary_restrictions' => ['solid_foods', 'carbonated_drinks'],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_range_hours',
                '/\b(?:presentarse|acudir)\s+con\s+ayuno\s+de\s+(\d+)\s*-\s*(\d+)\s*'.$hour.'\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'closed_range',
                    'min_hours' => (int) $m[1],
                    'max_hours' => (int) $m[2],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_range_hours',
                '/\b(?:presentarse|acudir)\s+(?:con\s+)?ayuno\b.{0,30}?\b(\d+)\s*(?:a|y|-)\s*(\d+)\s*'.$hour.'\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'closed_range',
                    'min_hours' => (int) $m[1],
                    'max_hours' => (int) $m[2],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_pediatric_patients_line',
                '/\-En\s+pacientes\s+pedi[aá]tricos\s*\(\s*\d+\s*(?:a|-)\s*\d+\s*a[nñ]os\s*\)\s*,?\s*presentarse\s+con\s+ayuno\s+de\s+(\d+)\s*(?:a|-)\s*(\d+)\s*'.$hour.'\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'closed_range',
                    'min_hours' => (int) $m[1],
                    'max_hours' => (int) $m[2],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_adult_patients_line',
                '/\-En\s+pacientes\s+adultos\s*,?\s*presentarse\s+con\s+ayuno\s+de\s+(\d+)\s*(?:a|-)\s*(\d+)\s*'.$hour.'\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'closed_range',
                    'min_hours' => (int) $m[1],
                    'max_hours' => (int) $m[2],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_minimum_hours',
                '/\b(?:al\s+menos|minimo|mínimo)\s+(\d+)\s*'.$hour.'\b.{0,25}?\bayuno\b|\bayuno\b.{0,25}?\b(?:al\s+menos|minimo|mínimo)\s+(\d+)\s*'.$hour.'\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'minimum',
                    'min_hours' => (int) ($m[1] !== '' ? $m[1] : $m[2]),
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_exact_hours',
                '/\bayuno\b.{0,20}?\bde\s+(\d+)\s*'.$hour.'\b|\ben\s+ayunas\b.{0,20}?\b(\d+)\s*'.$hour.'\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'exact',
                    'hours' => (int) ($m[1] !== '' ? $m[1] : $m[2]),
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_present_exact_hours',
                '/\b(?:presentarse|acudir)\s+con\s+ayuno\s+de\s+(\d+)\s*'.$hour.'\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'exact',
                    'hours' => (int) $m[1],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_present',
                '/\b(?:presentarse|acudir)\s+(?:con\s+)?ayuno\b(?!\s+de\s+\d)/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'required', 'hours_unspecified' => true],
            ),
            self::rule(
                LaboratoryInstructionCategory::FASTING,
                'fasting_incomplete',
                '/\bayuno\s+de\s+horas?\b/iu',
                LaboratoryInstructionRecognitionStatus::INCOMPLETE,
                fn () => ['kind' => 'incomplete', 'missing' => 'hours'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ageRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::AGE_CONDITION,
                'gestational_weeks_window',
                '/\bse\s+deber[aá]\s+realizar\b.{0,80}?\bsemanas\s+(\d+)\s*(?:a|y|-)\s*(\d+)\b.{0,40}?\bgestaci[oó]n\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'gestational_ultrasound_window',
                    'min_weeks' => (int) $m[1],
                    'max_weeks' => (int) $m[2],
                ],
                'week',
            ),
            self::rule(
                LaboratoryInstructionCategory::AGE_CONDITION,
                'gestational_weeks_range',
                '/\bsemanas?\s+(\d+)\s*(?:a|y|-)\s*(\d+)\b.{0,40}?\bgestaci[oó]n\b|\bentre\s+las\s+semanas\s+(\d+)\s*(?:a|y|-)\s*(\d+)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'gestational_weeks_range',
                    'min_weeks' => (int) ($m[1] !== '' ? $m[1] : $m[3]),
                    'max_weeks' => (int) ($m[2] !== '' ? $m[2] : $m[4]),
                ],
                'week',
            ),
            self::rule(
                LaboratoryInstructionCategory::AGE_CONDITION,
                'age_years_range',
                '/\b(?:de|entre)\s+(\d+)\s*(?:a|y|-)\s*(\d+)\s*a[nñ]os?\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'age_years_range',
                    'min_years' => (int) $m[1],
                    'max_years' => (int) $m[2],
                ],
                'year',
            ),
            self::rule(
                LaboratoryInstructionCategory::AGE_CONDITION,
                'pediatric_patients_age_band',
                '/\bpacientes\s+pedi[aá]tricos\s*\(\s*(\d+)\s*(?:a|-)\s*(\d+)\s*a[nñ]os\s*\)/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'age_max',
                    'max_years' => (int) $m[2] + 1,
                ],
                'year',
            ),
            self::rule(
                LaboratoryInstructionCategory::AGE_CONDITION,
                'adult_patients_label',
                '/\ben\s+pacientes\s+adultos\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'adult_patients'],
            ),
            self::rule(
                LaboratoryInstructionCategory::AGE_CONDITION,
                'age_years_max',
                '/\b(?:menores?\s+de|hasta)\s+(\d+)\s*a[nñ]os?\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'age_max', 'max_years' => (int) $m[1]],
                'year',
            ),
            self::rule(
                LaboratoryInstructionCategory::AGE_CONDITION,
                'age_years_min',
                '/\b(?:mayores?\s+de|a\s+partir\s+de)\s+(\d+)\s*a[nñ]os?\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'age_min', 'min_years' => (int) $m[1]],
                'year',
            ),
            self::rule(
                LaboratoryInstructionCategory::AGE_CONDITION,
                'newborn',
                '/\b(?:recien\s+nacido|recién\s+nacido|neonato|rn)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'newborn'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function hydrationRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::HYDRATION,
                'drink_water_volume',
                '/\b(?:beber|tomar|ingerir|deber[aá])\s+(?:por\s+lo\s+menos\s+)?(\d+(?:[.,]\d+)?)\s*(?:l|litros?|ml|mililitros?)\b.{0,30}?\bagua\b|\bagua\b.{0,30}?\b(\d+(?:[.,]\d+)?)\s*(?:l|litros?|ml|mililitros?)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'water_intake',
                    'amount' => (float) str_replace(',', '.', $m[1] !== '' ? $m[1] : $m[2]),
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::HYDRATION,
                'hydrated',
                '/\b(?:presentarse|acudir)\s+hidratad[oa]s?\b|\bmantener(?:se)?\s+hidratad[oa]\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'stay_hydrated'],
            ),
            self::rule(
                LaboratoryInstructionCategory::HYDRATION,
                'full_bladder',
                '/\bvejiga\s+llena\b|\b(?:beber|tomar)\s+agua\b.{0,40}?\bvejiga\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'full_bladder'],
            ),
            self::rule(
                LaboratoryInstructionCategory::HYDRATION,
                'water_liters_one_hour_before_bladder',
                '/\bdebera\s+tomar\s+1\.5\s*L\s+de\s+agua\s+1\s+hora\s+previa\s+a\s+su\s+estudio\s*\(llegar\s+a\s+su\s+cita\s+con\s+vejiga\s+llena\)/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'water_intake',
                    'amount' => 1.5,
                    'hours_before' => 1,
                    'full_bladder' => true,
                ],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function urineRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::URINE_24H,
                'urine_24h_collection',
                '/\b(?:recolectar|recolecci[oó]n\s+(?:de\s+)?)?orina\s+(?:de\s+|durante\s+)?24\s*horas?\b|\brecolecci[oó]n\s+(?:de\s+)?orina\s+(?:de\s+)?24\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'urine_24h'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'first_morning_urine',
                '/\bprimera\s+orina\b.{0,30}?\b(?:de\s+la\s+)?ma[nñ]ana\b|\bprimera\s+micci[oó]n\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'first_morning_urine'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'mid_stream_urine',
                '/\b(?:recolectar|recolecci[oó]n\s+de)\s+(?:el\s+)?chorro\s+medio\b|\bchorro\s+medio\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'mid_stream'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'discard_first_stream',
                '/\b(?:desechar|descartar)\s+(?:el\s+)?primer\s+chorro\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'discard_first_stream'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_after_void_hours',
                '/\b(?:recolectar\s+)?desp[uú]es\s+de\s+(\d+)\s*horas?\b.{0,50}?\b(?:[uú]ltima\s+micci[oó]n|micci[oó]n)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'hours_after_last_void',
                    'hours' => (int) $m[1],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_general',
                '/\bexamen\s+general\s+de\s+orina\b|\borina\s+simple\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'general_urine'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'pediatric_urine_bag',
                '/\bbolsa\s+pedi[aá]trica\b.{0,40}?\borina\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'pediatric_urine_bag'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'genital_hygiene',
                '/\b(?:lavarse|l[aá]vese)\s+las\s+manos\b.{0,60}?\baseo\s+genital\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'genital_hygiene'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'random_urine_after_void',
                '/\borina\s+aleatoria\b.{0,50}?\b(\d+)\s*horas?\b.{0,40}?\b(?:[uú]ltima\s+micci[oó]n|micci[oó]n)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'hours_after_last_void',
                    'hours' => (int) $m[1],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_collect_sample_required',
                '/\bSe requiere recolectar muestra de orina\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'collect_urine_sample',
                    'protocol_scope' => 'gda_metabolite_urine',
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_sample_volume_ml',
                '/\(\s*(\d+(?:[.,]\d+)?)\s*m[lL]\s*\)(?=\s*final del d[ií]a de la jornada laboral)/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'sample_volume',
                    'amount_ml' => (float) str_replace(',', '.', (string) $m[1]),
                    'unit' => 'mL',
                ],
                'ml',
            ),
            self::rule(
                LaboratoryInstructionCategory::SAMPLE_TIMING,
                'urine_collection_workday_end',
                '/\bfinal del d[ií]a de la jornada laboral\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'workday_end_collection',
                    'timing_scope' => 'end_of_workday',
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
                'urine_plastic_sterile_screw_cap_container',
                '/\bcontenedor de pl[aá]stico est[eé]ril tapa de rosca\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'plastic_sterile_screw_cap_container',
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_discard_midstream_fill_combined',
                '/\bDesechar el primer chorro de orina y recolectar el chorro medio en el contenedor,\s*llenar por lo menos hasta las tres cuartas partes\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'discard_midstream_fill_three_quarters',
                    'protocol_scope' => 'gda_metabolite_urine',
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_container_seal_no_spill',
                '/\bCerrar el contenedor,\s*asegurando que la tapa cierre correctamente,\s*para evitar derrame\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'seal_container_prevent_spill',
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'collect_urine',
                '/\b(?:recolectar\s+(?:(?:la\s+)?muestra\s+de\s+)?|recolecci[oó]n\s+(?:de\s+)?(?:(?:la\s+)?muestra\s+de\s+)?)orina\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'collect_urine'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_fill_level',
                '/\b(?:llenar|llene)\b.{0,40}?\btres\s+cuartas?\s+partes\b|\bpor\s+lo\s+menos\s+las\s+tres\s+cuartas?\s+partes\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'container_fill_three_quarters'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_delivery_time',
                '/\b(?:entregar|llevar)\b.{0,30}?\b(?:en\s+)?menos\s+de\s+(\d+)\s*horas?\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'deliver_within_hours',
                    'hours' => (int) $m[1],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_first_morning_or_random',
                '/Preferible\s+recolectar\s+la\s+primera\s+orina\s+de\s+la\s+ma[nñ]ana;\s*en\s+caso\s+contrario,\s*recolecte\s+una\s+orina\s+aleatoria\s*\([^)]+\)\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'first_morning_or_random_urine'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_mid_stream_full_instruction',
                '/Desechar\s+el\s+primer\s+y\s+recolectar\s+el\s+chorro\s+medio\s*\([^)]+\),\s*desechar\s+tambien\s+el\s+[uú]ltimo\s+chorro\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'mid_stream_with_discard'],
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'urine_delivery_container_deadline',
                '/Deber[aá]\s+entregar\s+el\s+contenedor\s+de\s+orina\s+al\s+laboratorio\s+en\s+un\s+lapso\s+no\s+mayor\s+a\s+(\d+)\s*horas\s+despu[eé]s\s+de\s+recolectarlo\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'deliver_within_hours',
                    'hours' => (int) $m[1],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::URINE_SIMPLE,
                'pediatric_urine_bag_instructions',
                '/Pacientes\s+pedi[aá]tricos:\s*Utilizar\s+bolsa\s+pedi[aá]trica\s+de\s+recolecci[oó]n\s+para\s+orina\s*\([^)]+\),\s*y\s+seguir\s+instrucciones\s+de\s+uso\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'pediatric_urine_bag'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function stoolRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::STOOL,
                'stool_sample',
                '/\b(?:muestra\s+de\s+)?heces\b|\bcoprocultivo\b|\bmateria\s+fecal\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'stool_collection'],
            ),
            self::rule(
                LaboratoryInstructionCategory::STOOL,
                'stool_avoid_water_urine',
                '/\bno\s+debe\s+tener\s+contacto\s+con\s+el\s+agua\b.{0,20}?\bn[ií]\s+con\s+orina\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_water_and_urine_contact'],
            ),
            self::rule(
                LaboratoryInstructionCategory::STOOL,
                'stool_amount_grams',
                '/\b(\d+)\s*(?:a|y|-)\s*(\d+)\s*gramos\b.{0,40}?\bmateria\s+fecal\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'stool_amount_grams',
                    'min_grams' => (int) $m[1],
                    'max_grams' => (int) $m[2],
                ],
                'g',
            ),
            self::rule(
                LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
                'branch_pickup_stool_containers',
                '/Acudir\s+a\s+la\s+sucursal\s+por\s+los\s+recipientes\s+para\s+la\s+recolecci[oó]n\s+de\s+la\s+muestra\s+o\s+podr[aá]\s+comprarlos\s+en\s+la\s+farmacia\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'branch_pickup_container'],
            ),
            self::rule(
                LaboratoryInstructionCategory::STOOL,
                'stool_collection_instructions',
                '/Recolectar\s+muestra\s+de\s+materia\s+fecal,\s*no\s+debe\s+tener\s+contacto\s+con\s+el\s+agua,\s*ni\s+con\s+orina\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'stool_collection_with_avoidance'],
            ),
            self::rule(
                LaboratoryInstructionCategory::STOOL,
                'stool_amount_walnut',
                '/La\s+cantidad\s+de\s+muestra\s+a\s+entregar\s+es\s+aproximadamente\s+de\s+5\s+a\s+10\s+gramos\s+de\s+materia\s+fecal,\s*lo\s+equivalente\s+al\s+tama[nñ]o\s+de\s+una\s+nuez\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'stool_amount_grams',
                    'min_grams' => 5,
                    'max_grams' => 10,
                ],
                'g',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function semenRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::SEMEN,
                'semen_sample',
                '/\bespermograma\b|\bmuestra\s+seminal\b|\bsemen\b(?!\s+de\s+24)/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'semen_collection'],
            ),
            self::rule(
                LaboratoryInstructionCategory::SEMEN,
                'semen_masturbation_collection',
                '/\bEl\s+semen\s+deber[aá]\s+obtenerse\s+por\s+masturbaci[oó]n:\s*no\s+deber[aá]\s+usarse\s+ning[uú]n\s+otro\s+m[eé]todo\s+para\s+la\s+recolecci[oó]n\s+de\s+la\s+muestra\s*\(ejemplo:\s*cond[oó]n,\s*coito\s+interrumpido,\s*etc\.\)\.?|\bsemen\b.{0,40}?\bobtenerse\s+por\s+masturbaci[oó]n\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'masturbation_collection'],
            ),
            self::rule(
                LaboratoryInstructionCategory::SEMEN,
                'semen_plastic_container',
                '/\bfrasco\s+de\s+pl[aá]stico\s+est[eé]ril\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'plastic_sterile_container'],
            ),
            self::rule(
                LaboratoryInstructionCategory::SEMEN,
                'semen_weekday_availability',
                '/\b(?:solo\s+)?se\s+realiza\s+de\s+lunes\s+a\s+s[aá]bado\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'weekday_availability'],
            ),
            self::rule(
                LaboratoryInstructionCategory::SEMEN,
                'semen_no_void_before',
                '/\bno\s+haber\s+orinado\b.{0,20}?\b(?:minimo|m[ií]nimo)\s+(\d+)\s*h\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'hours_without_void_before_collection',
                    'hours' => (int) $m[1],
                ],
                'hour',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function gynecologicalRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'no_menstruation',
                '/\bno\s+(?:estar|tener)\s+(?:en\s+)?(?:periodo|menstruaci[oó]n)\b|\bsin\s+menstruaci[oó]n\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_menstruation'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'female_patients_no_menstruation',
                '/Pacientes\s+femeninas:\s*no\s+estar\s+en\s+periodo\s+menstrual\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_menstruation'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'no_intercourse',
                '/\bno\s+tener\s+relaciones?\s+(?:sexuales?\s+)?(?:24|48|\d+)\s*horas?\b|\babstinencia\s+sexual\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'sexual_abstinence'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'sexual_abstinence_minimum_hours',
                '/\babstinencia\s+sexual\s+(?:de\s+)?(?:m[ií]nimo|minimo)\s+(\d+)\s*horas?\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'sexual_abstinence_minimum_hours',
                    'hours' => (int) $m[1],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'sexual_abstinence_days_minimum_maximum',
                '/\babstinencia\s+sexual\s+(?:de\s+)?(?:m[ií]nimo|minimo)\s+(\d+)\s*d[ií]as?\s+(?:m[aá]ximo|maximo)\s+(\d+)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'sexual_abstinence_days',
                    'min_days' => (int) $m[1],
                    'max_days' => (int) $m[2],
                ],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'habitual_hygiene',
                '/\b(?:presentarse|acudir)\s+con\s+(?:aseo|ba[nñ]o)\s+habitual\b|\bBa[nñ]o\s+diario\s+habitual\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'habitual_hygiene'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'menstruation_preference_after_bleeding',
                '/\bEs\s+preferible\s+no\s+acudir\s+durante\s+el\s+per[ií]odo\s+de\s+menstruaci[oó]n,\s*se\s+recomienda\s+que\s+pasen\s+(\d+)\s+d[ií]as?\s+despu[eé]s\s+de\s+haber\s+cesado\s+el\s+sangrado\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'avoid_menstruation_after_bleeding_stops', 'days' => (int) $m[1]],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'days_after_bleeding_stops',
                '/\b(\d+)\s+d[ií]as?\s+(?:despu[eé]s|despues)\b.{0,40}?\b(?:cesado|cese)\b.{0,20}?\bsangrado\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'days_after_bleeding_stops', 'days' => (int) $m[1]],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'vaginal_products_days_before',
                '/\bNo\s+aplicarse\s+duchas\s+vaginales,\s*tampones,\s*espumas\s+espermicidas,\s*medicamentos\s*\([^)]+\)\s+v[ií]a\s+vaginal\s+dos\s+d[ií]as\s+antes\.?|\b(?:no\s+aplicarse|sin\s+haberse\s+realizado)\b.{0,60}?\b(?:duchas?\s+vaginales?|tampones|espumas\s+espermicidas|medicamentos)\b.{0,40}?\b(\d+)\s+d[ií]as?\s+antes\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'avoid_vaginal_products_days_before',
                    'days' => (int) (($m[1] ?? '') !== '' ? $m[1] : 2),
                ],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'vaginal_shower_hours_before',
                '/\bSin\s+haberse\s+realizado\s+ducha\s+vaginal\s+(\d+)\s*horas?\s+antes\s+de\s+la\s+toma\s+del\s+estudio\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'avoid_vaginal_shower_hours_before', 'hours' => (int) $m[1]],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'vaginal_products_one_week_before',
                '/\b(?:no\s+aplicarse)\b.{0,80}?\bv[ií]a\s+vaginal\b.{0,20}?\buna\s+semana\s+antes\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_vaginal_products_days_before', 'days' => 7],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'pregnancy_medical_order',
                '/\bPuede\s+realizarse\s+el\s+estudio\s+durante\s+el\s+embarazo\s+[uú]nicamente\s+bajo\s+(?:orden|receta)\s+m[eé]dica\.?|\b(?:embarazo)\b.{0,40}?\b(?:orden|receta)\s+m[eé]dica\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'pregnancy_requires_medical_order'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'postpartum_weeks_window',
                '/\bEn\s+mujeres\s+postparto\s+realizar\s+la\s+toma\s+hasta\s+(\d+)\s+semanas\s+despu[eé]s\s+del\s+parto\.?|\bpostparto\b.{0,40}?\b(\d+)\s+semanas\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'postpartum_weeks_window', 'weeks' => (int) (($m[1] ?? '') !== '' ? $m[1] : $m[2])],
                'week',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'sexual_abstinence_days',
                '/\babstinencia\s+sexual\s+(?:de\s+)?(\d+)\s*(?:a|y|-)\s*(\d+)\s*d[ií]as?\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'sexual_abstinence_days',
                    'min_days' => (int) $m[1],
                    'max_days' => (int) $m[2],
                ],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'vph_cervicovaginal_urethral_sample',
                '/\bMuestra\s+Cervico\s+Vaginal\s+y\s+Uretral\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'cervicovaginal_urethral_sample'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'male_no_void_before_study',
                '/\bEn\s+pacientes\s+masculinos,\s*no\s+haber\s+orinado\s+de\s+(\d+)\s*a\s+(\d+)\s+horas?\s+antes\s+del\s+estudio\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'male_no_void_before_study', 'min_hours' => (int) $m[1], 'max_hours' => (int) $m[2]],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'female_vph_products_menstruation',
                '/\bEn\s+pacientes\s+femeninos:\s*No\s+aplicar\s+duchas\s+vaginales,\s*cremas,\s*pomadas\s+en\s+la\s+regi[oó]n\s+genital,\s*No\s+presentarse\s+durante\s+la\s+menstruaci[oó]n,\s*acudir\s+a\s+la\s+toma\s+del\s+estudio\s+(\d+)\s+d[ií]as?\s+despu[eé]s\s+de\s+haber\s+cesado\s+el\s+sangrado\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'female_vph_products_menstruation', 'days_after_bleeding_stops' => (int) $m[1]],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'anal_sample_no_products',
                '/\bMuestra\s+Anal:\s*[\s-]*No\s+aplicar\s+duchas,\s*cremas,\s*pomadas\s+en\s+la\s+regi[oó]n\s+anal\.?|\bNo\s+aplicar\s+duchas,\s*cremas,\s*pomadas\s+en\s+la\s+regi[oó]n\s+anal\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'anal_sample_no_products'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'anal_sample_label',
                '/\bMuestra\s+Anal:/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'anal_sample_variant'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'ocular_sample_no_products_cleaning',
                '/\bMuestra\s+Ocular:\s*[\s-]*No\s+aplicar\s+gotas,\s*cremas\s+en\s+el\s+ojo\.\s*[\s-]*No\s+realizar\s+limpieza\s+en\s+el\s+ojo\.?|\bNo\s+aplicar\s+gotas,\s*cremas\s+en\s+el\s+ojo\.?|\bNo\s+realizar\s+limpieza\s+en\s+el\s+ojo\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'ocular_sample_no_products_cleaning'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'ocular_sample_label',
                '/\bMuestra\s+Ocular:/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'ocular_sample_variant'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'urine_sample_label',
                '/\bMuestra\s+de\s+orina:/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'urine_sample_variant'],
            ),
            self::rule(
                LaboratoryInstructionCategory::GYNECOLOGICAL,
                'cytology_prep',
                '/\b(?:citolog[ií]a|papanicolaou|papanicolau)\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'gynecological_study'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function medicationRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::MEDICATION,
                'metformin_suspend_before_after_hours',
                '/\bsi\s+el\s+paciente\s+toma\s+metformina\b.{0,120}?\bsuspenderlo\s+(\d+)\s*horas?\s+antes\s+y\s+(\d+)\s*horas?\s+desp[uú]es\b.{0,30}?\bdel\s+estudio\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'metformin_suspend_before_after',
                    'substance_text' => 'metformina',
                    'hours_before' => (int) $m[1],
                    'hours_after' => (int) $m[2],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::MEDICATION,
                'metformin_physician_consult',
                '/\bsi\s+el\s+paciente\s+es\s+diab[eé]tico\s+y\s+toma\s+metformina\b.{0,200}?\bconsultar\s+con\s+su\s+m[eé]dico\b.{0,120}?\bdel\s+examen\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'metformin_physician_consult_required',
                    'substance_text' => 'metformina',
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::MEDICATION,
                'metformin_suspend_hours_before_exam',
                '/\bmetformina\b.{0,80}?\bsuspender\s+este\s+medicamento\s+(\d+)\s*horas?\s+antes\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'metformin_suspend_hours_before_exam',
                    'substance_text' => 'metformina',
                    'hours_before' => (int) $m[1],
                ],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::MEDICATION,
                'suspend_medication',
                '/\b(?:suspender|no\s+tomar|omitir|interrumpir)\b.{0,40}?\b(?:medicamento|medicina|f[áa]rmaco|tratamiento)\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'medication_change', 'detail_unspecified' => true],
            ),
            self::rule(
                LaboratoryInstructionCategory::MEDICATION,
                'suspend_medication_duration',
                '/\b(?:suspender|no\s+tomar)\s+([a-záéíóúñ0-9\s\-]{3,40}?)\s+(?:por|durante)\s+(\d+)\s*(horas?|d[ií]as?)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'suspend_substance_duration',
                    'substance_text' => trim($m[1]),
                    'duration' => (int) $m[2],
                    'duration_unit' => mb_strtolower($m[3]),
                ],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function creatinineContrastRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::CREATININE_CONTRAST,
                'creatinine_max_level_validity_month',
                '/\bdeber[aá]\s+presentar\s+estudio\s+de\s+creatinina\s+con\s+un\s+nivel\s+maximo\s+de\s+([\d.]+)\s*\(([\d.]+)\s*-\s*([\d.]+)\)\s*,?\s*con\s+vigencia\s+de\s+un\s+mes\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'creatinine_max_level',
                    'maximum_level' => (float) str_replace(',', '.', $m[1]),
                    'range_min' => (float) str_replace(',', '.', $m[2]),
                    'range_max' => (float) str_replace(',', '.', $m[3]),
                    'validity_days' => 30,
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::CREATININE_CONTRAST,
                'creatinine_recent_short',
                '/\bcreatinina\s+reciente\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'creatinine_recent_unspecified'],
            ),
            self::rule(
                LaboratoryInstructionCategory::CREATININE_CONTRAST,
                'creatinine_recent_normal_30_days',
                '/\bestudio\s+reciente\s+de\s+creatinina\s+en\s+sangre\b.{0,80}?\b(?:no\s+mayor\s+a|antig[uü]edad\s+no\s+mayor\s+a)\s+30\s+d[ií]as\b.{0,80}?\bvalores\s+dentro\s+de\s+la\s+normalidad\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => [
                    'kind' => 'creatinine_recent_normal',
                    'validity_days' => 30,
                    'requires_normal_values' => true,
                ],
            ),
            self::rule(
                LaboratoryInstructionCategory::CREATININE_CONTRAST,
                'contrast_procedure',
                '/\bprocedimiento\s+contrastad[oa]\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'contrast_procedure'],
            ),
            self::rule(
                LaboratoryInstructionCategory::CREATININE_CONTRAST,
                'creatinine_required',
                '/\bcreatinina\b.{0,40}?\b(?:antes|previo|pre)\b|\b(?:antes|previo|pre)\b.{0,40}?\bcreatinina\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'creatinine_before_contrast'],
            ),
            self::rule(
                LaboratoryInstructionCategory::CREATININE_CONTRAST,
                'contrast_study',
                '/\bcontraste\b|\b(?:tomograf[ií]a|resonancia|tac|rnm)\b.{0,30}?\bcontraste\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'contrast_imaging'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function documentationRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::DOCUMENTATION,
                'medical_order',
                '/\b(?:orden|receta|solicitud)\s+m[eé]dica\b|\breceta\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'medical_order'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DOCUMENTATION,
                'medical_order_full_requisition',
                '/\-Presentar\s+receta\s+m[eé]dica\s+debidadamente\s+requisitada[^.\n\r]*(?:[\.\n\r]|$)/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'medical_order_full_requisition'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DOCUMENTATION,
                'interpretation_copy',
                '/\bcopia\s+de\s+(?:interpretaci[oó]n|resultado)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'prior_study_copy'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DOCUMENTATION,
                'informed_consent',
                '/\bLlenado\s+de\s+consentimiento\s+informado\s+previo\s+a\s+su\s+estudio\.?|\bconsentimiento\s+informado\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'informed_consent'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DOCUMENTATION,
                'lab_information_request',
                '/\bSolicitar\s+informaci[oó]n\s+al\s+laboratorio\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'lab_information_request'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function appointmentRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::APPOINTMENT,
                'prior_appointment',
                '/\b(?:previa\s+cita|cita\s+previa|agendar\s+cita|realizar\s+previa\s+cita)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'prior_appointment'],
            ),
            self::rule(
                LaboratoryInstructionCategory::APPOINTMENT,
                'prior_appointment_bullet',
                '/\-Realizar\s+previa\s+cita\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'prior_appointment'],
            ),
            self::rule(
                LaboratoryInstructionCategory::APPOINTMENT,
                'scheduling_weekday_branch',
                '/\bsolo\s+se\s+realiza\s+los\s+d[ií]as\s+lunes\b.{0,120}?\bsucursales\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'weekday_branch_restriction'],
            ),
            self::rule(
                LaboratoryInstructionCategory::APPOINTMENT,
                'scheduling_weekday_named_branches',
                '/Solo\s+se\s+realiza\s+los\s+d[ií]as\s+lunes\s+en\s+las\s+sucursales\s+Olab\s+Anzures,\s*Azteca\s+Santa\s+Maria\s+la\s+Rivera/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'weekday_branch_restriction'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function companionRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::COMPANION_IDENTIFICATION,
                'companion_min_age_bullet',
                '/\-Acudir\s+acompa[nñ]ad[oa]\s+de\s+una\s+persona\s+mayor\s+de\s+(\d+)\s*a[nñ]os\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'companion_minimum_age',
                    'minimum_age_years' => (int) $m[1],
                ],
                'year',
            ),
            self::rule(
                LaboratoryInstructionCategory::COMPANION_IDENTIFICATION,
                'companion_min_age',
                '/\bacompa[nñ]ad[oa]\s+de\s+(?:una\s+persona|familiar)\s+mayor\s+de\s+(\d+)\s*a[nñ]os\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => [
                    'kind' => 'companion_minimum_age',
                    'minimum_age_years' => (int) $m[1],
                ],
                'year',
            ),
            self::rule(
                LaboratoryInstructionCategory::COMPANION_IDENTIFICATION,
                'companion_required',
                '/\bacompa[nñ]ante\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'companion_required'],
            ),
            self::rule(
                LaboratoryInstructionCategory::COMPANION_IDENTIFICATION,
                'identification_required',
                '/\b(?:identificaci[oó]n|identificacion|ine|credencial)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'identification_required'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function metalRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::METAL_IMPLANT,
                'metal_no_objects_comfortable_clothes',
                '/\bsin\s+objetos\s+metalicos\b.{0,40}?\bportar\s+ropa\s+comoda\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'no_metal_comfortable_clothing'],
            ),
            self::rule(
                LaboratoryInstructionCategory::METAL_IMPLANT,
                'patient_no_metal_comfortable_clothes',
                '/El\s+paciente\s+debe\s+de\s+ir\s+sin\s+objetos\s+metalicos\s+y\s+portar\s+ropa\s+comoda\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'no_metal_comfortable_clothing'],
            ),
            self::rule(
                LaboratoryInstructionCategory::METAL_IMPLANT,
                'pacemaker_notify',
                '/\bsi\s+el\s+paciente\s+tiene\s+marcapasos\b.{0,80}?\binformar\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'pacemaker_notify_staff'],
            ),
            self::rule(
                LaboratoryInstructionCategory::METAL_IMPLANT,
                'pacemaker_notify_branch_technologist',
                '/Si\s+el\s+paciente\s+tiene\s+marcapasos\s+favor\s+de\s+informar\s+a\s+la\s+sucursal\s+y\s+al\s+tecnico\s+radiologo\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'pacemaker_notify_staff'],
            ),
            self::rule(
                LaboratoryInstructionCategory::METAL_IMPLANT,
                'metal_restriction',
                '/\b(?:metal|objetos?\s+met[aá]licos?)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'metal_restriction'],
            ),
            self::rule(
                LaboratoryInstructionCategory::METAL_IMPLANT,
                'pacemaker',
                '/\bmarcapasos\b|\bdispositivo\s+card[ií]aco\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'pacemaker'],
            ),
            self::rule(
                LaboratoryInstructionCategory::METAL_IMPLANT,
                'implant',
                '/\bimplantes?\s+met[aá]licos?\b|\bimplante\b|\bcl[ií]ps\b|\btornillos\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'implant'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function dietRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'soft_diet_days_before',
                '/\bdieta\s+blanda\b.{0,30}?\btres\s+d[ií]as\s+previos(?:\s+a\s+su\s+estudio)?\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'soft_diet_three_days_before'],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::SAMPLE_TIMING,
                'enema_evacuant_schedule',
                '/\baplicacion\s+de\s+dos\s+enemas\s+evacuantes\b.{0,140}?\b2\s+horas\s+previos\s+al\s+estudio\.?|\bdos\s+enemas\s+evacuantes\b.{0,120}?\b2\s+horas\s+previos\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'enema_evacuant_schedule'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'avoid_alcohol',
                '/\b(?:no\s+tomar|evitar)\s+alcohol(?:\s+\d+\s*horas?\s+antes)?\b|\balcohol\b.{0,20}?\b(?:antes|previo)\b|\bSuspender\s+ingesta\s+de\s+bebidas\s+alcoh[oó]licas,\s*drogas\s+y\s+tabaco\s+3\s+d[ií]as\s+antes\s+de\s+su\s+estudio\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_alcohol'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'avoid_topical_products',
                '/\bSin\s+aplicaci[oó]n\s+de\s+medicamentos,\s*cremas,\s*pomadas\s+v[ií]a\s+t[oó]pica\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_topical_products'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'genital_hand_hygiene',
                '/\bRealizar\s+el\s+aseo\s+de\s+genitales\s+y\s+manos,\s*con\s+agua\s+y\s+jab[oó]n\s+antes\s+de\s+la\s+recolecci[oó]n\s+de\s+la\s+muestra\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'genital_hand_hygiene'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'avoid_exercise',
                '/\b(?:evitar|no\s+realizar)\s+ejercicio\s+intenso\b|\bejercicio\s+(?:intenso|fuerte)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_exercise'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'avoid_fatty_foods',
                '/\b(?:evitar|no\s+consumir)\b.{0,30}?\b(?:alimentos?\s+grasos?|grasas)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_fatty_foods'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'avoid_caffeine',
                '/\b(?:evitar|no\s+tomar)\b.{0,20}?\bcafe[ií]na\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'avoid_caffeine'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'special_diet',
                '/\bdieta\b.{0,40}?\b(?:baja|sin|libre\s+de)\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'special_diet', 'detail_unspecified' => true],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function timingRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::SAMPLE_TIMING,
                'hours_before_days',
                '/\bantes\s+de\s+los\s+(\d+)\s*d[ií]as?\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'before_days', 'days' => (int) $m[1]],
                'day',
            ),
            self::rule(
                LaboratoryInstructionCategory::SAMPLE_TIMING,
                'hours_before_study',
                '/\b(\d+)\s*horas?\s+(?:antes|previos?)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn (array $m) => ['kind' => 'hours_before', 'hours' => (int) $m[1]],
                'hour',
            ),
            self::rule(
                LaboratoryInstructionCategory::SAMPLE_TIMING,
                'before_collection',
                '/\bantes\s+(?:del\s+estudio|de\s+la\s+(?:toma\s+del\s+estudio|recolecci[oó]n\s+de\s+la\s+muestra))\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'before_collection'],
            ),
            self::rule(
                LaboratoryInstructionCategory::SAMPLE_TIMING,
                'semen_weekday_availability_prefix',
                '/\bEl\s+estudio\s+solo\s+se\s+realiza\s+de\s+lunes\s+a\s+sabado\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'weekday_availability'],
            ),
            self::rule(
                LaboratoryInstructionCategory::SAMPLE_TIMING,
                'repeat_sample_schedule',
                '/\bRepetir\s+muestra\s+cada\s+\d+\s+horas\b.{0,60}?\bsemanas?\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'repeat_sample_schedule', 'detail_unspecified' => true],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function noPrepRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::NO_PREPARATION,
                'no_preparation',
                '/\b(?:sin|no\s+requiere)\s+preparaci[oó]n\b|\bno\s+requiere\s+ayuno\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'none'],
            ),
            self::rule(
                LaboratoryInstructionCategory::NO_PREPARATION,
                'no_preparation_full',
                '/\bno\s+requiere\s+ning[uú]n\s+tipo\s+de\s+preparaci[oó]n\b.{0,80}?\bno\s+se\s+requiere\s+ayuno\)?\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'none', 'includes_no_fasting' => true],
            ),
            self::rule(
                LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
                'branch_pickup_container',
                '/\b(?:acudir|acuda)\s+a\s+la\s+sucursal\b.{0,220}?\b(?:frasco|recipiente)\b.{0,120}?\b(?:farmacia|laboratorio)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'branch_pickup_container'],
            ),
            self::rule(
                LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
                'branch_pickup_sterile_urine_container',
                '/Acudir\s+a\s+la\s+sucursal\s+por\s+el\s+frasco\s+de\s+laboratorio\s+est[eé]ril\s+para\s+la\s+recolecci[oó]n\s+de\s+la\s+muestra\s+de\s+orina\s+o\s+comprarlo\s+en\s+la\s+farmacia\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'branch_pickup_container'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DOCUMENTATION,
                'medical_requisition',
                '/\b(?:presentar|presentarse)\b.{0,240}?\breceta\s+m[eé]dica\b.{0,240}?\bfirma\s+aut[oó]gra?fa\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'medical_requisition'],
            ),
            self::rule(
                LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
                'axilla_hygiene',
                '/\b(?:presentarse|acudir)\s+con\s+axilas\s+depiladas,\s*sin\s+desodorante,\s*crema,\s*ni\s+perfume\.?|\b(?:presentarse|acudir)\s+con\s+axilas\s+depiladas\b.{0,120}?\b(?:desodorante|perfume|crema)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'axilla_hygiene'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function containerRules(): array
    {
        return [
            self::rule(
                LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
                'sterile_container',
                '/\b(?:recipiente|envase|frasco)\s+(?:de\s+pl[aá]stico\s+)?est[eé]ril\b|\bllevar\s+recipiente\s+est[eé]ril\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'sterile_container'],
            ),
            self::rule(
                LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
                'semen_requested_container',
                '/\bRecolectar\s+el\s+eyaculado\s+en\s+un\s+frasco\s+de\s+pl[aá]stico\s+est[eé]ril\s+proporcionado\s+por\s+el\s+laboratorio\s+o\s+adquirido\s+en\s+una\s+farmacia\s+procure\s+no\s+tocar\s+los\s+bordes\s+del\s+recipiente\s+est[eé]ril\s*\(no\s+se\s+recibir[aá]n\s+frascos\s+de\s+vidrio\s+u\s+otro\s+tipo\s+de\s+contenedor\s+que\s+no\s+sea\s+el\s+solicitado\)\.?/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'semen_plastic_sterile_container_no_glass'],
            ),
            self::rule(
                LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
                'preservative',
                '/\b(?:preservador|[áa]cido\s+b[oó]rico|formol)\b/iu',
                LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                fn () => ['kind' => 'preservative'],
            ),
            self::rule(
                LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
                'bring_container',
                '/\b(?:llevar|traer)\b.{0,30}?\b(?:recipiente|envase|frasco)\b/iu',
                LaboratoryInstructionRecognitionStatus::PARTIAL,
                fn () => ['kind' => 'bring_container'],
            ),
        ];
    }

    /**
     * @param  callable(array<int|string, string|int>): array<string, mixed>  $builder
     * @return array<string, mixed>
     */
    private static function rule(
        string $category,
        string $requirementType,
        string $pattern,
        string $status,
        callable $builder,
        ?string $unit = null,
    ): array {
        return [
            'category' => $category,
            'requirement_type' => $requirementType,
            'pattern' => $pattern,
            'status' => $status,
            'unit' => $unit,
            'builder' => static function (array $matches) use ($builder, $unit): array {
                $normalizedValue = $builder($matches);

                return [
                    'normalized_value' => $normalizedValue,
                    'unit' => $unit,
                    'qualifiers' => [],
                ];
            },
        ];
    }
}
