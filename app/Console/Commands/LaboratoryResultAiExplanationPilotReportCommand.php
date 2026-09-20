<?php

namespace App\Console\Commands;

use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationObservabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class LaboratoryResultAiExplanationPilotReportCommand extends Command
{
    protected $signature = 'laboratory:ai-explanation:pilot-report
                            {--since= : ISO date/time lower bound for metrics}
                            {--json : Output raw JSON only}';

    protected $description = 'Aggregate AI explanation pilot metrics for QA/staging (no PII, not for production)';

    public function handle(LaboratoryResultAiExplanationObservabilityService $observability): int
    {
        if (app()->environment('production')) {
            $this->error('This command cannot run in production.');

            return self::FAILURE;
        }

        $since = $this->option('since')
            ? Carbon::parse((string) $this->option('since'))
            : null;

        try {
            $report = $observability->buildReport($since);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('AI Explanation Pilot Report');
        $this->line('Environment: '.$report['environment']);
        $this->line('Effective enabled: '.($report['feature']['effective_enabled'] ? 'yes' : 'no'));
        if ($report['feature']['block_reason']) {
            $this->line('Block reason: '.$report['feature']['block_reason']);
        }

        $this->newLine();
        $this->info('Explanations');
        foreach ($report['explanations']['by_status'] as $status => $count) {
            $this->line("  {$status}: {$count}");
        }
        $this->line('  ready_rate: '.$report['explanations']['ready_rate']);

        $this->newLine();
        $this->info('Consent');
        $this->line('  accepted: '.$report['consent']['accepted']);
        $this->line('  declined: '.$report['consent']['declined']);
        $this->line('  consent_rate: '.($report['consent']['consent_rate'] ?? 'n/a'));

        $this->newLine();
        $this->info('AiExecution');
        $ai = $report['ai_execution'];
        $this->line("  total: {$ai['total']} | succeeded: {$ai['succeeded']} | failed: {$ai['failed']}");
        $this->line('  avg_duration_ms: '.($ai['avg_duration_ms'] ?? 'n/a'));
        $this->line('  avg_total_tokens: '.($ai['avg_total_tokens'] ?? 'n/a'));
        $this->line('  avg_cost_usd: '.($ai['avg_cost_usd'] ?? 'cost not available'));
        $this->line('  429 errors: '.$ai['openai_errors_429']);
        $this->line('  timeout errors: '.$ai['openai_errors_timeout']);

        $this->newLine();
        $this->info('Latency (request → ready)');
        $latency = $report['latency_ms'];
        $this->line('  avg_ms: '.($latency['avg_request_to_ready_ms'] ?? 'n/a'));
        $this->line('  max_ms: '.($latency['max_request_to_ready_ms'] ?? 'n/a'));

        $this->newLine();
        $this->info('Safety failures (from sanitized AiExecution errors)');
        foreach ($report['safety_failures'] as $key => $count) {
            $this->line("  {$key}: {$count}");
        }

        $this->newLine();
        $this->info('Prompt version consistency');
        $prompt = $report['prompt_version_consistency'];
        $this->line('  consistent: '.($prompt['consistent'] ? 'yes' : 'NO — investigate'));
        $this->line('  expected: '.$prompt['expected_contract_version'].' ('.$prompt['expected_label'].')');
        $this->line('  db active: '.json_encode($prompt['active_db_versions']));
        $this->line('  executions: '.json_encode($prompt['execution_prompt_versions']));

        return self::SUCCESS;
    }
}
