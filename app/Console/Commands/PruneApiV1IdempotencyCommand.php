<?php

namespace App\Console\Commands;

use App\Models\Api\V1\IdempotencyRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prune expired API v1 idempotency records.
 * Default is dry-run; pass --force to delete. Separate from akubica:prune-otp.
 */
class PruneApiV1IdempotencyCommand extends Command
{
    protected $signature = 'akubica:prune-idempotency
                            {--dry-run : Count candidates without deleting (default when --force is absent)}
                            {--force : Actually delete expired rows}
                            {--batch= : Chunk size (default api_v1.idempotency.prune.default_batch)}';

    protected $description = 'Depura registros de idempotencia API v1 expirados (dry-run por defecto)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $explicitDryRun = (bool) $this->option('dry-run');

        if ($force && $explicitDryRun) {
            $this->error('No combine --force y --dry-run. Use uno u otro.');

            return self::FAILURE;
        }

        $dryRun = ! $force;

        $batchRaw = $this->option('batch');
        $batch = $batchRaw !== null && $batchRaw !== ''
            ? (int) $batchRaw
            : (int) config('api_v1.idempotency.prune.default_batch', 1000);

        if ($batch < 1 || $batch > 10000) {
            $this->error('Opcion --batch invalida. Use un entero entre 1 y 10000.');

            return self::FAILURE;
        }

        $started = hrtime(true);
        $deleted = 0;
        $errors = 0;

        try {
            if ($dryRun) {
                $deleted = (int) IdempotencyRecord::query()
                    ->where('expires_at', '<', now())
                    ->count();
            } else {
                do {
                    $ids = IdempotencyRecord::query()
                        ->where('expires_at', '<', now())
                        ->orderBy('id')
                        ->limit($batch)
                        ->pluck('id')
                        ->all();

                    if ($ids === []) {
                        break;
                    }

                    $chunk = IdempotencyRecord::query()->whereIn('id', $ids)->delete();
                    $deleted += (int) $chunk;
                } while (count($ids) === $batch);
            }
        } catch (Throwable $e) {
            $errors = 1;
            Log::error('akubica_idempotency_prune_failed', [
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 240),
                'environment' => app()->environment(),
            ]);
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $mode = $dryRun ? 'DRY-RUN' : 'FORCE';

        $this->info("akubica:prune-idempotency [{$mode}] batch={$batch}");
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['expired_records', (string) $deleted],
                ['duration_ms', (string) $durationMs],
                ['errors', (string) $errors],
            ]
        );

        Log::info('akubica_idempotency_prune_completed', [
            'dry_run' => $dryRun,
            'batch' => $batch,
            'expired_records' => $deleted,
            'duration_ms' => $durationMs,
            'error_count' => $errors,
            'environment' => app()->environment(),
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
