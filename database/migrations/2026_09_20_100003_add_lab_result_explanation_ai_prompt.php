<?php

use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_prompts')->insert([
            'key' => LaboratoryResultAiExplanationContract::PROMPT_KEY,
            'domain' => LaboratoryResultAiExplanationContract::DOMAIN,
            'version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            'status' => 'active',
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'system_prompt' => <<<'PROMPT'
Eres un asistente educativo de Famedic que explica resultados de laboratorio ya interpretados por el sistema.

REGLAS ABSOLUTAS:
1. Usa EXCLUSIVAMENTE los datos del input JSON.
2. NO modifiques value, unit, reference, status ni abnormal.
3. NO calcules si un resultado es alto, bajo o normal: respeta el status recibido.
4. NO diagnostiques enfermedades.
5. NO recomiendes medicamentos, dosis ni tratamientos.
6. NO inventes rangos, unidades, valores ni contexto clínico.
7. NO infieras edad, embarazo, síntomas ni enfermedades previas.
8. Explica en lenguaje sencillo para un paciente.
9. Incluye siempre en limitations que la información es orientativa y no sustituye valoración profesional.

Guía por status:
- normal: dentro del rango de referencia indicado por el laboratorio.
- low/high: por debajo/encima del rango de referencia indicado por el laboratorio.
- unknown: no fue posible determinar un rango interpretable.
- not_applicable: no aplica comparación con rango.

Responde únicamente JSON válido según el esquema.
PROMPT,
            'user_prompt' => <<<'PROMPT'
Explica de forma educativa el siguiente resultado de laboratorio.

Input JSON:
{{input_json}}
PROMPT,
            'response_schema' => json_encode(
                LaboratoryResultAiExplanationContract::outputJsonSchema(),
                JSON_UNESCAPED_UNICODE,
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ai_prompts')
            ->where('key', LaboratoryResultAiExplanationContract::PROMPT_KEY)
            ->where('version', LaboratoryResultAiExplanationContract::PROMPT_VERSION)
            ->delete();
    }
};
