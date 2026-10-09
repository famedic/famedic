<?php

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPatientContext;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;

beforeEach(function () {
    $this->parser = app(LaboratoryInstructionParser::class);
    $this->engine = app(LaboratoryPreparationRuleEngine::class);
});

it('no consolida AUTO cuando hay ayuno reconocido y medicamento no estructurado', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => '1',
            'name' => 'Química',
            'gda_id' => '1',
            'indications' => 'Ayuno de 8 horas. Suspender medicamento XYZ-999 no documentado antes del estudio.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'SEC-MED-01',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBeIn([
            'uninterpreted_instruction_fragment',
            'medication_not_fully_structured',
            'ambiguous_instruction_fragment',
        ]);
});

it('no consolida AUTO cuando hay ayuno y restricción temporal no clasificada', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => '1',
            'name' => 'Estudio',
            'gda_id' => '1',
            'indications' => 'Ayuno de 8 horas. Repetir muestra cada 72 horas durante 2 semanas.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'SEC-TIME-01',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('uninterpreted_instruction_fragment');
});

it('no consolida AUTO cuando hay preparación de muestra y texto adicional relevante', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => '1',
            'name' => 'EGO',
            'gda_id' => '1',
            'indications' => 'Desechar el primer chorro y recolectar el chorro medio. Guardar la muestra en refrigeración hasta entregar.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'SEC-SAMPLE-01',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('uninterpreted_instruction_fragment');
});

it('tolera conectores lingüísticos aislados sin forzar fallback', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => '1',
            'name' => 'HOMA',
            'gda_id' => '1',
            'indications' => 'Ayuno de 8 horas.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'SEC-CON-01',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED);
});

it('no consolida AUTO ante instrucción desconocida con negación clínica', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => '1',
            'name' => 'Perfil',
            'gda_id' => '1',
            'indications' => 'Ayuno de 8 horas. No ingerir suplemento herbal no listado en protocolo interno.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'SEC-NEG-01',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL);
});

it('no consolida AUTO cuando una oración queda parcialmente reconocida con información restante', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => '1',
            'name' => 'AngioTAC',
            'gda_id' => '1',
            'indications' => 'Presentarse con ayuno de 8 horas. Creatinina reciente y además traer autorización especial del cardiólogo.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'SEC-PARTIAL-01',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('uninterpreted_instruction_fragment');
});

it('marca fallback cuando hay requisito reconocido fuera del alcance de reglas implementadas en modo 3A', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => '1',
            'name' => 'AngioTAC',
            'gda_id' => '1',
            'indications' => 'Si el paciente toma metformina, suspenderlo 24 horas antes y 24 horas despues del estudio.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'SEC-SCOPE-3A',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: true,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('rules_outside_phase_3a_scope');
});

it('marca fallback clínico por metformina incompatible entre estudios (R09)', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => '1',
            'name' => 'AngioTAC',
            'gda_id' => '1',
            'indications' => 'Si el paciente toma metformina, es necesario suspenderlo 24 horas antes y 24 horas despues del estudio.',
        ],
        [
            'id' => '2',
            'name' => 'Urografía',
            'gda_id' => '2',
            'indications' => 'Si el paciente es diabetico y toma metformina, debe consultar con su medico tratante quien a criterio medico, le indicará suspender este medicamento 48 horas antes del examen.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'SEC-MET-CONFLICT',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL)
        ->and($decision->fallbackReason)->toBe('incompatible_medication_instructions')
        ->and($decision->rulesApplied)->toContain('R09');
});
