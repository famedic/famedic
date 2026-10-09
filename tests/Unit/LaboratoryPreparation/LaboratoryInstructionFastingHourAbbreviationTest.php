<?php

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPatientContext;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRecognitionStatus;

beforeEach(function () {
    $this->parser = app(LaboratoryInstructionParser::class);
    $this->engine = app(LaboratoryPreparationRuleEngine::class);
});

function gda520236Indications(): string
{
    return "-Presentarse con ayuno de 8 -14 h. \n-Menores de 3 años con ayuno de al menos 4 h.";
}

it('reconoce rangos de ayuno GDA con abreviatura h y h.', function (string $indications, int $min, int $max) {
    $study = $this->parser->parseStudy('520236', 'ACIDO METILMALONICO', $indications);

    $fasting = collect($study->requirements)->first(
        fn ($requirement) => $requirement->requirementType === 'fasting_range_hours',
    );

    expect($study->hasUnrecognizedContent)->toBeFalse()
        ->and($fasting)->not->toBeNull()
        ->and($fasting->normalizedValue)->toMatchArray([
            'kind' => 'closed_range',
            'min_hours' => $min,
            'max_hours' => $max,
        ]);
})->with([
    'h con punto' => ['Presentarse con ayuno de 8 -14 h.', 8, 14],
    'h sin punto' => ['Presentarse con ayuno de 8 -14 h', 8, 14],
    'horas' => ['Presentarse con ayuno de 8 -14 horas.', 8, 14],
    'hrs' => ['Presentarse con ayuno de 8 -14 hrs.', 8, 14],
]);

it('reconoce ayuno mínimo pediátrico con h.', function () {
    $study = $this->parser->parseStudy(
        '520236',
        'ACIDO METILMALONICO',
        'Menores de 3 años con ayuno de al menos 4 h.',
    );

    expect(collect($study->requirements)->pluck('requirementType')->all())->toContain('age_years_max')
        ->and(collect($study->requirements)->pluck('requirementType')->all())->toContain('fasting_minimum_hours')
        ->and($study->hasUnrecognizedContent)->toBeFalse();
});

it('parsea snapshot GDA 520236 y 520100 sin fragmentos no reconocidos', function (string $gdaId, string $name) {
    $study = $this->parser->parseStudy($gdaId, $name, gda520236Indications());

    expect($study->hasUnrecognizedContent)->toBeFalse()
        ->and(collect($study->requirements)->pluck('requirementType')->all())->toContain('fasting_range_hours')
        ->and(collect($study->requirements)->pluck('requirementType')->all())->toContain('age_years_max')
        ->and(collect($study->requirements)->pluck('requirementType')->all())->toContain('fasting_minimum_hours');
})->with([
    '520236' => ['520236', 'ACIDO METILMALONICO'],
    '520100' => ['520100', 'ACIDO FOLICO (FOLATOS)'],
]);

it('no interpreta h como duración de ayuno fuera de contexto clínico de ayuno', function (string $indications) {
    $study = $this->parser->parseStudy('neg-1', 'Estudio', $indications);

    expect(collect($study->requirements)->contains(
        fn ($requirement) => $requirement->category === LaboratoryInstructionCategory::FASTING
            && $requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::RECOGNIZED,
    ))->toBeFalse();
})->with([
    'intervalo sin ayuno' => ['Esperar en recepción de 8 -14 h antes de pasar.'],
    'medicamento' => ['Tomar medicamento cada 8 h por 14 días.'],
]);

it('sigue marcando FALLBACK cuando hay ayuno reconocido y texto clínico adicional desconocido', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => 1,
            'name' => 'Química',
            'gda_id' => '1',
            'indications' => 'Presentarse con ayuno de 8 -14 h. Suspender medicamento XYZ-999 no documentado antes del estudio.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'REG-H-SEC',
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

it('consolida orden equivalente a compra 2420 para adulto (AUTO 8–14 h)', function () {
    $parse = $this->parser->parseItems([
        ['id' => 5888, 'name' => 'ACIDO METILMALONICO', 'gda_id' => '520236', 'indications' => gda520236Indications()],
        ['id' => 5889, 'name' => 'ACIDO HIALURONICO', 'gda_id' => '590302', 'indications' => 'Presentarse con ayuno de 8 -14 horas.'],
        ['id' => 5890, 'name' => 'ACIDO FOLICO (FOLATOS)', 'gda_id' => '520100', 'indications' => gda520236Indications()],
        ['id' => 5891, 'name' => 'AC.POR FIJACION DE COMPLEMENTO PARA COCCIDIOIDES', 'gda_id' => '520253', 'indications' => 'Presentarse con ayuno de 8 -14 horas.'],
        ['id' => 5892, 'name' => 'AC. TRYPANOSOMA CRUZI IgG (CHAGAS)', 'gda_id' => '520331', 'indications' => 'Presentarse con ayuno de 8 -14 horas.'],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'laboratory_purchase_2420',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->rulesApplied)->toContain('R01')
        ->and($decision->rulesApplied)->toContain('R02')
        ->and($decision->consolidatedRequirements['fasting']['minimum_hours'])->toBe(8)
        ->and($decision->consolidatedRequirements['fasting']['maximum_hours'])->toBe(14)
        ->and($decision->fallbackReason)->toBeNull();
});

it('aplica R02 con abreviatura h en contexto Menor de 3 años', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => 1,
            'name' => 'ACIDO METILMALONICO',
            'gda_id' => '520236',
            'indications' => gda520236Indications(),
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'QA05-GDA-H',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Menor de 3 años'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->rulesApplied)->toContain('R02')
        ->and($decision->consolidatedRequirements['fasting']['minimum_hours'])->toBe(4)
        ->and($decision->consolidatedRequirements['age_resolution']['age_source'])->toBe('official_label_pediatric');
});
