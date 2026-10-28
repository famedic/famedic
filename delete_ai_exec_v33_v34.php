<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach ([33, 34] as $id) {
    $deleted = App\Models\AiExecution::where('domain', 'lab_results_extraction')->where('subject_id', $id)->delete();
    echo "deleted exec for v{$id}: {$deleted}\n";
}