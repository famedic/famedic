<?php

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\LaboratoryPreparationDecision;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPatientContext;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;

beforeEach(function () {
    $this->parser = app(LaboratoryInstructionParser::class);
    $this->engine = app(LaboratoryPreparationRuleEngine::class);
});

function gda520794IndicationsSnapshot(): string
{
    return "- Se requiere recolectar muestra de orina (10 mL) final del día de la jornada laboral en contenedor de plástico esteril tapa de rosca.\n"
        ."- Desechar el primer chorro de orina y recolectar el chorro medio en el contenedor, llenar por lo menos hasta las tres cuartas partes.\n"
        ."- Cerrar el contenedor, asegurando que la tapa cierre correctamente, para evitar derrame.";
}

it('estructura el snapshot GDA 520794 sin fragmentos no reconocidos', function () {
    $study = $this->parser->parseStudy(
        '520794',
        'ACIDO MANDELICO MAS ACIDO FENILGLIOXILICO EN ORINA',
        gda520794IndicationsSnapshot(),
    );

    $types = collect($study->requirements)->pluck('requirementType')->all();

    expect($study->hasUnrecognizedContent)->toBeFalse()
        ->and($types)->toContain('urine_collect_sample_required')
        ->and($types)->toContain('urine_sample_volume_ml')
        ->and($types)->toContain('urine_collection_workday_end')
        ->and($types)->toContain('urine_plastic_sterile_screw_cap_container')
        ->and($types)->toContain('urine_discard_midstream_fill_combined')
        ->and($types)->toContain('urine_container_seal_no_spill')
        ->and($types)->not->toContain('urine_general');

    $volume = collect($study->requirements)->firstWhere('requirementType', 'urine_sample_volume_ml');
    expect($volume?->normalizedValue)->toMatchArray([
        'kind' => 'sample_volume',
        'amount_ml' => 10.0,
        'unit' => 'mL',
    ]);

    $timing = collect($study->requirements)->firstWhere('requirementType', 'urine_collection_workday_end');
    expect($timing?->category)->toBe(LaboratoryInstructionCategory::SAMPLE_TIMING)
        ->and($timing?->normalizedValue['timing_scope'])->toBe('end_of_workday');

    foreach ($study->requirements as $requirement) {
        expect($requirement->studyId)->toBe('520794')
            ->and($requirement->sourceText)->toBe(gda520794IndicationsSnapshot())
            ->and($requirement->sourceSpan)->not->toBe('');
    }
});

it('no fusiona volumen 10 mL con nivel de tres cuartas partes', function () {
    $study = $this->parser->parseStudy('520794', 'ACIDO MANDELICO EN ORINA', gda520794IndicationsSnapshot());

    $volume = collect($study->requirements)->firstWhere('requirementType', 'urine_sample_volume_ml');
    $combined = collect($study->requirements)->firstWhere('requirementType', 'urine_discard_midstream_fill_combined');

    expect($volume)->not->toBeNull()
        ->and($combined)->not->toBeNull()
        ->and($volume->normalizedValue)->not->toHaveKey('container_fill_ratio')
        ->and($combined->normalizedValue['kind'])->toBe('discard_midstream_fill_three_quarters');
});

it('conserva timing de jornada laboral solo en el estudio 520794', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => 1,
            'name' => 'ACIDO MANDELICO MAS ACIDO FENILGLIOXILICO EN ORINA',
            'gda_id' => '520794',
            'indications' => gda520794IndicationsSnapshot(),
        ],
        [
            'id' => 2,
            'name' => 'EXAMEN GENERAL DE ORINA',
            'gda_id' => 'ego-1',
            'indications' => 'Desechar el primer chorro y recolectar el chorro medio.',
        ],
    ]);

    $workdayStudies = collect($parse->studies)
        ->filter(fn ($study) => collect($study->requirements)->contains(
            fn ($requirement) => $requirement->requirementType === 'urine_collection_workday_end',
        ))
        ->pluck('studyId')
        ->all();

    expect($workdayStudies)->toBe(['520794']);
});

it('consolida orden equivalente a compra 2417 para adulto', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => 5874,
            'name' => 'ACIDO MANDELICO MAS ACIDO FENILGLIOXILICO EN ORINA',
            'gda_id' => '520794',
            'indications' => gda520794IndicationsSnapshot(),
        ],
        [
            'id' => 5875,
            'name' => 'ACIDO METILMALONICO',
            'gda_id' => '520236',
            'indications' => "-Presentarse con ayuno de 8 -14 h. \n-Menores de 3 años con ayuno de al menos 4 h.",
        ],
        [
            'id' => 5876,
            'name' => 'ACIDO HIALURONICO',
            'gda_id' => '590302',
            'indications' => 'Presentarse con ayuno de 8 -14 horas.',
        ],
        [
            'id' => 5877,
            'name' => 'ACIDO FOLICO (FOLATOS)',
            'gda_id' => '520100',
            'indications' => "-Presentarse con ayuno de 8 -14 h. \n-Menores de 3 años con ayuno de al menos 4 h.",
        ],
        [
            'id' => 5878,
            'name' => 'AC.POR FIJACION DE COMPLEMENTO PARA COCCIDIOIDES',
            'gda_id' => '520253',
            'indications' => 'Presentarse con ayuno de 8 -14 horas.',
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: 'laboratory_purchase_2417',
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel('Adulto'),
        restrictToPhase3aCategories: false,
    );

    expect($decision->status)->toBe(LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED)
        ->and($decision->rulesApplied)->toContain('R01')
        ->and($decision->rulesApplied)->toContain('R04')
        ->and($decision->fallbackReason)->toBeNull()
        ->and($decision->consolidatedRequirements['fasting']['minimum_hours'])->toBe(8)
        ->and($decision->consolidatedRequirements['fasting']['maximum_hours'])->toBe(14);
});

it('sigue en FALLBACK cuando el protocolo 520794 incluye texto clínico adicional desconocido', function () {
    $parse = $this->parser->parseItems([
        [
            'id' => 1,
            'name' => 'ACIDO MANDELICO MAS ACIDO FENILGLIOXILICO EN ORINA',
            'gda_id' => '520794',
            'indications' => gda520794IndicationsSnapshot()."\nSuspender medicamento XYZ-999 no documentado antes del estudio.",
        ],
    ]);

    $decision = $this->engine->evaluate(
        orderId: '520794-SEC',
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

it('reconoce recolectar muestra de orina en variantes documentadas sin reclasificar como EGO', function (string $text, string $expectedType) {
    $study = $this->parser->parseStudy('520794', 'ACIDO MANDELICO EN ORINA', $text);

    expect(collect($study->requirements)->pluck('requirementType')->all())->toContain($expectedType)
        ->and(collect($study->requirements)->pluck('requirementType')->all())->not->toContain('urine_general');
})->with([
    'se requiere muestra' => [
        'Se requiere recolectar muestra de orina (10 mL) final del día de la jornada laboral.',
        'urine_collect_sample_required',
    ],
    'recolección de la muestra' => [
        'Acudir por el frasco para la recolección de la muestra de orina.',
        'collect_urine',
    ],
]);
