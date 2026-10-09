<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPatientContext;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;

/** @var list<string> $caseIds */
$caseIds = array_slice($argv, 1);
if ($caseIds === []) {
    $caseIds = ['QA09', 'QA14', 'QA25', 'QA27', 'QA30', 'QA31', 'QA32', 'QA37'];
}

$jsonPath = __DIR__.'/../tests/Support/LaboratoryPreparation/casos_prueba_oficiales_v3.json';
/** @var list<array<string, mixed>> $allCases */
$allCases = json_decode((string) file_get_contents($jsonPath), true);
$casesById = collect($allCases)->keyBy('case_id');

$parser = app(LaboratoryInstructionParser::class);
$engine = app(LaboratoryPreparationRuleEngine::class);

foreach ($caseIds as $caseId) {
    /** @var array<string, mixed> $case */
    $case = $casesById->get($caseId);
    if ($case === null) {
        fwrite(STDERR, "Unknown case: {$caseId}\n");

        continue;
    }

    $items = array_map(
        static fn (array $study): array => [
            'id' => $study['study_id'],
            'name' => $study['study_name'],
            'gda_id' => $study['study_id'],
            'indications' => $study['indications'],
        ],
        $case['studies'],
    );

    $parse = $parser->parseItems($items);
    $decision = $engine->evaluate(
        orderId: $caseId,
        parseResult: $parse,
        patient: LaboratoryPreparationPatientContext::fromOfficialLabel((string) $case['patient_context']),
        restrictToPhase3aCategories: false,
    );

    $spans = [];
    foreach ($parse->studies as $study) {
        foreach ($study->requirements as $requirement) {
            if ($requirement->requirementType !== 'unrecognized_fragment') {
                continue;
            }

            $spans[] = $requirement->sourceSpan;
            if (count($spans) >= 3) {
                break 2;
            }
        }
    }

    echo json_encode([
        'case' => $caseId,
        'fallbackReason' => $decision->fallbackReason,
        'unrecognized_source_spans' => $spans,
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
}
