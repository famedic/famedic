<?php

namespace App\Console\Commands;

use App\Services\Laboratory\LaboratoryPurchaseItemTestResolver;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * BLQ-A1-01: historical backfill of laboratory_purchase_items.laboratory_test_id.
 *
 * - Idempotent and batched: only rows WHERE laboratory_test_id IS NULL are read, and each write repeats that
 *   condition, so an already persisted id is never overwritten and a second run changes nothing new.
 * - Writes ONLY the RESOLUBLE class with a candidate id. AJ-BLQ-A1-01-A (IT-0035): no rule emits RESOLUBLE any more
 *   (dates plus catalog matches are not proof), so today the command writes nothing and works as a measurement:
 *   AMBIGUO (incl. TIMESTAMPS_NO_CONCLUYENTES) and SIN_FUENTE stay null (BLOCKED): no invented id, no 0, no product
 *   removed.
 * - --dry-run classifies without writing. Output is counts by class and reason only (no names or personal data);
 *   --report writes item_id,class,reason,candidate_id (internal ids only).
 * - Writes do not touch updated_at or any other column.
 */
class BackfillLaboratoryPurchaseItemTestIdsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'laboratory:backfill-purchase-item-test-ids
        {--dry-run : Classify only; write nothing}
        {--batch=500 : Rows per batch}
        {--report= : Optional CSV path with item_id,class,reason,candidate_id (internal ids only)}
        {--force : Skip the production confirmation}';

    protected $description = 'BLQ-A1-01: classify legacy laboratory_purchase_items without laboratory_test_id; writes only an independently proven id (none today)';

    public function handle(LaboratoryPurchaseItemTestResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) $this->option('batch'));
        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $report = null;
        if ($path = $this->option('report')) {
            $report = fopen($path, 'w');
            fputcsv($report, ['item_id', 'class', 'reason', 'candidate_id']);
        }

        $persistedBefore = DB::table('laboratory_purchase_items')->whereNotNull('laboratory_test_id')->count();
        $counts = [];
        $written = 0;
        $batches = 0;

        DB::table('laboratory_purchase_items as i')
            ->leftJoin('laboratory_purchases as p', 'p.id', '=', 'i.laboratory_purchase_id')
            ->whereNull('i.laboratory_test_id')
            ->select(['i.id', 'i.gda_id', 'i.name', 'i.created_at', 'i.laboratory_test_id', 'p.brand as order_brand'])
            ->orderBy('i.id')
            ->chunkById($batch, function ($rows) use ($resolver, $dryRun, $report, &$counts, &$written, &$batches) {
                $batches++;
                $candidates = $resolver->candidates($rows->map(fn ($r) => ['brand' => $r->order_brand, 'gda_id' => $r->gda_id]));
                foreach ($rows as $row) {
                    $result = $resolver->classify($row, $row->order_brand, $candidates);
                    $counts[$result['class']][$result['reason']] = ($counts[$result['class']][$result['reason']] ?? 0) + 1;
                    if ($report) {
                        fputcsv($report, [$row->id, $result['class'], $result['reason'], $result['laboratory_test_id'] ?? '']);
                    }
                    if (! $dryRun && $result['class'] === LaboratoryPurchaseItemTestResolver::RESOLUBLE && $result['laboratory_test_id'] !== null) {
                        $written += DB::table('laboratory_purchase_items')
                            ->where('id', $row->id)
                            ->whereNull('laboratory_test_id')
                            ->update(['laboratory_test_id' => $result['laboratory_test_id']]);
                    }
                }
            }, 'i.id', 'id');

        if ($report) {
            fclose($report);
        }

        $this->line('BLQ-A1-01 backfill '.($dryRun ? '(DRY-RUN: sin escrituras)' : '(ESCRITURA)'));
        $this->line('lotes='.$batches.' tamano_lote='.$batch);
        $this->line('YA_PERSISTIDO (no se tocan)='.$persistedBefore);
        foreach ([LaboratoryPurchaseItemTestResolver::RESOLUBLE, LaboratoryPurchaseItemTestResolver::AMBIGUO, LaboratoryPurchaseItemTestResolver::SIN_FUENTE] as $class) {
            $reasons = $counts[$class] ?? [];
            ksort($reasons);
            $this->line($class.'='.array_sum($reasons));
            foreach ($reasons as $reason => $n) {
                $this->line('  '.$class.'.'.$reason.'='.$n);
            }
        }
        $this->line('escritos='.$written);

        return self::SUCCESS;
    }
}
