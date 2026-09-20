<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ai_prompts')) {
            return;
        }

        $exists = DB::table('ai_prompts')
            ->where('key', 'lab_results_extraction')
            ->where('version', 1)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('ai_prompts')->insert([
            'key' => 'lab_results_extraction',
            'domain' => 'lab_results_extraction',
            'version' => 1,
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
            'response_schema' => json_encode([
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
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ai_prompts')) {
            return;
        }

        DB::table('ai_prompts')
            ->where('key', 'lab_results_extraction')
            ->where('version', 1)
            ->delete();
    }
};
