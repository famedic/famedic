<?php

use App\Models\AiExecution;
use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;
use App\Services\LaboratoryPreparation\LaboratoryPreparationOpenAiRedactor;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRedactionPayloadBuilder;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRedactionValidator;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;
use App\Services\OpenAi\OpenAiClient;

function redactorAutoDecision(array $consolidatedOverrides = []): LaboratoryPreparationDecision
{
    $consolidated = array_replace_recursive([
        'fasting' => [
            'exact_hours' => 8,
            'sources' => [
                [
                    'study_id' => 'study-1',
                    'study_name' => 'INDICE HOMA',
                    'source_span' => 'Ayuno de 8 horas',
                    'category' => 'fasting',
                    'requirement_type' => 'fasting_exact_hours',
                    'normalized_value' => ['kind' => 'exact_hours', 'hours' => 8],
                ],
            ],
            'dietary_restrictions' => [],
        ],
        'diet_substance_exercise' => [
            'blocks' => [
                [
                    'study_ids' => ['study-2'],
                    'study_names' => ['ANGIORESONANCIA'],
                    'sources' => [
                        [
                            'study_id' => 'study-2',
                            'study_name' => 'ANGIORESONANCIA',
                            'source_span' => 'Si el paciente toma metformina, es necesario suspenderlo 24 horas antes y 24 horas despues del estudio.',
                            'category' => 'medication',
                            'requirement_type' => 'metformin_hold_before_after',
                            'normalized_value' => ['kind' => 'metformin_hold', 'hours_before' => 24, 'hours_after' => 24],
                        ],
                        [
                            'study_id' => 'study-3',
                            'study_name' => 'ESPERMOGRAMA',
                            'source_span' => 'Recolectar el eyaculado en un frasco de plástico estéril; no se recibirán frascos de vidrio.',
                            'category' => 'container_preservative',
                            'requirement_type' => 'semen_requested_container',
                            'normalized_value' => ['kind' => 'semen_plastic_sterile_container_no_glass'],
                        ],
                    ],
                ],
            ],
        ],
        'container_preservative' => [
            'blocks' => [
                [
                    'study_ids' => ['study-4'],
                    'study_names' => ['ORINA 24 HORAS'],
                    'sources' => [
                        [
                            'study_id' => 'study-4',
                            'study_name' => 'ORINA 24 HORAS',
                            'source_span' => 'Recolectar orina de 24 horas en contenedor con preservador.',
                            'category' => 'urine_24h',
                            'requirement_type' => 'urine_24h_container_preservative',
                            'normalized_value' => ['kind' => 'urine_24h_container_preservative'],
                        ],
                    ],
                ],
            ],
        ],
    ], $consolidatedOverrides);

    return LaboratoryPreparationDecision::autoConsolidated(
        orderId: 'ORD-REDACTOR',
        rulesVersion: LaboratoryPreparationRuleEngine::RULES_VERSION,
        rulesApplied: ['R01', 'R09', 'R18'],
        consolidatedRequirements: $consolidated,
        originalInstructions: [
            [
                'study_id' => 'study-1',
                'study_name' => 'INDICE HOMA',
                'source_instructions' => 'Paciente Juan Pérez. Ayuno de 8 horas.',
            ],
            [
                'study_id' => 'study-2',
                'study_name' => 'ANGIORESONANCIA',
                'source_instructions' => 'Si el paciente toma metformina, es necesario suspenderlo 24 horas antes y 24 horas despues del estudio.',
            ],
        ],
    );
}

function redactorFallbackDecision(): LaboratoryPreparationDecision
{
    return LaboratoryPreparationDecision::fallbackOriginal(
        orderId: 'ORD-FALLBACK',
        rulesVersion: LaboratoryPreparationRuleEngine::RULES_VERSION,
        rulesApplied: ['R01'],
        fallbackReason: 'incompatible_fasting_interval',
        fallbackCategory: LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT,
        needsProviderReview: true,
        originalInstructions: [
            ['study_id' => 'a', 'study_name' => 'A', 'source_instructions' => 'Ayuno de 6 a 8 horas'],
            ['study_id' => 'b', 'study_name' => 'B', 'source_instructions' => 'Ayuno de 10 a 12 horas'],
        ],
    );
}

