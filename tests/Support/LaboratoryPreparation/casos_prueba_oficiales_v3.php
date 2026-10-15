<?php

/**
 * Casos oficiales de la hoja Casos_prueba (Entregable_Eulalio_FAMEDIC_Indicaciones_v3.xlsx).
 *
 * Generar con:
 *   php scripts/generate_casos_prueba_oficiales_v3.php docs/indicaciones-v3/Entregable_Eulalio_FAMEDIC_Indicaciones_v3.xlsx
 *
 * @return list<array{
 *     case_id: string,
 *     nivel: string,
 *     patient_context: string,
 *     order_studies_list: string,
 *     source_text: string,
 *     expected_route: string,
 *     expected_rules: string,
 *     acceptance_criteria: string,
 *     expected_output: string,
 *     studies: list<array{study_id: string, study_name: string, indications: string, appointment_required: bool|null, header_line: string}>
 * }>
 */
return (static function (): array {
    $jsonPath = __DIR__.'/casos_prueba_oficiales_v3.json';

    if (! is_readable($jsonPath)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($jsonPath), true);

    return is_array($decoded) ? $decoded : [];
})();
