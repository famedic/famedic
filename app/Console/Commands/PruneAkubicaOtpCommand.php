<?php

namespace App\Console\Commands;

use App\Services\Otp\AkubicaOtpPruningService;
use Illuminate\Console\Command;

/**
 * Prune terminal OTP rows past retention (P0-C2).
 * Default is dry-run; pass --force to delete. Scheduler uses --force when cleanup.enabled.
 * Does NOT prune personal_access_tokens (use sanctum:prune-expired).
 */
class PruneAkubicaOtpCommand extends Command
{
    protected $signature = 'akubica:prune-otp
                            {--dry-run : Count candidates without deleting (default when --force is absent)}
                            {--force : Actually delete eligible rows}
                            {--batch= : Chunk size (default otp.p0a.cleanup.default_batch)}
                            {--type=all : all|challenges|deliveries|rate-limits|grants|secure-links}';

    protected $description = 'Depura registros OTP terminales fuera de retencion (dry-run por defecto)';

    public function handle(AkubicaOtpPruningService $pruner): int
    {
        $type = strtolower(trim((string) $this->option('type')));
        if (! in_array($type, AkubicaOtpPruningService::TYPES, true)) {
            $this->error('Opcion --type invalida. Use: '.implode('|', AkubicaOtpPruningService::TYPES));

            return self::FAILURE;
        }

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
            : (int) config('otp.p0a.cleanup.default_batch', 1000);

        if ($batch < 1 || $batch > 10000) {
            $this->error('Opcion --batch invalida. Use un entero entre 1 y 10000.');

            return self::FAILURE;
        }

        $result = $pruner->prune($dryRun, $batch, $type);
        $counts = $result->counts();

        $mode = $result->dryRun ? 'DRY-RUN' : 'FORCE';
        $this->info("akubica:prune-otp [{$mode}] type={$result->type} batch={$result->batch}");
        $this->table(
            ['Categoria', 'Conteo'],
            [
                ['secure_download_links', (string) $counts['secure_download_links']],
                ['step_up_grants', (string) $counts['step_up_grants']],
                ['delivery_operations', (string) $counts['delivery_operations']],
                ['challenges', (string) $counts['challenges']],
                ['rate_limits', (string) $counts['rate_limits']],
            ]
        );

        $skipped = $result->skipped;
        if (($skipped['challenges_registration_intent'] ?? 0) > 0
            || ($skipped['challenges_remaining_grants'] ?? 0) > 0
            || ($skipped['grants_active_secure_links'] ?? 0) > 0
        ) {
            $this->warn(sprintf(
                'Omitidos: challenges_registration_intent=%d challenges_remaining_grants=%d grants_active_secure_links=%d',
                $skipped['challenges_registration_intent'] ?? 0,
                $skipped['challenges_remaining_grants'] ?? 0,
                $skipped['grants_active_secure_links'] ?? 0,
            ));
        }

        $this->line(sprintf(
            'Duracion: %d ms (bucket %s)',
            $result->durationMs,
            $result->durationBucket()
        ));

        if ($result->failed()) {
            $this->error(sprintf(
                'Prune completo con %d error(es) de entidad (sin detalle sensible).',
                count($result->errors)
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
