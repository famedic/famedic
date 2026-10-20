<?php

namespace App\Console\Commands;

use App\Enums\LaboratoryResultExtractionQaDocumentCategory;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionQaBatchOptions;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionQaBatchService;
use Illuminate\Console\Command;
use Throwable;

class QaBatchLaboratoryResultsCommand extends Command
{
    protected $signature = 'laboratory:results:qa-batch
                            {--limit=20 : Maximum number of versions to evaluate}
                            {--source=gda : Filter by LaboratoryResultVersion source}
                            {--status=complete : Filter by LaboratoryResultStatus status}
                            {--environment= : Require current app environment to match}
                            {--force-vision : Run Vision even when fallback is not required}
                            {--verbose-conflicts : Include clinical values in conflict output (default OFF)}';

    protected $description = 'QA batch: evaluate Text vs Vision on a controlled dataset (Shadow Mode only, no PII output)';

    public function handle(LaboratoryResultExtractionQaBatchService $batchService): int
    {
        if (app()->environment('production')) {
            $this->error('This command cannot run in production.');

            return self::FAILURE;
        }

        $options = new LaboratoryResultExtractionQaBatchOptions(
            limit: (int) $this->option('limit'),
            source: $this->option('source') ?: null,
            status: $this->option('status') ?: null,
            environment: $this->option('environment') ?: null,
            forceVision: (bool) $this->option('force-vision'),
            verboseConflicts: (bool) $this->option('verbose-conflicts'),
        );

        try {
            $result = $batchService->run($options);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Laboratory Results QA Batch');
        $this->line('===========================');
        $this->newLine();

        $this->line('Versions evaluated: '.$result->totalVersions);
        $this->line('Text available: '.$result->versionsWithText);
        $this->line('Vision executed: '.$result->versionsWithVision);
        $this->newLine();

        $this->line('Comparable pairs: '.$result->totalComparablePairs);
        $this->newLine();

        $this->line('MATCH: '.$result->totalMatch);
        $this->line('CONFLICT: '.$result->totalConflict);
        $this->line('TEXT_ONLY: '.$result->totalTextOnly);
        $this->line('VISION_ONLY: '.$result->totalVisionOnly);
        $this->line('UNRESOLVED: '.$result->totalUnresolved);
        $this->newLine();

        $this->line('Match rate: '.$result->formatPercentage($result->matchRate));
        $this->line('Conflict rate: '.$result->formatPercentage($result->conflictRate));
        $this->line('Vision coverage: '.$result->formatPercentage($result->visionCoverage));
        $this->line('Vision-only rate: '.$result->formatPercentage($result->visionOnlyRate));
        $this->line('Text-only rate: '.$result->formatPercentage($result->textOnlyRate));
        $this->newLine();

        $this->line('Documents:');
        foreach (LaboratoryResultExtractionQaDocumentCategory::cases() as $category) {
            $this->line($category->value.': '.($result->categoryCounts[$category->value] ?? 0));
        }

        $this->newLine();
        $this->line('Per-document summary:');

        foreach ($result->documents as $document) {
            if (! $document->success) {
                $this->line('  version_id='.$document->versionId.' ERROR='.$document->errorMessage);

                continue;
            }

            $this->line(sprintf(
                '  version_id=%d category=%s text_obs=%s vision_obs=%s match=%d conflict=%d vision_exec=%s',
                $document->versionId,
                $document->documentCategory?->value ?? 'n/a',
                $document->textObservationCount ?? 0,
                $document->visionObservationCount ?? 0,
                $document->matchCount,
                $document->conflictCount,
                $document->visionExecuted ? 'yes' : 'no',
            ));
        }

        $conflicts = $result->allConflicts($options->verboseConflicts);

        if ($conflicts !== []) {
            $this->newLine();
            $this->line('Conflicts ('.count($conflicts).'):');

            foreach ($conflicts as $conflict) {
                $fields = implode(',', $conflict['conflict_fields'] ?? []);
                $line = '  version_id='.$conflict['version_id'].' analyte_key='.($conflict['analyte_key'] ?? 'n/a').' fields='.$fields;

                if ($options->verboseConflicts) {
                    $line .= ' text_value='.($conflict['text_value'] ?? 'n/a');
                    $line .= ' vision_value='.($conflict['vision_value'] ?? 'n/a');
                }

                $this->line($line);
            }
        }

        if ($result->aliasCandidateVersionIds['text_only_analyte_keys'] !== [] || $result->aliasCandidateVersionIds['vision_only_analyte_keys'] !== []) {
            $this->newLine();
            $this->line('Alias resolution candidates (normalized keys, no fuzzy matching applied):');

            foreach ($result->aliasCandidateVersionIds['text_only_analyte_keys'] as $key => $count) {
                $this->line('  TEXT_ONLY key="'.$key.'" occurrences='.$count);
            }

            foreach ($result->aliasCandidateVersionIds['vision_only_analyte_keys'] as $key => $count) {
                $this->line('  VISION_ONLY key="'.$key.'" occurrences='.$count);
            }
        }

        $this->newLine();
        $this->comment('Vision remains: SHADOW MODE');
        $this->comment('No patient PII printed. No Vision publication. No Text replacement.');

        return self::SUCCESS;
    }
}