function redactorValidContent(): array
{
    return [
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => implode("\n", [
            '• Acude con ayuno de 8 horas.',
            '• Si tomas metformina, suspenderlo 24 horas antes y 24 horas despues del estudio.',
            '• Recolectar el eyaculado en un frasco de plástico estéril; no se recibirán frascos de vidrio.',
            '• Recolectar orina de 24 horas en contenedor con preservador.',
        ]),
        'bullet_count' => 4,
    ];
}

function bindRedactorOpenAiReturning(array $content): object
{
    $mock = Mockery::mock(OpenAiClient::class);
    $mock->shouldReceive('chatCompletionWithMetadata')
        ->once()
        ->andReturn([
            'content' => $content,
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            'raw' => [],
        ]);

    app()->instance(OpenAiClient::class, $mock);

    return $mock;
}

function bindRedactorOpenAiThrowing(Throwable $exception): object
{
    $mock = Mockery::mock(OpenAiClient::class);
    $mock->shouldReceive('chatCompletionWithMetadata')
        ->once()
        ->andThrow($exception);

    app()->instance(OpenAiClient::class, $mock);

    return $mock;
}

it('activa un prompt versionado separado para redacción segura', function () {
    $prompt = app(LaboratoryPreparationOpenAiRedactor::class)->activePrompt();

    expect($prompt->key)->toBe('laboratory_preparation_redactor')
        ->and($prompt->version)->toBe(1)
        ->and($prompt->system_prompt)->toContain('ÚNICAMENTE redactar')
        ->and($prompt->user_prompt)->toContain('{{redaction_payload_json}}')
        ->and($prompt->response_schema['properties']['mode']['const'])->toBe('AUTO_CONSOLIDATED');
});

it('redacta una decisión AUTO válida usando únicamente el payload consolidado', function () {
    bindRedactorOpenAiReturning(redactorValidContent());

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeTrue()
        ->and($result->content['patient_text'])->toContain('ayuno de 8 horas')
        ->and($result->content['patient_text'])->toContain('metformina')
        ->and($result->content['patient_text'])->toContain('24 horas')
        ->and($result->aiExecution)->toBeInstanceOf(AiExecution::class)
        ->and($result->aiExecution->status)->toBe(AiExecution::STATUS_SUCCEEDED);
});

it('no llama OpenAI cuando la decisión es FALLBACK_ORIGINAL', function () {
    $mock = Mockery::mock(OpenAiClient::class);
    $mock->shouldNotReceive('chatCompletionWithMetadata');
    app()->instance(OpenAiClient::class, $mock);

    $decision = redactorFallbackDecision();
    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact($decision);

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->decision)->toBe($decision)
        ->and($result->decision->needsProviderReview)->toBeTrue()
        ->and(AiExecution::query()->count())->toBe(0);
});

it('produce fallback técnico seguro ante timeout sin requerir revisión médica', function () {
    bindRedactorOpenAiThrowing(new RuntimeException('OpenAI timeout'));

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($result->decision->fallbackCategory)->toBe(LaboratoryPreparationDecision::FALLBACK_TECHNICAL_AI_FAILURE)
        ->and($result->decision->needsProviderReview)->toBeFalse()
        ->and($result->aiExecution->status)->toBe(AiExecution::STATUS_FAILED);
});

it('produce fallback técnico seguro ante error de proveedor', function () {
    bindRedactorOpenAiThrowing(new RuntimeException('OpenAI request failed with status 500'));

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->decision->fallbackReason)->toBe('ai_redaction_failed')
        ->and($result->technicalFailureReason)->toBe('ai_redaction_failed')
        ->and($result->aiExecution->error)->toContain('status 500');
});

it('produce fallback técnico seguro ante JSON inválido', function () {
    bindRedactorOpenAiThrowing(new RuntimeException('OpenAI returned non-JSON structured content.'));

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->decision->fallbackCategory)->toBe(LaboratoryPreparationDecision::FALLBACK_TECHNICAL_AI_FAILURE)
        ->and($result->aiExecution->status)->toBe(AiExecution::STATUS_FAILED);
});

it('rechaza una cifra inventada por la IA', function () {
    $content = redactorValidContent();
    $content['patient_text'] .= "\n• Llegar 12 minutos antes.";
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('invented number [12]');
});

it('rechaza una cifra omitida por la IA', function () {
    $content = redactorValidContent();
    $content['patient_text'] = str_replace('8 horas', 'ayuno indicado', $content['patient_text']);
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('omitted number [8]');
});

