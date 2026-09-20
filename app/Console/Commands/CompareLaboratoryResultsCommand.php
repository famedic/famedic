<?php

namespace App\Console\Commands;

use App\Services\LaboratoryResults\Extraction\LaboratoryResultHybridExtractionQaService;
use Illuminate\Console\Command;
use Throwable;

class CompareLaboratoryResultsCommand extends Command
{
    protected $signature = 'laboratory:results:compare
                            {version : LaboratoryResultVersion ID}
                            {--force-vision : Run Vision even when fallback is not required}
                            {--synthetic : Use synthetic fixture mode marker in output}';

    protected $description = 'QA/dev: compare Text vs Vision extraction for a result version (Shadow Mode only, no patient flow changes)';

    public function handle(LaboratoryResultHybridExtractionQaService $qaService): int
    {
        if (app()->environment('production')) {
            $this->error('This command cannot run in production.');

            return self::FAILURE;
        }

        try {
            $qaService->assertAllowedEnvironment();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $versionId = (int) $this->argument('version');
        $forceVision = (bool) $this->option('force-vision');

        $this->info('Laboratory Results — Text vs Vision QA Compare');
        $this->line('Environment: '.app()->environment());
        $this->line('Shadow Mode: '.(config('laboratory-results.vision_extraction.shadow_mode', true) ? 'ON' : 'OFF'));
        $this->line('Vision enabled: '.(config('laboratory-results.vision_extraction.enabled', false) ? 'YES' : 'NO'));

        if ($this->option('synthetic')) {
            $this->warn('Dataset: synthetic laboratory result fixture (no real PII)');
        }

        $this->newLine();

        try {
            $result = $qaService->compareVersion($versionId, $forceVision);
        } catch (Throwable $e) {
            $this->error('Compare failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $output = $result->toCommandOutput();
        $summary = $output['summary'];

        $this->line('Result version: '.$output['result_version_id']);
        $this->line('Text report: '.($output['text_report_id'] ?? 'n/a (in-memory QA only)'));
        $this->line('Vision report: '.($output['vision_report_id'] ?? 'n/a'));
        $this->line('AiExecution: '.($output['ai_execution_id'] ?? 'n/a'));
        $this->line('QA metric: '.$output['qa_metric_id']);
        $this->newLine();

        $this->line('Text observations: '.($summary['text_observations'] ?? 0));
        $this->line('Vision observations: '.($summary['vision_observations'] ?? 0));
        $this->newLine();

        $this->line('MATCH: '.($summary['match'] ?? 0));
        $this->line('CONFLICT: '.($summary['conflict'] ?? 0));
        $this->line('VISION_ONLY: '.($summary['vision_only'] ?? 0));
        $this->line('TEXT_ONLY: '.($summary['text_only'] ?? 0));
        $this->line('UNRESOLVED: '.($summary['unresolved'] ?? 0));
        $this->newLine();

        if ($summary['match_rate'] !== null) {
            $this->line('Match rate: '.($summary['match_rate'] * 100).'%');
        }

        if ($summary['conflict_rate'] !== null) {
            $this->line('Conflict rate: '.($summary['conflict_rate'] * 100).'%');
        }

        if ($summary['vision_coverage'] !== null) {
            $this->line('Vision coverage: '.($summary['vision_coverage'] * 100).'%');
        }

        $this->newLine();
        $this->line('Vision status: '.$output['vision_status']);
        $this->line('Comparison outcome: '.($summary['comparison_outcome'] ?? 'unknown'));

        if ($output['fallback_reasons'] !== []) {
            $this->line('Fallback reasons: '.implode(', ', $output['fallback_reasons']));
        }

        if ($result->textPublishedStatusBefore !== null) {
            $this->newLine();
            $this->line('Text published status (unchanged): '.$result->textPublishedStatusAfter);
        }

        $this->newLine();
        $this->comment('Vision results are NOT published. Text published report was NOT replaced.');

        return self::SUCCESS;
    }
}
