<?php

namespace App\Console\Commands;

use App\Enums\LaboratoryBrand;
use App\Services\LaboratoryStores\Gda\GdaImportPlanner;
use App\Services\LaboratoryStores\Gda\GdaLaboratoryStoreImportApplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportGdaLaboratoryStoresCommand extends Command
{
    protected $signature = 'laboratory:stores-gda-import
        {path : Path to the GDA Excel file}
        {--dry-run : Persist audit rows only and never mutate laboratory store data}
        {--brand= : Required brand scope for apply; optional for dry-run}
        {--apply : Future apply mode; blocked unless feature flag and confirmations are present}
        {--run-id= : Completed dry-run audit run ID to apply or export}
        {--confirm-hash= : SHA-256 hash that must match the reviewed Excel file}
        {--confirm-apply= : Explicit non-interactive confirmation matching the requested brand in uppercase}
        {--export-backup= : Write a logical JSON backup for the scoped brand without applying}
        {--export-sql= : Write a rollback-ended SQL preview file without executing it}
        {--export-rollback= : Write a brand-scoped rollback preview file for an applied fixture run}';

    protected $description = 'Plan the GDA laboratory store import as a dry-run audit';

    public function handle(GdaImportPlanner $planner, GdaLaboratoryStoreImportApplier $applier): int
    {
        if (! $this->option('dry-run') && ! $this->option('apply') && ! $this->option('export-backup') && ! $this->option('export-rollback')) {
            $this->error('Apply mode is not enabled in this phase.');

            return self::FAILURE;
        }

        try {
            $path = $this->resolvePath((string) $this->argument('path'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('export-rollback')) {
            return $this->exportRollback($applier);
        }

        if ($this->option('export-backup')) {
            return $this->exportBackup($applier);
        }

        if ($this->option('apply')) {
            return $this->apply($applier, $path);
        }

        try {
            $plan = $planner->plan($path, $this->option('brand'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderPlan($plan);

        if ($exportSql = $this->optionString('export-sql')) {
            $brand = $this->optionString('brand');

            if ($brand === null || $plan->runId === null) {
                $this->error('--brand is required for --export-sql.');

                return self::FAILURE;
            }

            try {
                $applier->exportSqlPreview($plan->runId, $path, $brand, hash_file('sha256', $path), $exportSql);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("SQL preview written: {$exportSql}");
        }

        $this->warn('No laboratory_stores rows were created, updated, deleted, or restored.');

        return self::SUCCESS;
    }

    private function apply(GdaLaboratoryStoreImportApplier $applier, string $path): int
    {
        if (! (bool) config('laboratory-stores.gda_import.apply_enabled', false)) {
            $this->error('Apply mode is disabled. Set LABORATORY_GDA_IMPORT_APPLY_ENABLED=true only for an approved apply window.');

            return self::FAILURE;
        }

        $brand = $this->optionString('brand');
        $runId = $this->optionString('run-id');
        $confirmHash = $this->optionString('confirm-hash') ?? '';
        $confirmApply = $this->optionString('confirm-apply');

        if (! $this->supportedBrand($brand) || $runId === null || ! ctype_digit($runId)) {
            $this->error('--apply requires --brand to be one of: '.$this->supportedBrandsForDisplay().', plus --run-id, --confirm-hash and --confirm-apply=<BRAND>.');

            return self::FAILURE;
        }

        $expectedConfirmation = strtoupper((string) $brand);

        if ($confirmApply !== $expectedConfirmation) {
            $this->error("--confirm-apply must be {$expectedConfirmation} for --brand={$brand}.");

            return self::FAILURE;
        }

        try {
            $summary = $applier->apply((int) $runId, $path, $brand, $confirmHash);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('GDA IMPORT APPLY COMPLETED');
        $this->table(['Metric', 'Total'], collect($summary)->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }

    private function exportRollback(GdaLaboratoryStoreImportApplier $applier): int
    {
        $runId = $this->optionString('run-id');
        $brand = $this->optionString('brand');
        $exportPath = $this->optionString('export-rollback');

        if ($runId === null || ! ctype_digit($runId) || ! $this->supportedBrand($brand) || $exportPath === null) {
            $this->error('--export-rollback requires --run-id and --brand to be one of: '.$this->supportedBrandsForDisplay().'.');

            return self::FAILURE;
        }

        try {
            $applier->exportRollbackSql((int) $runId, $brand, $exportPath);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Rollback preview written: {$exportPath}");

        return self::SUCCESS;
    }

    private function exportBackup(GdaLaboratoryStoreImportApplier $applier): int
    {
        $brand = $this->optionString('brand');
        $exportPath = $this->optionString('export-backup');

        if (! $this->supportedBrand($brand) || $exportPath === null) {
            $this->error('--export-backup requires --brand to be one of: '.$this->supportedBrandsForDisplay().'.');

            return self::FAILURE;
        }

        try {
            $applier->backupBrand($brand, $exportPath);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Brand backup written: {$exportPath}");

        return self::SUCCESS;
    }

    private function renderPlan($plan): void
    {
        $this->info('GDA IMPORT DRY RUN');
        $this->line("Audit run ID: {$plan->runId}");
        $this->newLine();

        $this->line('DIRECTORIO');
        $brand = $this->optionString('brand');
        $brandLabel = $brand === null ? 'All brands' : strtoupper($brand);

        $this->table(['Metric', 'Total'], [
            ["{$brandLabel} rows", $plan->totals['directory_rows']],
            ['MATCHED', $plan->totals['directory']['matched']],
            ['NEW', $plan->totals['directory']['new']],
            ['AMBIGUOUS', $plan->totals['directory']['ambiguous']],
            ['INVALID', $plan->totals['directory']['invalid']],
            ['SOFT_DELETED_MATCH', $plan->totals['directory']['soft_deleted_match']],
            ['VALID', $plan->totals['directory']['validation_valid']],
            ['WITH_WARNINGS', $plan->totals['directory']['validation_warning']],
            ['INVALID_FIELDS', $plan->totals['directory']['validation_invalid_fields']],
        ]);

        $this->line('HISTORIA CLINICA');
        $this->table(['Metric', 'Total'], [
            ["Rows {$brandLabel}", $plan->totals['clinical_history_rows']],
            ['MATCHED', $plan->totals['clinical_history']['matched']],
            ['AMBIGUOUS', $plan->totals['clinical_history']['ambiguous']],
            ['UNMATCHED', $plan->totals['clinical_history']['unmatched']],
        ]);

        $this->line('OPTICAS');
        $this->table(['Metric', 'Total'], [
            ["Rows {$brandLabel}", $plan->totals['optical_rows']],
            ['MATCHED', $plan->totals['optical']['matched']],
            ['AMBIGUOUS', $plan->totals['optical']['ambiguous']],
            ['UNMATCHED', $plan->totals['optical']['unmatched']],
        ]);

        $this->table(['Metric', 'Total'], [
            ['Warnings', $plan->totals['warnings']],
            ['Business writes', 0],
            ['Processed including auxiliary sheets', $plan->totals['processed']],
        ]);

        if ($this->option('verbose')) {
            $this->table(
                ['Sheet', 'Row', 'Brand', 'Name', 'Auto Classification', 'Auto Matched ID', 'Resolution Source', 'Resolution Decision', 'Final Classification', 'Validation', 'Action', 'Matched ID', 'Warnings/Errors'],
                collect($plan->rows)->map(fn ($row) => [
                    $row->row->sheet,
                    $row->row->rowNumber,
                    $row->brand,
                    $row->sourceName,
                    $row->autoClassification,
                    $row->autoMatchedStoreId,
                    $row->resolutionSource,
                    $row->resolutionDecision,
                    $row->classification,
                    $row->validationStatus,
                    $row->action,
                    $row->matchedStoreId,
                    implode(' | ', $row->errors),
                ])->all(),
            );
        }
    }

    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->path($path);
        }

        $storagePath = storage_path('app/'.$path);

        if (is_file($storagePath)) {
            return $storagePath;
        }

        throw new \RuntimeException("File not found: {$path}");
    }

    private function optionString(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }

    private function supportedBrand(?string $brand): bool
    {
        return $brand !== null
            && in_array($brand, array_map(fn (LaboratoryBrand $brand) => $brand->value, LaboratoryBrand::cases()), true);
    }

    private function supportedBrandsForDisplay(): string
    {
        return implode(', ', array_map(fn (LaboratoryBrand $brand) => $brand->value, LaboratoryBrand::cases()));
    }
}
