<?php

namespace App\Console\Commands;

use App\Models\OdessaPreEnrollmentImportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOdessaPreEnrollmentImportRunsCommand extends Command
{
    protected $signature = 'odessa:prune-pre-enrollment-import-runs';

    protected $description = 'Prunes sanitized ODESSA pre-enrollment import run manifests.';

    public function handle(): int
    {
        $expired = 0;
        $deleted = 0;
        $retentionDays = max(1, (int) config('famedic.odessa_pre_enrollments.import_run_retention_days', 90));

        OdessaPreEnrollmentImportRun::query()
            ->where('status', OdessaPreEnrollmentImportRun::STATUS_PREVIEWED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($runs) use (&$expired): void {
                foreach ($runs as $run) {
                    DB::transaction(function () use ($run, &$expired): void {
                        $locked = OdessaPreEnrollmentImportRun::query()
                            ->whereKey($run->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $locked || $locked->status !== OdessaPreEnrollmentImportRun::STATUS_PREVIEWED) {
                            return;
                        }

                        $locked->rows()->delete();
                        $locked->forceFill([
                            'status' => OdessaPreEnrollmentImportRun::STATUS_EXPIRED,
                            'failure_code' => 'run_expired',
                            'source_file_hash' => null,
                            'row_hmac_key_encrypted' => null,
                        ])->save();

                        $expired++;
                    });
                }
            });

        OdessaPreEnrollmentImportRun::query()
            ->whereIn('status', [
                OdessaPreEnrollmentImportRun::STATUS_COMPLETED,
                OdessaPreEnrollmentImportRun::STATUS_FAILED,
                OdessaPreEnrollmentImportRun::STATUS_EXPIRED,
            ])
            ->where('updated_at', '<', now()->subDays($retentionDays))
            ->chunkById(100, function ($runs) use (&$deleted): void {
                foreach ($runs as $run) {
                    $run->delete();
                    $deleted++;
                }
            });

        $this->info(sprintf('ODESSA import runs pruned. expired=%d deleted=%d', $expired, $deleted));

        return self::SUCCESS;
    }
}
