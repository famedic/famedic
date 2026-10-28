<?php

// Real Laravel queue worker, providers simulated, disposable MySQL only.
require __DIR__.'/../../vendor/autoload.php';
$test = new class('confirmation_worker') extends \Tests\TestCase {};
$app = $test->createApplication();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Notification::fake();
[$script, $queue, $scenario, $run] = $argv;
config(['queue.default' => 'database', 'laboratory-confirmation.enabled' => $scenario !== 'paused',
    'laboratory-confirmation.activated_at' => now()->subDay()->utc()->toIso8601String(),
    'services.laboratory_preparation.deterministic_v3_enabled' => true,
    'services.laboratory_preparation.deterministic_v3_shadow_enabled' => false]);
$audit = function (string $event, ?int $intent = null, ?string $hash = null) use ($run): void {
    \Illuminate\Support\Facades\DB::table('confirmation_worker_audit')->insert([
        'run' => $run, 'event' => $event, 'intent_id' => $intent, 'digest' => $hash, 'created_at' => now()]);
};
$transport = \Mockery::mock(\App\Services\LaboratoryConfirmation\LaboratoryConfirmationTransport::class);
$transport->shouldReceive('send')->andReturnUsing(function ($payload, $attemptId) use ($audit, $scenario) {
    $intent = \App\Models\LaboratoryPurchaseConfirmation::where('attempt_id', $attemptId)->sole();
    $audit('send', $intent->id, hash('sha256', json_encode($payload)));
    usleep(250000);
    if ($scenario === 'crash') { exit(0); }
    if ($scenario === 'ambiguous') { throw new \RuntimeException('AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML'); }
    return 'QA-'.$attemptId;
});
$app->instance(\App\Services\LaboratoryConfirmation\LaboratoryConfirmationTransport::class, $transport);
$service = \Mockery::mock($app->make(\App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService::class))->makePartial();
$service->shouldReceive('generate')->andReturnUsing(function ($purchase) use ($audit, $scenario, $run) {
    $audit('generation_started');
    if ($scenario === 'blocked') {
        $until = microtime(true) + 20;
        while (! \Illuminate\Support\Facades\DB::table('confirmation_worker_audit')->where('run', $run)->where('event', 'release_generation')->exists()) {
            if (microtime(true) > $until) { throw new \RuntimeException('QA barrier timeout'); }
            usleep(20000);
        }
    }
    $hash = app(\App\Services\LaboratoryPreparation\LaboratoryPreparationSource::class)->deterministicHash($purchase);
    $execution = \App\Models\AiExecution::create(['domain' => 'laboratory', 'feature' => 'laboratory_preparation_redactor',
        'subject_type' => $purchase->getMorphClass(), 'subject_id' => $purchase->id, 'status' => 'succeeded', 'input_hash' => $hash]);
    return \App\Models\LaboratoryPurchasePreparationSummary::create(['laboratory_purchase_id' => $purchase->id,
        'ai_execution_id' => $execution->id, 'source_hash' => $hash, 'status' => 'generated', 'decision_status' => 'AUTO_CONSOLIDATED',
        'rules_version' => \App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine::RULES_VERSION,
        'summary_text' => 'QA_AUTO_VALIDADO', 'summary_json' => ['summary' => 'QA_AUTO_VALIDADO',
            'sections' => [['title' => 'Preparación', 'content' => 'QA_AUTO_VALIDADO']], 'special_instructions' => [], 'individual_instructions' => []],
        'generated_at' => now()]);
});
$app->instance(\App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService::class, $service);
if ($scenario === 'race') {
    $audit('ready');
    $until = microtime(true) + 20;
    while (! \Illuminate\Support\Facades\DB::table('confirmation_worker_audit')->where('run', $run)->where('event', 'start')->exists()) {
        if (microtime(true) > $until) { throw new \RuntimeException('QA barrier timeout'); }
        usleep(20000);
    }
}
$code = \Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue,
    '--once' => true, '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 60]);
if (str_contains(\Illuminate\Support\Facades\Artisan::output(), 'AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML')) { exit(3); }
\Illuminate\Support\Facades\Notification::assertNotSentTo(\App\Models\User::all(), \App\Notifications\LaboratoryPreparationSummaryAvailable::class);
exit($code);
