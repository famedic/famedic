<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows = App\Models\AiExecution::query()
    ->where('domain', 'lab_results_extraction')
    ->whereIn('subject_id', [33,34])
    ->orderByDesc('id')
    ->limit(10)
    ->get(['id','subject_id','status','error','model']);
echo json_encode($rows, JSON_PRETTY_PRINT);