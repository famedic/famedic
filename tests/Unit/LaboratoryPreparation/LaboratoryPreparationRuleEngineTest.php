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

function ruleEngineOfficialCases(): array
{
    $jsonPath = __DIR__.'/../../Support/LaboratoryPreparation/casos_prueba_oficiales_v3.json';

    return json_decode((string) file_get_contents($jsonPath), true);
}

function ruleEngineOfficialCasesDataset(): array
{
    return collect(ruleEngineOfficialCases())
        ->mapWithKeys(fn (array $case) => [$case['case_id'] => [$case]])
        ->all();
}

function parseOfficialCaseStudies(LaboratoryInstructionParser $parser, array $case): LaboratoryInstructionParseResult
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

it('interseca ayuno 8 h con 10–12 h en AUTO (R01)', function () {
    $parse = $this->parser->parseItems([
        ['id' => 1, 'name' => 'HOMA', 'gda_id' => '1', 'indications' => 'Ayuno de 8 horas'],
        ['id' => 2, 'name' => 'QUIMICA', 'gda_id' => '2', 'indications' => 'Ayuno de 10 a 12 horas'],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'ORD-R01-01',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->rulesApplied)->toContain('R01')
        ->and($decision->consolidatedRequirements['fasting']['minimum_hours'])->toBe(10)
        ->and($decision->consolidatedRequirements['fasting']['maximum_hours'])->toBe(12)
        ->and($decision->needsProviderReview)->toBeFalse()
        ->and($decision->toSchemaPayload()['status'])->toBe('AUTO_CONSOLIDATED');
});

it('interseca 8–10 h con 10–12 h en un punto de 10 h (R01)', function () {
    $parse = $this->parser->parseItems([
        ['id' => 1, 'name' => 'ECO', 'gda_id' => '1', 'indications' => 'Ayuno de 8 a 10 horas'],
        ['id' => 2, 'name' => 'QUIMICA', 'gda_id' => '2', 'indications' => 'Ayuno de 10 a 12 horas'],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'ORD-R01-02',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->consolidatedRequirements['fasting']['exact_hours'])->toBe(10);
});

it('marca FALLBACK clínico cuando 6–8 h choca con 10–12 h (R01)', function () {
    $parse = $this->parser->parseItems([
        ['id' => 1, 'name' => 'ECO', 'gda_id' => '1', 'indications' => 'En ayuno a solidos de 6 a 8 horas.'],
        ['id' => 2, 'name' => 'QUIMICA', 'gda_id' => '2', 'indications' => 'Ayuno de 10 a 12 horas'],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'ORD-R01-03',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('incompatible_fasting_interval')
        ->and($decision->fallbackCategory)->toBe(LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT)
        ->and($decision->needsProviderReview)->toBeTrue()
        ->and($decision->consolidatedRequirements)->toBeNull();
});

it('aplica condición pediátrica sin inventar edad (R02, QA05)', function () {
    $case = collect(ruleEngineOfficialCases())->firstWhere('case_id', 'QA05');
    $parse = parseOfficialCaseStudies($this->parser, $case);

    $decision = $this->engine->evaluate(
        orderId: 'QA05',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($case['patient_context']),
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->rulesApplied)->toContain('R02')
        ->and($decision->consolidatedRequirements['fasting']['minimum_hours'])->toBe(4)
        ->and($decision->consolidatedRequirements['age_resolution']['age_source'])->toBe('official_label_pediatric');
});

it('consolida QA04 adulto con intersección 10–12 h (R01,R02)', function () {
    $case = collect(ruleEngineOfficialCases())->firstWhere('case_id', 'QA04');
    $parse = parseOfficialCaseStudies($this->parser, $case);

    $decision = $this->engine->evaluate(
        orderId: 'QA04',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($case['patient_context']),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->rulesApplied)->toContain('R01')
        ->and($decision->consolidatedRequirements['fasting']['minimum_hours'])->toBe(10)
        ->and($decision->consolidatedRequirements['fasting']['maximum_hours'])->toBe(12);
});

it('conserva hidratación y vejiga sin secuenciar orina ambigua (R03)', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => 'eco',
            'name' => 'ECO RENAL',
            'gda_id' => 'eco',
            'indications' => 'Tomar 1.5 l de agua. Vejiga llena.',
        ],
        [
            'id' => 'ego',
            'name' => 'EXAMEN GENERAL DE ORINA',
            'gda_id' => 'ego',
            'indications' => 'Examen general de orina.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'ORD-R03-01',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->rulesApplied)->toContain('R03')
        ->and($decision->fallbackReason)->toBe('hydration_bladder_conflicts_with_urine_collection');
});

it('aplica FALLBACK oficial QA26 por ayuno incompatible (R01,R03)', function () {
    $case = collect(ruleEngineOfficialCases())->firstWhere('case_id', 'QA26');
    $parse = parseOfficialCaseStudies($this->parser, $case);

    $decision = $this->engine->evaluate(
        orderId: 'QA26',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($case['patient_context']),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('incompatible_fasting_interval')
        ->and($decision->originalInstructions)->toHaveCount(2);
});

it('evalúa casos oficiales R01 puros en AUTO (QA01–QA03)', function (string $caseId) {
    $case = collect(ruleEngineOfficialCases())->firstWhere('case_id', $caseId);
    $parse = parseOfficialCaseStudies($this->parser, $case);

    $decision = $this->engine->evaluate(
        orderId: $caseId,
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($case['patient_context']),
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->rulesApplied)->toContain('R01');
})->with(['QA01', 'QA02', 'QA03']);

it('evalúa el estado oficial AUTO/FALLBACK de los 40 casos R01–R18', function (array $case) {
    $parse = parseOfficialCaseStudies($this->parser, $case);

    $decision = $this->engine->evaluate(
        orderId: $case['case_id'],
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($case['patient_context']),
        restrictToPhase3aCategories: false,
    );

    $expectedStatus = $case['expected_route'] === 'AUTO'
        ? LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED
        : LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL;

    expect($decision->status)->toBe($expectedStatus)
        ->and($decision->originalInstructions)->toHaveCount(count($case['studies']));

    if ($expectedStatus === LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED) {
        expect($decision->consolidatedRequirements)->not->toBeNull()
            ->and($decision->fallbackReason)->toBeNull();
    } else {
        expect($decision->consolidatedRequirements)->toBeNull()
            ->and($decision->needsProviderReview)->toBeTrue();
    }
})->with(fn () => ruleEngineOfficialCasesDataset());

it('preserva bloques R15–R18 en los casos oficiales prioritarios de Fase 3D', function () {
    $cases = collect(ruleEngineOfficialCases())->keyBy('case_id');

    $qa32 = $this->engine->evaluate(
        orderId: 'QA32',
        parseResult: parseOfficialCaseStudies($this->parser, $cases['QA32']),
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($cases['QA32']['patient_context']),
        restrictToPhase3aCategories: false,
    );

    expect($qa32->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($qa32->rulesApplied)->toContain('R15')
        ->and(collect($qa32->consolidatedRequirements['diet_substance_exercise']['blocks'][0]['sources'])->pluck('source_span')->implode(' '))
        ->toContain('Presentarse con axilas depiladas, sin desodorante, crema, ni perfume.');

    $qa37 = $this->engine->evaluate(
        orderId: 'QA37',
        parseResult: parseOfficialCaseStudies($this->parser, $cases['QA37']),
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($cases['QA37']['patient_context']),
        restrictToPhase3aCategories: false,
    );

    expect($qa37->rulesApplied)->toContain('R16')
        ->and(collect($qa37->consolidatedRequirements['sample_timing']['blocks'][0]['sources'])->pluck('source_span')->implode(' '))
        ->toContain('Solo se realiza los días lunes');

    $qa35 = $this->engine->evaluate(
        orderId: 'QA35',
        parseResult: parseOfficialCaseStudies($this->parser, $cases['QA35']),
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($cases['QA35']['patient_context']),
        restrictToPhase3aCategories: false,
    );

    expect($qa35->rulesApplied)->toContain('R17')
        ->and($qa35->consolidatedRequirements['no_preparation']['message'])
        ->toBe('No requiere preparación especial ni ayuno.');

    $qa08 = $this->engine->evaluate(
        orderId: 'QA08',
        parseResult: parseOfficialCaseStudies($this->parser, $cases['QA08']),
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($cases['QA08']['patient_context']),
        restrictToPhase3aCategories: false,
    );

    expect($qa08->rulesApplied)->toContain('R18')
        ->and(collect($qa08->consolidatedRequirements['container_preservative']['blocks'][0]['sources'])->pluck('source_span')->implode(' '))
        ->toContain('frasco de laboratorio estéril');
});

it('prioriza conflicto de medicamentos documentado en QA31 antes de hidratación incidental', function () {
    $case = collect(ruleEngineOfficialCases())->firstWhere('case_id', 'QA31');

    $decision = $this->engine->evaluate(
        orderId: 'QA31',
        parseResult: parseOfficialCaseStudies($this->parser, $case),
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel($case['patient_context']),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('incompatible_medication_instructions')
        ->and($decision->rulesApplied)->toContain('R09', 'R10');
});

it('serializa decisiones AUTO y FALLBACK compatibles con schema v2', function () {
    $auto = LaboratoryPreparationDecision::autoConsolidated(
        orderId: 'ORD-SCHEMA',
        rulesVersion: LaboratoryPreparationRuleEngine::RULES_VERSION,
        rulesApplied: ['R01'],
        consolidatedRequirements: ['fasting' => ['minimum_hours' => 8]],
        originalInstructions: [
            ['study_id' => '1', 'study_name' => 'HOMA', 'source_instructions' => 'Ayuno de 8 horas'],
        ],
    );

    expect($auto->toSchemaPayload()['trace']['rules_applied'])->toBe(['R01']);

    $parse = $this->parser->parseItems([
        ['id' => 1, 'name' => 'A', 'gda_id' => '1', 'indications' => 'Ayuno de 6 a 8 horas'],
        ['id' => 2, 'name' => 'B', 'gda_id' => '2', 'indications' => 'Ayuno de 10 a 12 horas'],
    ]);

    $fallback = $this->engine->evaluate(
        orderId: 'ORD-FB',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($fallback->toSchemaPayload()['consolidated_requirements'])->toBeNull()
        ->and($fallback->toSchemaPayload()['needs_provider_review'])->toBeTrue();
});
