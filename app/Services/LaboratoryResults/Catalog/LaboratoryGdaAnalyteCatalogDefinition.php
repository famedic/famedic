<?php

namespace App\Services\LaboratoryResults\Catalog;

use App\Enums\LaboratoryAnalyteValueKind;

/**
 * Catálogo mínimo GDA derivado del corpus 8C-6 / 8C-6.3.
 *
 * Los códigos `code` son Famedic internal analyte codes — NO son LOINC.
 */
final class LaboratoryGdaAnalyteCatalogDefinition
{
    public const CODE_PREFIX = 'FAMEDIC_';

    /**
     * @return list<array{
     *     code: string,
     *     canonical_name: string,
     *     category: string,
     *     default_unit: ?string,
     *     value_kind: LaboratoryAnalyteValueKind,
     *     aliases: list<string>,
     *     corpus_evidence: string
     * }>
     */
    public static function entries(): array
    {
        return [
            ...self::cbcEntries(),
            ...self::chemistryEntries(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function cbcEntries(): array
    {
        $numeric = LaboratoryAnalyteValueKind::Numeric;

        return [
            [
                'code' => 'FAMEDIC_CBC_HGM',
                'canonical_name' => 'Hemoglobina corpuscular media',
                'category' => 'cbc',
                'default_unit' => 'pg',
                'value_kind' => $numeric,
                'aliases' => ['HGM', 'Hemoglobina Corpuscular Media', 'Hemoglobina corpuscular media'],
                'corpus_evidence' => 'Text v33/v34; CONFLICT pg vs g/dL',
            ],
            [
                'code' => 'FAMEDIC_CBC_HGB',
                'canonical_name' => 'Hemoglobina',
                'category' => 'cbc',
                'default_unit' => 'g/dL',
                'value_kind' => $numeric,
                'aliases' => ['Hemoglobina', 'HEMOGLOBINA', 'Hb', 'HB'],
                'corpus_evidence' => 'Text+Vision overlap v33/v34',
            ],
            [
                'code' => 'FAMEDIC_CBC_HCT',
                'canonical_name' => 'Hematocrito',
                'category' => 'cbc',
                'default_unit' => '%',
                'value_kind' => $numeric,
                'aliases' => ['Hematocrito', 'HEMATOCRITO', 'Hto'],
                'corpus_evidence' => 'TEXT_ONLY frecuente 8C-6.1',
            ],
            [
                'code' => 'FAMEDIC_CBC_CHCM',
                'canonical_name' => 'Concentración de hemoglobina corpuscular media',
                'category' => 'cbc',
                'default_unit' => 'g/dL',
                'value_kind' => $numeric,
                'aliases' => ['CHCM', 'Concentracion de hemoglobina corpuscular media'],
                'corpus_evidence' => 'Text v33/v34; TEXT_ONLY 8C-6.1',
            ],
            [
                'code' => 'FAMEDIC_CBC_RBC',
                'canonical_name' => 'Eritrocitos',
                'category' => 'cbc',
                'default_unit' => 'mill/µL',
                'value_kind' => $numeric,
                'aliases' => ['Eritrocitos', 'ERITROCITOS', 'RBC'],
                'corpus_evidence' => 'Text+Vision v33/v34 CONFLICT ref',
            ],
            [
                'code' => 'FAMEDIC_CBC_RDW',
                'canonical_name' => 'Ancho de distribución eritrocitaria',
                'category' => 'cbc',
                'default_unit' => '%',
                'value_kind' => $numeric,
                'aliases' => ['RDW', 'RDW-CV', 'Ancho de distribución eritrocitaria', 'Ancho de distribucion eritrocitaria'],
                'corpus_evidence' => 'MATCH v34 post 8C-6.2C',
            ],
            [
                'code' => 'FAMEDIC_CBC_PLT',
                'canonical_name' => 'Plaquetas',
                'category' => 'cbc',
                'default_unit' => 'miles/µL',
                'value_kind' => $numeric,
                'aliases' => ['Plaquetas', 'PLAQUETAS', 'PLT', 'Platelets'],
                'corpus_evidence' => 'CONFLICT v33/v34',
            ],
            [
                'code' => 'FAMEDIC_CBC_NEUT',
                'canonical_name' => 'Neutrófilos totales',
                'category' => 'cbc',
                'default_unit' => '%',
                'value_kind' => $numeric,
                'aliases' => ['Neutrófilos totales', 'Neutrofilos totales', 'NEUTROFILOS TOTALES'],
                'corpus_evidence' => 'TEXT_ONLY frecuente 8C-6.1',
            ],
            [
                'code' => 'FAMEDIC_CBC_NEUT_SEG',
                'canonical_name' => 'Neutrófilos segmentados',
                'category' => 'cbc',
                'default_unit' => '%',
                'value_kind' => $numeric,
                'aliases' => ['Neutrófilos segmentados', 'Neutrofilos segmentados', 'NEUTROFILOS SEGMENTADOS'],
                'corpus_evidence' => 'Text multi-columna 8C-6.2B fixtures GDA',
            ],
            [
                'code' => 'FAMEDIC_CBC_VPM',
                'canonical_name' => 'Volumen plaquetario medio',
                'category' => 'cbc',
                'default_unit' => 'fL',
                'value_kind' => $numeric,
                'aliases' => ['VPM', 'Volumen plaquetario medio', 'Volumen Plaquetario Medio'],
                'corpus_evidence' => 'TEXT_ONLY frecuente 8C-6.1',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function chemistryEntries(): array
    {
        $numeric = LaboratoryAnalyteValueKind::Numeric;

        return [
            [
                'code' => 'FAMEDIC_CHEM_GLU',
                'canonical_name' => 'Glucosa',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Glucosa', 'GLUCOSA'],
                'corpus_evidence' => 'VISION_ONLY 8C-6.1; Text post 8C-6.2B',
            ],
            [
                'code' => 'FAMEDIC_CHEM_CREA',
                'canonical_name' => 'Creatinina',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Creatinina', 'CREATININA'],
                'corpus_evidence' => 'VISION_ONLY 8C-6.1',
            ],
            [
                'code' => 'FAMEDIC_CHEM_UA',
                'canonical_name' => 'Ácido úrico',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Acido urico', 'Ácido úrico', 'ACIDO URICO'],
                'corpus_evidence' => 'VISION_ONLY 8C-6.1',
            ],
            [
                'code' => 'FAMEDIC_CHEM_CHOL',
                'canonical_name' => 'Colesterol total',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Colesterol total', 'COLESTEROL TOTAL', 'Colesterol Total'],
                'corpus_evidence' => 'VISION_ONLY 8C-6.1',
            ],
            [
                'code' => 'FAMEDIC_CHEM_TG',
                'canonical_name' => 'Triglicéridos',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Triglicéridos', 'Trigliceridos', 'TRIGLICERIDOS'],
                'corpus_evidence' => 'VISION_ONLY 8C-6.1',
            ],
            [
                'code' => 'FAMEDIC_CHEM_CA',
                'canonical_name' => 'Calcio',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Calcio', 'CALCIO'],
                'corpus_evidence' => 'VISION_ONLY 8C-6.1',
            ],
            [
                'code' => 'FAMEDIC_CHEM_UREA',
                'canonical_name' => 'Urea sérica',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Urea serica', 'UREA SERICA', 'Urea sérica'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_BUN',
                'canonical_name' => 'Nitrógeno ureico',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Nitrogeno ureico', 'NITROGENO UREICO', 'Nitrógeno ureico', 'BUN'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_BILI_T',
                'canonical_name' => 'Bilirrubina total',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Bilirrubina total', 'BILIRRUBINA TOTAL'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_BILI_D',
                'canonical_name' => 'Bilirrubina directa',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Bilirrubina directa', 'BILIRRUBINA DIRECTA'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_BILI_I',
                'canonical_name' => 'Bilirrubina indirecta',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Bilirrubina indirecta', 'BILIRRUBINA INDIRECTA'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_TP',
                'canonical_name' => 'Proteínas totales',
                'category' => 'chemistry',
                'default_unit' => 'g/dL',
                'value_kind' => $numeric,
                'aliases' => ['Proteinas totales', 'PROTEINAS TOTALES', 'Proteínas totales'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_ALB',
                'canonical_name' => 'Albúmina',
                'category' => 'chemistry',
                'default_unit' => 'g/dL',
                'value_kind' => $numeric,
                'aliases' => ['Albumina', 'ALBUMINA', 'Albúmina'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_GLOB',
                'canonical_name' => 'Globulinas',
                'category' => 'chemistry',
                'default_unit' => 'g/dL',
                'value_kind' => $numeric,
                'aliases' => ['Globulinas', 'GLOBULINAS'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_AG_RATIO',
                'canonical_name' => 'Relación albúmina/globulina',
                'category' => 'chemistry',
                'default_unit' => null,
                'value_kind' => $numeric,
                'aliases' => [
                    'Relacion albumina globulina',
                    'RELACION ALBUMINA GLOBULINA',
                    'Relacion albumina/globulina',
                    'RELACION ALBUMINA/GLOBULINA',
                    'RELACION ALBUMINA',
                ],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_AST',
                'canonical_name' => 'Aspartato aminotransferasa',
                'category' => 'chemistry',
                'default_unit' => 'U/L',
                'value_kind' => $numeric,
                'aliases' => [
                    'AST',
                    'TGO',
                    'AST/TGO',
                    'Aspartato amino transferasa (AST/TGO)',
                    'ASPARTATO AMINO TRANSFERASA (AST/TGO)',
                ],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_K',
                'canonical_name' => 'Potasio',
                'category' => 'chemistry',
                'default_unit' => 'mmol/L',
                'value_kind' => $numeric,
                'aliases' => ['Potasio', 'POTASIO'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_P',
                'canonical_name' => 'Fósforo',
                'category' => 'chemistry',
                'default_unit' => 'mg/dL',
                'value_kind' => $numeric,
                'aliases' => ['Fosforo', 'FOSFORO', 'Fósforo'],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
            [
                'code' => 'FAMEDIC_CHEM_GGT',
                'canonical_name' => 'Gamma glutamil transferasa',
                'category' => 'chemistry',
                'default_unit' => 'U/L',
                'value_kind' => $numeric,
                'aliases' => [
                    'GGT',
                    'Gamma glutamil transferasa',
                    'GAMMA GLUTAMIL TRANSFERASA',
                ],
                'corpus_evidence' => 'Purchase #2009 PERFIL BIOQUIMICO 24 PDF',
            ],
        ];
    }
}
