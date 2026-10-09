<?php

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPatientContext;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseResult;

beforeEach(function () {
    $this->parser = app(LaboratoryInstructionParser::class);
    $this->engine = app(LaboratoryPreparationRuleEngine::class);
});

function ruleEngineAuditOfficialCases(): array
{
    $jsonPath = __DIR__.'/../../Support/LaboratoryPreparation/casos_prueba_oficiales_v3.json';

    return json_decode((string) file_get_contents($jsonPath), true);
}

function ruleEngineAuditOfficialCasesDataset(): array
{
    return collect(ruleEngineAuditOfficialCases())
        ->mapWithKeys(fn (array $case) => [$case['case_id'] => [$case]])
        ->all();
}

function ruleEngineAuditParseOfficialCase(LaboratoryInstructionParser $parser, array $case): LaboratoryInstructionParseResult
{
    $items = array_map(
        fn (array $study) => [
            'id' => $study['study_id'],
            'name' => $study['study_name'],
            'gda_id' => $study['study_id'],
            'indications' => $study['indications'],
        ],
        $case['studies'],
    );

    return $parser->parseItems($items);
}

function ruleEngineAuditEvaluateOfficialCase(
    LaboratoryInstructionParser $parser,
    LaboratoryPreparationRuleEngine $engine,
    array $case,
): LaboratoryPreparationDecision {
    return $engine->evaluate(
        orderId: $case['case_id'],
        parseResult: ruleEngineAuditParseOfficialCase($parser, $case),
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($case['patient_context']),
        restrictToPhase3aCategories: false,
    );
}

function ruleEngineAuditConsolidatedText(LaboratoryPreparationDecision $decision): string
{
    return (string) json_encode($decision->consolidatedRequirements, JSON_UNESCAPED_UNICODE);
}

it('mantiene el contrato schema v2 y las instrucciones originales literales en los 40 casos oficiales', function (array $case) {
    $decision = ruleEngineAuditEvaluateOfficialCase($this->parser, $this->engine, $case);
    $payload = $decision->toSchemaPayload();

    $expectedStatus = $case['expected_route'] === 'AUTO'
        ? LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED
        : LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL;

    expect($payload)
        ->toHaveKeys([
            'status',
            'order_id',
            'consolidated_requirements',
            'operational_requirements',
            'original_instructions',
            'fallback_reason',
            'needs_provider_review',
            'trace',
        ])
        ->and($payload['trace'])->toHaveKeys(['rules_version', 'rules_applied', 'fallback_category'])
        ->and($payload['status'])->toBe($expectedStatus)
        ->and($payload['order_id'])->toBe($case['case_id'])
        ->and($payload['trace']['rules_version'])->toBe(LaboratoryPreparationRuleEngine::RULES_VERSION)
        ->and($payload['original_instructions'])->toHaveCount(count($case['studies']));

    foreach ($case['studies'] as $index => $study) {
        expect($payload['original_instructions'][$index])
            ->toMatchArray([
                'study_id' => $study['study_id'],
                'study_name' => $study['study_name'],
                'source_instructions' => $study['indications'],
            ]);
    }

    if ($expectedStatus === LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED) {
        expect($payload['consolidated_requirements'])->toBeArray()
            ->and($payload['fallback_reason'])->toBeNull()
            ->and($payload['needs_provider_review'])->toBeFalse();
    } else {
        expect($payload['consolidated_requirements'])->toBeNull()
            ->and($payload['fallback_reason'])->toBeString()->not->toBe('')
            ->and($payload['needs_provider_review'])->toBeTrue();
    }
})->with(fn () => ruleEngineAuditOfficialCasesDataset());

it('conserva completos los FALLBACK oficiales sin consolidado parcial', function (string $caseId, string $reason, string $category) {
    $case = collect(ruleEngineAuditOfficialCases())->firstWhere('case_id', $caseId);
    $decision = ruleEngineAuditEvaluateOfficialCase($this->parser, $this->engine, $case);

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe($reason)
        ->and($decision->fallbackCategory)->toBe($category)
        ->and($decision->needsProviderReview)->toBeTrue()
        ->and($decision->consolidatedRequirements)->toBeNull();

    foreach ($case['studies'] as $index => $study) {
        expect($decision->originalInstructions[$index]['source_instructions'])->toBe($study['indications']);
    }
})->with([
    'QA17' => ['QA17', 'ambiguous_stool_sample_schedule', LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY],
    'QA26' => ['QA26', 'incompatible_fasting_interval', LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT],
    'QA29' => ['QA29', 'incompatible_fasting_interval', LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT],
    'QA31' => ['QA31', 'incompatible_medication_instructions', LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT],
    'QA33' => ['QA33', 'uninterpreted_instruction_fragment', LaboratoryPreparationDecision::FALLBACK_SOURCE_QUALITY],
    'QA34' => ['QA34', 'uninterpreted_instruction_fragment', LaboratoryPreparationDecision::FALLBACK_SOURCE_QUALITY],
    'QA39' => ['QA39', 'hydration_bladder_conflicts_with_urine_collection', LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY],
    'QA40' => ['QA40', 'ambiguous_gynecological_sample_with_urine_order', LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY],
]);

