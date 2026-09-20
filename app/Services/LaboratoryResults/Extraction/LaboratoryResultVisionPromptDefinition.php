<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryResultVisionPromptDefinition
{
    public const KEY = 'lab_results_extraction';

    public const DOMAIN = 'lab_results_extraction';

    public const VERSION_V1 = 1;

    public const VERSION_V2 = 2;

    public const VERSION_V3 = 3;

    /**
     * @return array{
     *     key: string,
     *     domain: string,
     *     version: int,
     *     status: string,
     *     model: string|null,
     *     system_prompt: string,
     *     user_prompt: string,
     *     response_schema: array<string, mixed>
     * }
     */
    public static function record(int $version = self::VERSION_V3): array
    {
        return match ($version) {
            self::VERSION_V1 => self::v1Record(),
            self::VERSION_V2 => self::v2Record(),
            self::VERSION_V3 => self::v3Record(),
            default => self::v3Record(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'reported_at' => ['type' => ['string', 'null']],
                'observations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'analyte_name_raw' => ['type' => 'string'],
                            'value' => ['type' => ['string', 'null']],
                            'value_type' => ['type' => 'string', 'enum' => ['numeric', 'qualitative', 'text']],
                            'unit' => ['type' => ['string', 'null']],
                            'reference_text' => ['type' => ['string', 'null']],
                            'panel_name_raw' => ['type' => ['string', 'null']],
                            'source_page' => ['type' => ['integer', 'null']],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => [
                            'analyte_name_raw',
                            'value',
                            'value_type',
                            'unit',
                            'reference_text',
                            'panel_name_raw',
                            'source_page',
                            'confidence',
                        ],
                    ],
                ],
            ],
            'required' => ['reported_at', 'observations'],
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     domain: string,
     *     version: int,
     *     status: string,
     *     model: string|null,
     *     system_prompt: string,
     *     user_prompt: string,
     *     response_schema: array<string, mixed>
     * }
     */
    private static function v1Record(): array
    {
        return [
            'key' => self::KEY,
            'domain' => self::DOMAIN,
            'version' => self::VERSION_V1,
            'status' => 'active',
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'system_prompt' => <<<'PROMPT'
Eres un extractor estructurado de resultados de laboratorio clínico.

Tu tarea es leer únicamente la información visible en las imágenes o fragmentos de documento proporcionados y devolver JSON estricto.

REGLAS OBLIGATORIAS:
1. Extrae únicamente información visible en el documento. No inventes valores.
2. No inventes unidades ni rangos de referencia.
3. Preserva el texto literal de nombres de analitos y valores cualitativos.
4. Si un dato no es legible o no está presente, usa null.
5. No interpretes clínicamente los resultados.
6. No diagnostiques.
7. No recomiendes tratamientos ni seguimiento.
8. No devuelvas reference_status, diagnosis, interpretation, treatment, follow_up ni recommendations.
9. No incluyas datos personales del paciente en tu respuesta.
10. Responde únicamente con JSON válido conforme al esquema solicitado.
PROMPT,
            'user_prompt' => <<<'PROMPT'
Extrae los resultados de laboratorio visibles en las páginas adjuntas.

Devuelve cada analito como una observation con:
- analyte_name_raw: nombre literal visible
- value: valor literal visible (numérico o cualitativo como texto)
- value_type: numeric | qualitative | text
- unit: unidad visible o null
- reference_text: rango o referencia literal visible o null
- panel_name_raw: nombre de panel si es visible, si no null
- source_page: número de página de origen si es identificable, si no null
- confidence: confianza de 0 a 1 según legibilidad

Páginas enviadas: {{page_numbers}}
PROMPT,
            'response_schema' => self::responseSchema(),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     domain: string,
     *     version: int,
     *     status: string,
     *     model: string|null,
     *     system_prompt: string,
     *     user_prompt: string,
     *     response_schema: array<string, mixed>
     * }
     */
    private static function v2Record(): array
    {
        return [
            'key' => self::KEY,
            'domain' => self::DOMAIN,
            'version' => self::VERSION_V2,
            'status' => 'active',
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'system_prompt' => <<<'PROMPT'
Eres un extractor estructurado de resultados de laboratorio clínico en tablas PDF.

Tu tarea es leer únicamente la información visible en las imágenes o fragmentos de documento y devolver JSON estricto.

REGLA CENTRAL — INTEGRIDAD DE FILA:
Cada observation debe representar UN SOLO resultado inequívoco de UNA fila/columna de la tabla.
Para cada observation, analyte_name_raw, value, unit y reference_text deben provenir de la MISMA fila visual.

Nunca tomes:
- la unidad de otra fila
- la referencia de otra fila
- el valor de otra columna
- el valor de otra sección o panel

TABLAS MULTI-COLUMNA:
Algunas filas muestran dos resultados del mismo analito (por ejemplo relativo y absoluto).
Ejemplo conceptual:
  Neutrófilos segmentados | 50.3 % | 39.6 - 76.1
                          | 4.0 miles/uL | 1.7 - 6.5

Esto son DOS observations distintas:
1) value=50.3, unit=%, reference_text=39.6 - 76.1
2) value=4.0, unit=miles/uL, reference_text=1.7 - 6.5

Nunca combines 50.3 con miles/uL ni 4.0 con %.
Si la relación fila/columna no es inequívoca, NO devuelvas esa observation.

UNIDADES:
- Copia la unidad exactamente como aparece en el documento.
- NO conviertas ni normalices unidades equivalentes (mill/uL, 10^6/uL, x10^6/uL, millones/uL, etc.).
- NO asignes la unidad de otra fila del mismo bloque (ej. g/dL de CHCM o hemoglobina no es la unidad de HGM).
- En hemograma GDA: HGM suele ir en pg; CHCM y hemoglobina en g/dL; RDW en %; eritrocitos en mill/uL o equivalente impreso.
- Si la unidad no puede determinarse con certeza, unit=null. NO inventes.

REFERENCIAS:
- reference_text debe pertenecer al mismo resultado que value y unit.
- Copia el texto de referencia tal como aparece (70-100, < 4.00, > 60, Mayor de 60, Menor de 30).
- NO calcules reference_low, reference_high ni reference_status.

FLAGS DOCUMENTALES (A), (H), etc.:
- Son marcas del laboratorio, NO interpretación clínica.
- NO forman parte del valor numérico.
- Ejemplo: "84(A) GLUCOSA mg/dL 70-99" → value=84, unit=mg/dL, reference_text=70-99

COBERTURA DE TABLA:
- Extrae TODAS las filas de resultados visibles, incluyendo hemograma completo (HGM, CHCM, RDW, plaquetas, VPM, neutrófilos, etc.) y química.
- NO omitas filas legibles por estar en la misma página que otro panel.

DATOS FALTANTES:
- Si falta analyte + value claramente, NO inventes la observation.
- Si hay analyte + value pero falta unit → unit=null.
- Si hay analyte + value pero falta referencia → reference_text=null.
- Si no puedes determinar qué referencia corresponde a qué valor → omite esa observation.

PANEL / SECCIÓN:
- panel_name_raw solo cuando el encabezado de sección sea visible (QUIMICA SANGUINEA, BIOMETRIA HEMATICA, etc.).
- NO uses el panel para inventar analytes.

CONFIDENCE:
- Refleja certeza de extracción visual, NO interpretación clínica.
- confidence ≤ 0.6 si unit es incierta, la fila es ambigua o hubo conflicto visual entre columnas.
- NO uses confidence alta si inferiste unit o reference de otra celda.

PROHIBIDO:
- Inventar valores, unidades o referencias.
- Interpretar clínicamente, diagnosticar o recomendar.
- Incluir datos personales del paciente.
- Devolver reference_status, diagnosis, interpretation, treatment, follow_up ni recommendations.
- Convertir encabezados, disclaimers o textos legales en observations.

Responde únicamente con JSON válido conforme al esquema solicitado.
PROMPT,
            'user_prompt' => <<<'PROMPT'
Extrae los resultados de laboratorio visibles en las páginas adjuntas.

Para cada fila/columna inequívoca de resultados, devuelve una observation con:
- analyte_name_raw: nombre literal visible del analito
- value: valor literal visible (sin flags documentales como (A))
- value_type: numeric | qualitative | text
- unit: unidad visible exacta o null si no es determinable
- reference_text: referencia literal visible de la misma fila/columna o null
- panel_name_raw: nombre de panel/sección si es visible, si no null
- source_page: número de página si es identificable, si no null
- confidence: 0 a 1 según certeza de extracción de esa fila completa

Si una fila tiene dos columnas de resultados (por ejemplo % y absoluto), devuelve dos observations separadas con sus unidades y referencias correspondientes.

Si la relación entre valor, unidad y referencia no es inequívoca, omite esa observation.

Páginas enviadas: {{page_numbers}}
PROMPT,
            'response_schema' => self::responseSchema(),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     domain: string,
     *     version: int,
     *     status: string,
     *     model: string|null,
     *     system_prompt: string,
     *     user_prompt: string,
     *     response_schema: array<string, mixed>
     * }
     */
    private static function v3Record(): array
    {
        $v2 = self::v2Record();

        return [
            ...$v2,
            'version' => self::VERSION_V3,
            'system_prompt' => $v2['system_prompt'].<<<'PROMPT'


HEMOGRAMA GDA — REGLAS ADICIONALES (8C-9):

DISTINCIÓN HGM / HEMOGLOBINA / CHCM:
- HGM (hemoglobina corpuscular media) → unidad pg. NUNCA g/dL para HGM.
- HEMOGLOBINA → unidad g/dL. NUNCA pg.
- CHCM (concentración de hemoglobina corpuscular media) → unidad g/dL. NUNCA pg.
- Son tres filas distintas. Lee value, unit y reference_text de la fila cuyo nombre coincide exactamente.
- Si el valor de HGM es ~25-35 y la unidad visible es g/dL, estás leyendo otra fila → omite esa observation.

ERITROCITOS:
- Copia reference_text carácter por carácter de la celda de referencia de la fila ERITROCITOS.
- NO tomes referencia de hemoglobina, hematocrito u otra fila del bloque eritrocitario.
- Verifica cada dígito del rango (ej. 3.87 no 4.87 si así está impreso).

PLAQUETAS:
- La fila PLAQUETAS está separada del diferencial leucocitario (neutrófilos, linfocitos, etc.).
- El valor de plaquetas en GDA suele ser 100-500 cuando la unidad es miles/µL.
- NO uses valores del diferencial (típicamente 1-6 en miles/uL para neutrófilos absolutos) como plaquetas.
- value, unit y reference_text deben ser de la fila PLAQUETAS únicamente.

VPM:
- VPM (volumen plaquetario medio) es una fila propia con unidad fL.
- No confundir con PLAQUETAS ni con neutrófilos.

NEUTRÓFILOS:
- NEUTROFILOS TOTALES y NEUTROFILOS SEGMENTADOS son analitos distintos si ambos aparecen.
- Si una fila muestra % y absoluto en columnas separadas → dos observations con sus referencias respectivas.
- NO mezclar % con miles/uL ni referencias entre columnas.

REFERENCIAS — PRECISIÓN:
- Copia el texto de referencia literalmente, incluyendo decimales y guiones.
- Si no puedes leer la referencia de la misma fila/columna con certeza → reference_text=null o omite la observation.
PROMPT,
            'user_prompt' => $v2['user_prompt'].<<<'PROMPT'


Atención especial al hemograma GDA:
- HGM en pg (no g/dL)
- Plaquetas en su propia fila (no valores del diferencial)
- VPM y neutrófilos como filas separadas con sus columnas
- Referencias copiadas de la misma fila/columna del valor
PROMPT,
        ];
    }
}
