<?php

namespace App\Console\Commands;

use App\Models\LaboratoryResultReport;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaPromotionService;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaRunOptions;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPublicationException;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPublicationGate;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPublicationService;
use Illuminate\Console\Command;
use Throwable;

class StructuredQaLaboratoryResultsCommand extends Command
{
    protected $signature = 'laboratory:results:structured-qa
                            {--dry-run : Preview without writing to the database}
                            {--version-id= : Limit to a single LaboratoryResultVersion ID}
                            {--limit= : Maximum number of observations to process}
                            {--report-id= : Explicit LaboratoryResultReport ID (required for publication)}
                            {--evaluate-promotion : Run promotion gate on Shadow QA observations (8C-18)}
                            {--publish-approved : Publish one approved report (8C-19B pilot)}';

    protected $description = 'Shadow/QA: promotion gate, approval tooling, and controlled publication pilot';

    public function handle(
        LaboratoryStructuredQaPromotionService $promotionService,
        LaboratoryStructuredResultPublicationService $publicationService,
        LaboratoryStructuredResultPublicationGate $publicationGate,
    ): int {
        $publishApproved = (bool) $this->option('publish-approved');
        $evaluatePromotion = (bool) $this->option('evaluate-promotion');

        if ($publishApproved) {
            return $this->handlePublishApproved($publicationService, $publicationGate);
        }

        if (! $evaluatePromotion) {
            $this->error('Specify --evaluate-promotion or --publish-approved --report-id=ID');

            return self::FAILURE;
        }

        try {
            $promotionService->assertAllowedEnvironment();
            $promotionService->assertEnabled();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $versionOption = $this->option('version-id');
        $limitOption = $this->option('limit');

        $options = new LaboratoryStructuredQaRunOptions(
            dryRun: (bool) $this->option('dry-run'),
            versionId: $versionOption !== null && $versionOption !== '' ? (int) $versionOption : null,
            limit: $limitOption !== null && $limitOption !== '' ? (int) $limitOption : null,
            evaluatePromotion: true,
        );

        $this->info('Laboratory Results — Shadow QA Promotion Gate (8C-18)');
        $this->line('Environment: '.app()->environment());
        $this->line('Mode: '.($options->dryRun ? 'DRY RUN' : 'PERSIST'));
        $this->newLine();

        try {
            $result = $promotionService->evaluate($options);
        } catch (Throwable $e) {
            $this->error('Promotion gate failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($options->dryRun) {
            foreach ($result->preview as $row) {
                $this->line(sprintf(
                    '[%s] obs=%s %s — %s',
                    strtoupper($row['promotion_status']),
                    $row['observation_id'],
                    $row['analyte_code'],
                    implode('; ', $row['reasons'] ?: ['ok']),
                ));
            }
        }

        $this->newLine();
        $this->line('Promotion gate metrics');
        $this->line('----------------------');
        $this->line('Candidates: '.$result->candidatesInput);
        $this->line('Validated: '.$result->validatedCount);
        $this->line('Needs Review: '.$result->needsReviewCount);
        $this->line('Rejected: '.$result->rejectedCount);

        if (! $options->dryRun) {
            $this->line('Updated: '.$result->updatedCount);
            $this->line('Duplicate prevented: '.$result->duplicatePrevented);
        }

        if ($result->reasonCodeCounts !== []) {
            $this->newLine();
            $this->line('Reason codes:');
            foreach ($result->reasonCodeCounts as $code => $count) {
                $this->line("  {$code}: {$count}");
            }
        }

        return self::SUCCESS;
    }

    private function handlePublishApproved(
        LaboratoryStructuredResultPublicationService $publicationService,
        LaboratoryStructuredResultPublicationGate $publicationGate,
    ): int {
        $reportIdOption = $this->option('report-id');

        if ($reportIdOption === null || $reportIdOption === '') {
            $this->error('--report-id is required for --publish-approved');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $reportId = (int) $reportIdOption;

        $this->info('Laboratory Results — Controlled Publication Pilot (8C-19B)');
        $this->line('Environment: '.app()->environment());
        $this->line('Mode: '.($dryRun ? 'DRY RUN' : 'PUBLISH'));
        $this->line('Report ID: '.$reportId);
        $this->newLine();

        $report = LaboratoryResultReport::query()->with(['observations', 'resultVersion'])->find($reportId);

        if ($report === null) {
            $this->error('Report not found: '.$reportId);

            return self::FAILURE;
        }

        $gateResult = $publicationGate->evaluate($report);

        $this->line('Publication eligibility preview');
        $this->line('-------------------------------');
        foreach ($gateResult->preview as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $this->line(sprintf('%s: %s', $key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }

        if ($gateResult->reasonsHuman !== []) {
            $this->newLine();
            $this->line('Blocking reasons:');
            foreach ($gateResult->reasonsHuman as $reason) {
                $this->line('  - '.$reason);
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('Eligible: '.($gateResult->canPublish ? 'YES' : 'NO'));

            return self::SUCCESS;
        }

        try {
            $publicationService->assertEnabled();
            $result = $publicationService->publish($report, actor: null, dryRun: false);
        } catch (LaboratoryStructuredResultPublicationException $e) {
            $this->error('Publication blocked: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Publication failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Published: '.($result->published ? 'yes' : 'no'));
        $this->line('Idempotent: '.($result->idempotent ? 'yes' : 'no'));
        $this->line('published_version_slot: '.($result->report->published_version_slot ?? 'null'));

        return self::SUCCESS;
    }
}
