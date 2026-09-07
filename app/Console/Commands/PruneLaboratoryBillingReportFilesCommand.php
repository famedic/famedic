<?php

namespace App\Console\Commands;

use App\Models\LaboratoryBillingReportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneLaboratoryBillingReportFilesCommand extends Command
{
    protected $signature = 'laboratory-billing:prune-report-files';

    protected $description = 'Delete expired laboratory billing report files from private storage.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('famedic.laboratory_billing.report_file_retention_days', 14));

        LaboratoryBillingReportRun::query()
            ->whereNotNull('file_path')
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($runs) {
                foreach ($runs as $run) {
                    if ($run->file_disk && $run->file_path) {
                        Storage::disk($run->file_disk)->delete($run->file_path);
                    }

                    $run->update([
                        'file_path' => null,
                        'file_size' => null,
                        'link_expires_at' => null,
                    ]);
                }
            });

        $this->info('Expired laboratory billing report files pruned.');

        return self::SUCCESS;
    }
}
