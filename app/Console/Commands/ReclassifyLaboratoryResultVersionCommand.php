<?php

namespace App\Console\Commands;

use App\Actions\Laboratories\ReclassifyLaboratoryResultVersionAction;
use Illuminate\Console\Command;

class ReclassifyLaboratoryResultVersionCommand extends Command
{
    protected $signature = 'laboratory:reclassify-result-version
                            {version : LaboratoryResultVersion ID}
                            {--dry-run : Classify without persisting changes}';

    protected $description = 'Reclassify an existing laboratory result version from its stored PDF (local/testing only)';

    public function handle(ReclassifyLaboratoryResultVersionAction $action): int
    {
        $versionId = (int) $this->argument('version');

        if ($this->option('dry-run')) {
            $this->warn('Dry-run is not implemented; use tests or run without --dry-run in local.');

            return self::INVALID;
        }

        try {
            $result = $action->execute($versionId);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $version = $result->version;

        if (! $result->changed) {
            $this->info("Version {$versionId} already matches current classifier output (idempotent skip).");

            return self::SUCCESS;
        }

        $this->info("Version {$versionId} reclassified.");
        $this->line("  classification: {$version->classification->value}");
        $this->line("  reason: {$version->classification_reason}");
        $this->line("  classifier: {$version->classifier}");
        $this->line('  extraction_dispatched: '.($result->extractionDispatched ? 'yes' : 'no'));
        $this->line('  extraction_suppressed: '.($result->extractionSuppressed ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