it('preserva texto sensible R15-R18 en AUTO sin convertir dieta o sustancias en ayuno general', function (string $caseId, array $rules, array $expectedFragments) {
    $case = collect(ruleEngineAuditOfficialCases())->firstWhere('case_id', $caseId);
    $decision = ruleEngineAuditEvaluateOfficialCase($this->parser, $this->engine, $case);
    $consolidatedText = ruleEngineAuditConsolidatedText($decision);

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED);

    foreach ($rules as $rule) {
        expect($decision->rulesApplied)->toContain($rule);
    }

    foreach ($expectedFragments as $fragment) {
        expect($consolidatedText)->toContain($fragment);
    }
})->with([
    'QA18' => [
        'QA18',
        ['R15', 'R16', 'R18'],
        [
            'Sin aplicación de medicamentos, cremas, pomadas vía tópica.',
            'Suspender ingesta de bebidas alcohólicas, drogas y tabaco 3 días antes de su estudio.',
            'No haber orinado mínimo 1h',
            'antes de la recolección de la muestra.',
            'frasco de plástico estéril',
            'lunes a sabado',
        ],
    ],
    'QA23' => [
        'QA23',
        ['R15'],
        ['Presentarse con axilas depiladas, sin desodorante, crema, ni perfume.'],
    ],
    'QA32' => [
        'QA32',
        ['R15'],
        ['Presentarse con axilas depiladas, sin desodorante, crema, ni perfume.'],
    ],
    'QA37' => [
        'QA37',
        ['R16'],
        ['Solo se realiza los días lunes', 'sucursales Olab Anzures, Azteca Santa Maria la Rivera'],
    ],
    'QA38' => [
        'QA38',
        ['R15', 'R16'],
        [
            'Dieta blanda tres dias previos a su estudio.',
            'aplicacion de dos enemas evacuantes',
            'una noche anterior al estudio y 2 horas previos al estudio',
        ],
    ],
]);

it('no emite ausencia genérica de preparación cuando otro estudio sí exige preparación real', function () {
    $case = collect(ruleEngineAuditOfficialCases())->firstWhere('case_id', 'QA36');
    $decision = ruleEngineAuditEvaluateOfficialCase($this->parser, $this->engine, $case);

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->rulesApplied)->toContain('R17', 'R01')
        ->and($decision->consolidatedRequirements)->toHaveKey('fasting')
        ->and($decision->consolidatedRequirements)->not->toHaveKey('no_preparation');
});

it('mantiene bloques R15 independientes cuando dos estudios comparten la misma restricción', function () {
    $case = collect(ruleEngineAuditOfficialCases())->firstWhere('case_id', 'QA23');
    $decision = ruleEngineAuditEvaluateOfficialCase($this->parser, $this->engine, $case);

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->consolidatedRequirements['diet_substance_exercise']['blocks'])->toHaveCount(2)
        ->and($decision->consolidatedRequirements['diet_substance_exercise']['blocks'][0]['study_ids'])->toBe(['QA23-01'])
        ->and($decision->consolidatedRequirements['diet_substance_exercise']['blocks'][1]['study_ids'])->toBe(['QA23-02']);
});

it('es determinístico para evaluaciones repetidas y conserva la decisión ante cambio de orden compatible', function () {
    $cases = collect(ruleEngineAuditOfficialCases())->keyBy('case_id');
    $qa32 = $cases['QA32'];

    $first = ruleEngineAuditEvaluateOfficialCase($this->parser, $this->engine, $qa32);
    $second = ruleEngineAuditEvaluateOfficialCase($this->parser, $this->engine, $qa32);

    expect($second->toArray())->toBe($first->toArray());

    $reversed = $qa32;
    $reversed['studies'] = array_reverse($qa32['studies']);

    $reversedDecision = ruleEngineAuditEvaluateOfficialCase($this->parser, $this->engine, $reversed);

    expect($reversedDecision->status)->toBe($first->status)
        ->and($reversedDecision->rulesApplied)->toBe($first->rulesApplied)
        ->and($reversedDecision->consolidatedRequirements['fasting']['minimum_hours'])->toBe(8)
        ->and($reversedDecision->consolidatedRequirements['fasting']['maximum_hours'])->toBe(10);
});

it('bloquea fragmentos relevantes desconocidos antes de AUTO', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => 'unknown-1',
            'name' => 'ESTUDIO CON INDICACION NO DOCUMENTADA',
            'gda_id' => 'unknown-1',
            'indications' => 'Ayuno de 8 horas. Tomar suplemento herbal xyz-999 durante 2 semanas.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'ORD-UNKNOWN-AUDIT',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('uninterpreted_instruction_fragment')
        ->and($decision->fallbackCategory)->toBe(LaboratoryPreparationDecision::FALLBACK_SOURCE_QUALITY)
        ->and($decision->consolidatedRequirements)->toBeNull();
});
