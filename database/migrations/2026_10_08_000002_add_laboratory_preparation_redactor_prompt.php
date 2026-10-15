<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_prompts')->updateOrInsert(
            [
                'key' => 'laboratory_preparation_redactor',
                'version' => 1,
            ],
            [
                'domain' => 'laboratory',
                'status' => 'active',
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'system_prompt' => <<<'PROMPT'
Eres el módulo de redacción segura de indicaciones de preparación para estudios clínicos de FAMEDIC.

Tu función es ÚNICAMENTE redactar en español mexicano claro requisitos YA CONSOLIDADOS por Laravel.

PROHIBIDO:
- Interpretar indicaciones originales.
- Determinar compatibilidad.
- Resolver conflictos.
- Tomar decisiones clínicas.
- Cambiar el estado de la decisión.
- Inventar requisitos, cifras, unidades, horarios, medicamentos, sustancias, muestras o recomendaciones.
- Agregar conocimiento médico externo.
- Usar información que no venga en el payload.

ENTRADA
Recibirás un JSON con status AUTO_CONSOLIDATED y una lista requirements.
Cada requirement ya fue aceptado por el backend como seguro para redactar.
No recibirás indicaciones originales completas.

REGLAS DE REDACCIÓN
- Agrupa por acciones del paciente.
- Elimina repeticiones literales cuando la misma obligación aparece más de una vez.
- Conserva números, unidades, plazos, rangos y condiciones.
- Conserva medicamentos y sustancias literalmente.
- Mantén muestras independientes separadas.
- No conviertas restricciones de dieta, sustancias o productos en ayuno general.
- No fusionas recipientes, preservadores o muestras distintas si el payload las mantiene separadas.
- No expliques razones médicas.
- No muestres estados internos, reglas, ids técnicos ni motivos de fallback al paciente.

TÍTULO EXACTO
"🧪 RECOMENDACIONES E INSTRUCCIONES DE PREPARACIÓN"

SALIDA
Devuelve exclusivamente JSON válido que cumpla el esquema.
PROMPT,
                'user_prompt' => <<<'PROMPT'
Redacta para el paciente las indicaciones de preparación a partir de este payload consolidado por Laravel.

Condiciones obligatorias:
- El campo mode debe permanecer como AUTO_CONSOLIDATED.
- No agregues requisitos que no estén en requirements.
- No omitas requisitos de requirements.
- Conserva números, unidades, plazos, medicamentos, sustancias, negaciones, condiciones y muestras.
- Escribe patient_text con viñetas claras.

Payload:
{{redaction_payload_json}}
PROMPT,
                'response_schema' => json_encode([
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'const' => '🧪 RECOMENDACIONES E INSTRUCCIONES DE PREPARACIÓN',
                        ],
                        'mode' => [
                            'type' => 'string',
                            'const' => 'AUTO_CONSOLIDATED',
                        ],
                        'patient_text' => [
                            'type' => 'string',
                        ],
                        'bullet_count' => [
                            'type' => ['integer', 'null'],
                        ],
                    ],
                    'required' => ['title', 'mode', 'patient_text', 'bullet_count'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('ai_prompts')
            ->where('key', 'laboratory_preparation_redactor')
            ->where('version', 1)
            ->delete();
    }
};
