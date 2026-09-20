<?php

namespace App\Console\Commands;

use App\Services\LaboratoryResults\Extraction\LaboratoryResultHybridExtractionExperimentQaService;
use Illuminate\Console\Command;
use Throwable;

class CompareLaboratoryResultsExperimentCommand extends Command
{
    protected $signature = 'laboratory:results:compare-experiment
                            {version : LaboratoryResultVersion ID}
                            {--json : Output target analyte snapshots as JSON}';

    protected $description = 'QA/dev: 8C-10B experiment — real PDF raster + Smalot hybrid input (Shadow Mode, no publication)';

    public function handle(LaboratoryResultHybridExtractionExperimentQaService $experimentService): int
    {
        if (app()->environment('production')) {
            $this->error('This command cannot run in production.');

            return self::FAILURE;
        }

        try {
            $result = $experimentService->compareVersionExperiment((int) $this->argument('version'));
        } catch (Throwable $e) {
            $this->error('Experiment compare failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $output = $result->toCommandOutput();
        $summary = $output['summary'];
        $snapshots = $experimentService->targetAnalyteSnapshots($result);

        $this->info('Laboratory Results — 8C-10B Experiment (real PDF raster + hybrid text)');
        $this->line('Input mode: real_pdf_raster_hybrid');
        $this->line('Prompt version: '.($summary['prompt_version'] ?? 'n/a'));
        $this->line('AiExecution: '.($output['ai_execution_id'] ?? 'n/a'));
        $this->line('QA metric: '.$output['qa_metric_id']);
        $this->newLine();

        $this->line('MATCH: '.($summary['match'] ?? 0));
        $this->line('CONFLICT: '.($summary['conflict'] ?? 0));
        $this->line('VISION_ONLY: '.($summary['vision_only'] ?? 0));
        $this->line('TEXT_ONLY: '.($summary['text_only'] ?? 0));
        $this->newLine();

        if ($this->option('json')) {
            $this->line(json_encode([
                'version_id' => $output['result_version_id'],
                'ai_execution_id' => $output['ai_execution_id'],
                'summary' => $summary,
                'target_analytes' => $snapshots,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($snapshots as $row) {
            $this->line($row['analyte'].' ['.$row['outcome'].']');
            $this->line('  Text:   '.json_encode($row['text'], JSON_UNESCAPED_UNICODE));
            $this->line('  Vision: '.json_encode($row['vision'], JSON_UNESCAPED_UNICODE));

            if ($row['conflict_fields'] !== []) {
                $this->line('  Conflict fields: '.implode(', ', $row['conflict_fields']));
            }
        }

        $this->newLine();
        $this->comment('Experiment only — Vision NOT published. Text report NOT replaced.');

        return self::SUCCESS;
    }
}
