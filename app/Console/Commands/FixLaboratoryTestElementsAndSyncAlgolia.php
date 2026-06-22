<?php

namespace App\Console\Commands;

use App\Models\LaboratoryTest;
use Illuminate\Console\Command;

class FixLaboratoryTestElementsAndSyncAlgolia extends Command
{
    protected $signature = 'laboratory-tests:fix-elements-and-sync-algolia
                            {--value= : Valor que se asignará a elements (default: - - -)}
                            {--dry-run : Muestra qué registros se actualizarían sin cambiar DB ni Algolia}
                            {--force : Ejecuta sin confirmación interactiva}';

    protected $description = 'Update elements for specific laboratory tests in DB and sync only those records to Algolia.';

    /**
     * @var list<int>
     */
    private const TARGET_IDS = [1803, 1804, 1805, 1806];

    public function handle(): int
    {
        $value = $this->resolveElementsValue();
        $indexName = (new LaboratoryTest)->searchableAs();

        $this->line('Environment: '.app()->environment());
        $this->line("Scout index: {$indexName}");
        $this->line('Target IDs: '.implode(', ', self::TARGET_IDS));
        $this->line("New elements value: \"{$value}\"");
        $this->newLine();

        $laboratoryTests = LaboratoryTest::query()
            ->whereIn('id', self::TARGET_IDS)
            ->get()
            ->keyBy('id');

        foreach (self::TARGET_IDS as $id) {
            $laboratoryTest = $laboratoryTests->get($id);

            if ($laboratoryTest === null) {
                $this->warn("ID {$id}: not found in database.");

                continue;
            }

            $current = $laboratoryTest->elements ?? '<null>';

            $this->line("ID {$id} | gda_id={$laboratoryTest->gda_id} | name={$laboratoryTest->name}");
            $this->line("  current elements: {$current}");
            $this->line("  new elements: \"{$value}\"");
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('DRY RUN — no changes made to DB or Algolia.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This command will update MySQL and sync Algolia for laboratory tests: '.implode(', ', self::TARGET_IDS).'.');
        $this->warn('This should only be executed in the intended environment.');

        if (! $this->option('force') && ! $this->confirm('Continue?')) {
            return self::SUCCESS;
        }

        $scoutQueue = config('scout.queue');
        config(['scout.queue' => false]);

        try {
            foreach (self::TARGET_IDS as $id) {
                $laboratoryTest = $laboratoryTests->get($id);

                if ($laboratoryTest === null) {
                    $this->warn("Skipping ID {$id}: not found in database.");

                    continue;
                }

                $previousElements = $laboratoryTest->elements;

                $laboratoryTest->forceFill([
                    'elements' => $value,
                ])->save();

                $laboratoryTest->load('laboratoryTestCategory');
                $laboratoryTest->searchable();

                $this->info("Updated ID {$id} | gda_id={$laboratoryTest->gda_id} | name={$laboratoryTest->name}");
                $this->line('  previous elements: '.($previousElements ?? '<null>'));
                $this->line("  new elements: \"{$value}\"");
                $this->line('  synced to Algolia.');
            }
        } finally {
            config(['scout.queue' => $scoutQueue]);
        }

        $this->newLine();
        $this->line('Done.');

        return self::SUCCESS;
    }

    private function resolveElementsValue(): string
    {
        $value = $this->option('value');

        if (! is_string($value) || $value === '') {
            return '- - -';
        }

        return $value;
    }
}