it('rechaza un medicamento alterado por la IA', function () {
    $content = redactorValidContent();
    $content['patient_text'] = str_replace('metformina', 'medicamento', $content['patient_text']);
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('metformina');
});

it('rechaza un requisito consolidado omitido', function () {
    $content = redactorValidContent();
    $content['patient_text'] = preg_replace('/^.*frasco.*\R?/mu', '', $content['patient_text']);
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('frasco');
});

it('rechaza una unidad modificada', function () {
    $decision = LaboratoryPreparationDecision::autoConsolidated(
        orderId: 'ORD-FASTING-ONLY',
        rulesVersion: LaboratoryPreparationRuleEngine::RULES_VERSION,
        rulesApplied: ['R01'],
        consolidatedRequirements: [
            'fasting' => [
                'exact_hours' => 8,
                'sources' => [],
                'dietary_restrictions' => [],
            ],
        ],
        originalInstructions: [
            ['study_id' => 'study-1', 'study_name' => 'INDICE HOMA', 'source_instructions' => 'Ayuno de 8 horas.'],
        ],
    );
    $content = [
        'title' => LaboratoryPreparationRedactionValidator::TITLE,
        'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
        'patient_text' => '• Acude con ayuno de 8 días.',
        'bullet_count' => 1,
    ];
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact($decision);

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('omitted unit [hora]');
});

it('rechaza una fusión incorrecta de muestras al omitir requisitos de una muestra independiente', function () {
    $content = redactorValidContent();
    $content['patient_text'] = implode("\n", [
        '• Acude con ayuno de 8 horas.',
        '• Si tomas metformina, suspenderlo 24 horas antes y 24 horas despues del estudio.',
        '• Recolecta las muestras en un frasco de plástico estéril sin frascos de vidrio.',
    ]);
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('orina');
});

it('rechaza un intento de modificar el estado por parte de la IA', function () {
    $content = redactorValidContent();
    $content['mode'] = LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL;
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('change the decision status');
});

it('rechaza texto vacío', function () {
    $content = redactorValidContent();
    $content['patient_text'] = '   ';
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('patient_text is empty');
});

it('rechaza duplicaciones no autorizadas', function () {
    $content = redactorValidContent();
    $content['patient_text'] .= "\n• Acude con ayuno de 8 horas.";
    bindRedactorOpenAiReturning($content);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    expect($result->usedOpenAiText())->toBeFalse()
        ->and($result->aiExecution->error)->toContain('repeats an instruction');
});

it('no incluye indicaciones originales completas ni PII innecesaria en el payload enviado a OpenAI', function () {
    $capturedMessages = null;
    $mock = Mockery::mock(OpenAiClient::class);
    $mock->shouldReceive('chatCompletionWithMetadata')
        ->once()
        ->withArgs(function (array $messages) use (&$capturedMessages): bool {
            $capturedMessages = $messages;

            return true;
        })
        ->andReturn([
            'content' => redactorValidContent(),
            'model' => 'gpt-4o-mini',
            'usage' => [],
            'raw' => [],
        ]);
    app()->instance(OpenAiClient::class, $mock);

    $result = app(LaboratoryPreparationOpenAiRedactor::class)->redact(redactorAutoDecision());

    $requestText = json_encode($capturedMessages, JSON_UNESCAPED_UNICODE);
    $storedPayload = json_encode($result->aiExecution->request_payload_redacted, JSON_UNESCAPED_UNICODE);

    expect($requestText)->not->toContain('source_instructions')
        ->and($requestText)->not->toContain('Paciente Juan Pérez')
        ->and($storedPayload)->not->toContain('source_instructions')
        ->and($storedPayload)->not->toContain('Paciente Juan Pérez');
});

it('construye payload AUTO sin usar toSchemaPayload completo ni originales', function () {
    $payload = app(LaboratoryPreparationRedactionPayloadBuilder::class)->build(redactorAutoDecision());
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

    expect($payload)->toHaveKeys(['status', 'order_id', 'rules_version', 'rules_applied', 'requirements', 'operational_requirements'])
        ->and($payload)->not->toHaveKey('original_instructions')
        ->and($payload['requirements'])->not->toBeEmpty()
        ->and($encoded)->not->toContain('Paciente Juan Pérez')
        ->and($encoded)->toContain('Ayuno de 8 horas')
        ->and($encoded)->toContain('metformina');
});
