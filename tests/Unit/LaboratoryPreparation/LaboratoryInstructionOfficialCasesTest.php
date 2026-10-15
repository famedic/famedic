<?php

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionOfficialSourceSplitter;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRecognitionStatus;

beforeEach(function () {
    $this->parser = app(LaboratoryInstructionParser::class);
    $this->splitter = app(LaboratoryInstructionOfficialSourceSplitter::class);
});

function preparationOfficialCasesFromJson(): array
{
    $jsonPath = __DIR__.'/../../Support/LaboratoryPreparation/casos_prueba_oficiales_v3.json';

    expect(is_readable($jsonPath))->toBeTrue('Genera casos_prueba_oficiales_v3.json desde el Excel oficial.');

    $decoded = json_decode((string) file_get_contents($jsonPath), true);

    return is_array($decoded) ? $decoded : [];
}

it('carga exactamente 40 casos oficiales QA01 a QA40 con textos literales del Excel', function () {
    $cases = preparationOfficialCasesFromJson();

    expect($cases)->toHaveCount(40)
        ->and(collect($cases)->pluck('case_id')->all())->toBe(
            collect(range(1, 40))->map(fn (int $n) => sprintf('QA%02d', $n))->all(),
        );

    foreach ($cases as $case) {
        expect($case['source_text'])->not->toBe('')
            ->and($case['expected_route'])->not->toBe('')
            ->and($case['studies'])->not->toBeEmpty();

        foreach ($case['studies'] as $study) {
            expect($case['source_text'])->toContain($study['header_line'] !== '' ? $study['header_line'] : $study['study_name']);
        }
    }
});

it('parsea cada estudio oficial sin perder el texto fuente ni mezclar estudios', function () {
    foreach (preparationOfficialCasesFromJson() as $case) {
        foreach ($case['studies'] as $study) {
            $parsed = $this->parser->parseStudy(
                studyId: $study['study_id'],
                studyName: $study['study_name'],
                indications: $study['indications'],
            );

            expect($parsed->sourceText)->toBe($study['indications'])
                ->and($parsed->studyName)->toBe($study['study_name']);

            foreach ($parsed->requirements as $requirement) {
                expect($requirement->sourceText)->toBe($study['indications'])
                    ->and(str_contains($study['indications'], $requirement->sourceSpan))->toBeTrue();

                if ($requirement->sourceSpanEnd > $requirement->sourceSpanStart) {
                    expect(mb_substr(
                        $requirement->sourceText,
                        $requirement->sourceSpanStart,
                        $requirement->sourceSpanEnd - $requirement->sourceSpanStart,
                    ))->toBe($requirement->sourceSpan);
                }
            }
        }
    }
});

it('reconstruye los bloques oficiales con el splitter sin alterar entradas', function () {
    foreach (preparationOfficialCasesFromJson() as $case) {
        $studies = $this->splitter->split($case['case_id'], $case['source_text']);

        expect($studies)->toHaveCount(count($case['studies']))
            ->and(collect($studies)->pluck('study_id')->all())
            ->toBe(collect($case['studies'])->pluck('study_id')->all());

        foreach ($studies as $index => $study) {
            expect($study['indications'])->toBe($case['studies'][$index]['indications'])
                ->and($study['study_name'])->toBe($case['studies'][$index]['study_name']);
        }
    }
});

it('marca ambigüedades e incompletos explícitamente en casos oficiales', function () {
    $withSignals = 0;

    foreach (preparationOfficialCasesFromJson() as $case) {
        foreach ($case['studies'] as $study) {
            $parsed = $this->parser->parseStudy(
                studyId: $study['study_id'],
                studyName: $study['study_name'],
                indications: $study['indications'],
            );

            if ($parsed->hasUnrecognizedContent) {
                $withSignals++;
            }

            foreach ($parsed->requirements as $requirement) {
                expect(in_array($requirement->recognitionStatus, [
                    LaboratoryInstructionRecognitionStatus::RECOGNIZED,
                    LaboratoryInstructionRecognitionStatus::PARTIAL,
                    LaboratoryInstructionRecognitionStatus::AMBIGUOUS,
                    LaboratoryInstructionRecognitionStatus::UNRECOGNIZED,
                    LaboratoryInstructionRecognitionStatus::INCOMPLETE,
                ], true))->toBeTrue();
            }
        }
    }

    expect($withSignals)->toBeGreaterThan(0);
});
