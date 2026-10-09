<?php

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSource;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRecognitionStatus;

beforeEach(function () {
    $this->parser = app(LaboratoryInstructionParser::class);
});

function preparationParserRegressionCases(): array
{
    return require __DIR__.'/../../Support/LaboratoryPreparation/parser_escenarios_regresion_v3.php';
}

function preparationParserOfficialCases(): array
{
    return require __DIR__.'/../../Support/LaboratoryPreparation/casos_prueba_oficiales_v3.php';
}

function assertParserCase(LaboratoryInstructionParser $parser, array $case): void
{
    $study = $parser->parseStudy(
        $case['study_id'],
        $case['study_name'],
        $case['indications'],
    );

    expect($study->sourceText)->toBe($case['indications'])
        ->and($study->isEmptySource)->toBe($case['indications'] === '');

    foreach ($case['preserved_substrings'] as $substring) {
        expect($study->sourceText)->toContain($substring);

        $spanCoverage = preg_replace('/\s+/u', ' ', collect($study->requirements)->pluck('sourceSpan')->implode(' ')) ?? '';
        expect($spanCoverage)->toContain($substring);
    }

    foreach ($case['expected_categories'] as $category) {
        expect(collect($study->requirements)->pluck('category')->all())->toContain($category);
    }

    foreach ($case['expected_requirement_types'] as $type) {
        expect(collect($study->requirements)->pluck('requirementType')->all())->toContain($type);
    }

    if (! $case['allow_unrecognized']) {
        expect($study->hasUnrecognizedContent)->toBeFalse();
    }

    foreach ($study->requirements as $requirement) {
        expect($requirement->studyId)->toBe($case['study_id'])
            ->and($requirement->studyName)->toBe($case['study_name'])
            ->and($requirement->sourceText)->toBe($case['indications']);

        if ($requirement->sourceSpanStart >= 0 && $requirement->sourceSpanEnd > $requirement->sourceSpanStart) {
            expect(mb_substr(
                $requirement->sourceText,
                $requirement->sourceSpanStart,
                $requirement->sourceSpanEnd - $requirement->sourceSpanStart,
            ))->toBe($requirement->sourceSpan);
        }
    }
}

it('expone categorías reconocidas sin consolidar estudios', function () {
    $items = [
        ['id' => 1, 'name' => 'Estudio A', 'gda_id' => '2001', 'indications' => 'Ayuno de 8 horas.'],
        ['id' => 2, 'name' => 'Estudio B', 'gda_id' => '2002', 'indications' => 'Ayuno de 12 horas.'],
    ];

    $result = $this->parser->parseItems($items);

    expect($result->studies)->toHaveCount(2)
        ->and($result->studies[0]->requirements[0]->normalizedValue['hours'] ?? $result->studies[0]->requirements[0]->normalizedValue['min_hours'] ?? null)->not->toBeNull()
        ->and($result->studies[1]->requirements[0]->normalizedValue['hours'] ?? null)->toBe(12);
});

it('conserva texto original y trazabilidad por span', function () {
    $text = 'Desechar el primer chorro y recolectar el chorro medio.';
    $study = $this->parser->parseStudy('3001', 'Urocultivo', $text);

    expect($study->sourceText)->toBe($text)
        ->and(collect($study->requirements)->every(fn ($requirement) => str_contains($study->sourceText, $requirement->sourceSpan)))->toBeTrue()
        ->and(collect($study->requirements)->pluck('sourceSpan')->implode(' '))->toContain('primer chorro');
});

it('marca instrucciones vacías sin inventar requisitos', function () {
    $study = $this->parser->parseStudy('4001', 'Paquete', '');

    expect($study->isEmptySource)->toBeTrue()
        ->and($study->requirements)->toBeEmpty()
        ->and($study->hasUnrecognizedContent)->toBeFalse();
});

it('detecta ayuno con intervalo cerrado 8-10 sin decidir compatibilidad', function () {
    $study = $this->parser->parseStudy('5001', 'Química', 'Ayuno de 8 a 10 horas.');

    $fasting = collect($study->requirements)->first(fn ($requirement) => $requirement->category === LaboratoryInstructionCategory::FASTING);

    expect($fasting)->not->toBeNull()
        ->and($fasting->normalizedValue)->toMatchArray([
            'kind' => 'closed_range',
            'min_hours' => 8,
            'max_hours' => 10,
        ])
        ->and($fasting->recognitionStatus)->toBe(LaboratoryInstructionRecognitionStatus::RECOGNIZED);
});

it('preserva negaciones explícitas de ayuno', function () {
    $study = $this->parser->parseStudy('5002', 'Glucosa', 'No requiere ayuno.');

    $fasting = collect($study->requirements)->first(fn ($requirement) => $requirement->requirementType === 'no_fasting');

    expect($fasting)->not->toBeNull()
        ->and($fasting->normalizedValue['kind'])->toBe('none');
});

it('expone unidades desconocidas sin convertirlas en instrucciones válidas', function () {
    $study = $this->parser->parseStudy('5003', 'Estudio', 'Tomar 2 chilitos antes del estudio.');

    expect($study->hasUnrecognizedContent)->toBeTrue();

    $unknownUnit = collect($study->requirements)->first(
        fn ($requirement) => $requirement->requirementType === 'unknown_unit',
    );

    expect($unknownUnit)->not->toBeNull()
        ->and($unknownUnit->recognitionStatus)->toBe(LaboratoryInstructionRecognitionStatus::AMBIGUOUS)
        ->and($unknownUnit->sourceSpan)->toContain('chilitos');
});

it('integra con LaboratoryPreparationSource sin usar feature_list', function () {
    $input = [
        'items' => [
            [
                'id' => 77,
                'name' => 'Química sanguínea',
                'gda_id' => 'GDA-77',
                'indications' => 'Ayuno de 8 horas.',
                'feature_list' => ['EXAMEN QUE NO DEBE PARSEARSE'],
            ],
        ],
    ];

    $parsed = $this->parser->parseItems($input['items']);
    $hash = app(LaboratoryPreparationSource::class)->hash($input);

    expect($parsed->studies)->toHaveCount(1)
        ->and($parsed->studies[0]->requirements)->not->toBeEmpty()
        ->and($hash)->toHaveLength(64)
        ->and(collect($parsed->studies[0]->requirements)->pluck('sourceSpan')->implode(' '))->not->toContain('EXAMEN QUE NO DEBE PARSEARSE');
});

it('ejecuta escenarios de regresión internos del parser', function () {
    foreach (preparationParserRegressionCases() as $case) {
        assertParserCase($this->parser, $case);
    }
})->group('regresion_parser');

it('delega casos oficiales al fixture JSON generado desde Excel', function () {
    expect(preparationParserOfficialCases())->not->toBeEmpty();
})->group('casos_oficiales');

it('marca entradas incompletas de ayuno', function () {
    $study = $this->parser->parseStudy('6001', 'Incompleto', 'Ayuno de horas.');

    expect(collect($study->requirements)->contains(
        fn ($requirement) => $requirement->requirementType === 'fasting_incomplete'
            && $requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::INCOMPLETE,
    ))->toBeTrue();
});

it('marca fragmentos no reconocidos explícitamente en lugar de descartarlos', function () {
    $study = $this->parser->parseStudy('6002', 'Parcial', 'Recolectar orina y seguir protocolo interno XYZ-999 no documentado.');

    expect(collect($study->requirements)->contains(
        fn ($requirement) => $requirement->requirementType === 'unrecognized_fragment',
    ))->toBeTrue();
});
