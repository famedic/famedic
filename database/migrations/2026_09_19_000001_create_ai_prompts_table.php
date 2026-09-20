<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('domain', 80);
            $table->unsignedInteger('version');
            $table->string('status', 40)->default('draft');
            $table->string('model')->nullable();
            $table->longText('system_prompt');
            $table->longText('user_prompt');
            $table->json('response_schema')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'version'], 'ai_prompts_key_version_unique');
            $table->index(['domain', 'key', 'status'], 'ai_prompts_domain_key_status_idx');
        });

        DB::table('ai_prompts')->insert([
            'key' => 'laboratory_preparation_summary',
            'domain' => 'laboratory',
            'version' => 1,
            'status' => 'active',
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'system_prompt' => <<<'PROMPT'
Eres el asistente de preparación de estudios de laboratorio de Famedic.

Tu tarea es consolidar las indicaciones proporcionadas para múltiples estudios de laboratorio comprados por un paciente.

REGLAS:
1. Utiliza exclusivamente la información proporcionada.
2. No inventes indicaciones.
3. No agregues recomendaciones médicas que no estén presentes en las indicaciones fuente.
4. No cambies cantidades, tiempos, horarios, restricciones o condiciones.
5. Identifica indicaciones repetidas o compatibles.
6. Agrupa únicamente instrucciones que sean compatibles.
7. Si dos estudios tienen instrucciones diferentes, conserva ambas.
8. Si existe una indicación especial que no pueda agruparse de forma segura, debe permanecer como indicación especial.
9. No diagnostiques.
10. No interpretes resultados médicos.
11. No conviertas una indicación específica en una recomendación general si la fuente no lo permite.
12. Cuando exista incertidumbre, conserva la información original.
13. El resultado debe ser claro y fácil de entender para un paciente.
14. Debes conservar la trazabilidad hacia los estudios originales mediante source_item_ids.

Responde únicamente con JSON válido que cumpla el esquema solicitado.
PROMPT,
            'user_prompt' => <<<'PROMPT'
Consolida las indicaciones de preparación de los estudios de laboratorio incluidos en esta compra.

Usa únicamente los datos de los items proporcionados. No uses conocimiento externo.

Items:
{{items_json}}
PROMPT,
            'response_schema' => json_encode([
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'sections' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'key' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'content' => ['type' => 'string'],
                                'source_item_ids' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'integer'],
                                ],
                            ],
                            'required' => ['key', 'title', 'content', 'source_item_ids'],
                        ],
                    ],
                    'special_instructions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'content' => ['type' => 'string'],
                                'source_item_ids' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'integer'],
                                ],
                            ],
                            'required' => ['content', 'source_item_ids'],
                        ],
                    ],
                    'individual_instructions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'study_name' => ['type' => 'string'],
                                'content' => ['type' => 'string'],
                                'source_item_id' => ['type' => 'integer'],
                            ],
                            'required' => ['study_name', 'content', 'source_item_id'],
                        ],
                    ],
                ],
                'required' => ['summary', 'sections', 'special_instructions', 'individual_instructions'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};
