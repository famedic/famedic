<?php

declare(strict_types=1);

use App\Services\LaboratoryPreparation\LaboratoryInstructionParser;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** @var list<array{case_id: string, study_id: string, study_name: string, indications: string, expected_categories: list<string>}> $cases */
$cases = require __DIR__.'/tests/Support/LaboratoryPreparation/parser_escenarios_regresion_v3.php';

$parser = app(LaboratoryInstructionParser::class);

foreach ($cases as $case) {
    $study = $parser->parseStudy(
        $case['study_id'],
        $case['study_name'],
        $case['indications'],
    );

    /** @var list<string> $foundCategories */
    $foundCategories = collect($study->requirements)->pluck('category')->values()->all();

    foreach ($case['expected_categories'] as $category) {
        if (! in_array($category, $foundCategories, true)) {
            echo 'FAIL case_id='.$case['case_id'].PHP_EOL;
            echo 'expected_categories='.json_encode($case['expected_categories'], JSON_UNESCAPED_UNICODE).PHP_EOL;
            echo 'found_categories='.json_encode($foundCategories, JSON_UNESCAPED_UNICODE).PHP_EOL;
            exit(1);
        }
    }
}

echo 'OK: all '.count($cases).' cases passed expected_categories checks.'.PHP_EOL;
exit(0);
