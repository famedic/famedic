<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$m = App\Models\LaboratoryResultExtractionQaMetric::where('laboratory_result_version_id', 33)->orderBy('id')->first();
echo json_encode($m?->summary, JSON_PRETTY_PRINT);