#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRecognitionStatus;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$jsonPath = __DIR__.'/../tests/Support/LaboratoryPreparation/casos_prueba_oficiales_v3.json';
if (! is_readable($jsonPath)) {
    fwrite(STDERR, "Falta {$jsonPath}. Ejecuta generate_casos_prueba_oficiales_v3.php primero.\n");
    exit(1);
}

/** @var list<array<string, mixed>> $cases */
$cases = json_decode((string) file_get_contents($jsonPath), true);
$parser = app(LaboratoryInstructionParser::class);

echo "CP | Reconocimiento | Fragmentos desconocidos | Ambigüedades | Observaciones\n";
echo str_repeat('-', 120)."\n";

foreach ($cases as $case) {
    $caseId = (string) $case['case_id'];
    $unknownFragments = [];
    $ambiguous = [];
    $recognized = 0;
    $totalRequirements = 0;
    $observations = [];

    foreach ($case['studies'] as $study) {
        $result = $parser->parseStudy(
            studyId: (string) $study['study_id'],
            studyName: (string) $study['study_name'],
            indications: (string) $study['indications'],
        );

        if ($result->sourceText !== (string) $study['indications']) {
            $observations[] = 'pérdida texto estudio '.$study['study_id'];
        }

        foreach ($result->requirements as $requirement) {
            $totalRequirements++;
            if ($requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::RECOGNIZED) {
                $recognized++;
            }
            if ($requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::AMBIGUOUS) {
                $ambiguous[] = $requirement->sourceSpan;
            }
            if ($requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::UNRECOGNIZED) {
                $unknownFragments[] = $requirement->sourceSpan;
            }
            if ($requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::INCOMPLETE) {
                $observations[] = 'incompleto: '.$requirement->sourceSpan;
            }
        }

        $unknownFragments = [...$unknownFragments, ...$result->unrecognizedFragments];
    }

    $recognition = $totalRequirements === 0
        ? 'sin_indicaciones'
        : sprintf('%d/%d reqs', $recognized, $totalRequirements);

    $unknownText = $unknownFragments === [] ? '—' : mb_substr(implode(' | ', array_unique($unknownFragments)), 0, 80);
    $ambiguousText = $ambiguous === [] ? '—' : mb_substr(implode(' | ', array_unique($ambiguous)), 0, 80);
    $obsText = $observations === [] ? '—' : mb_substr(implode('; ', array_unique($observations)), 0, 60);

    echo "{$caseId} | {$recognition} | {$unknownText} | {$ambiguousText} | {$obsText}\n";
}
