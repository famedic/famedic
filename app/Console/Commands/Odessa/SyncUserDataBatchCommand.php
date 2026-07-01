<?php

namespace App\Console\Commands\Odessa;

use App\Actions\Odessa\SyncOdessaUserDataAction;
use App\DTOs\SyncOdessaUserDataResult;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Exceptions\OdessaUserDataSyncMismatchException;
use App\Models\OdessaAfiliateAccount;
use App\Support\Odessa\OdessaApiErrorFormatter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class SyncUserDataBatchCommand extends Command
{
    protected $signature = 'odessa:sync-user-data-batch
                            {--dry-run : Consulta Odessa y reporta cambios sin persistir}
                            {--force : Sincronizar aunque IdOdessa o IdExterno no coincidan}
                            {--limit=25 : Máximo de cuentas a procesar}
                            {--only-missing : Solo cuentas sin client_id, empresa, nombre, planta_id o partner_identifier}
                            {--from-id= : Procesar desde este odessa_afiliate_accounts.id}
                            {--sleep=0 : Segundos de espera entre llamadas a Odessa}
                            {--stop-on-error : Detener al primer error}
                            {--show-fields : Mostrar detalle de campos por cuenta}
                            {--allow-partial : Exit 0 si hubo al menos una cuenta exitosa, aunque otras fallen}';

    protected $description = 'Sincroniza en lote client_id, empresa, nombre, planta_id y partner_identifier desde getUserData de Odessa';

    public function handle(SyncOdessaUserDataAction $syncOdessaUserDataAction): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = max(1, (int) $this->option('limit'));
        $onlyMissing = (bool) $this->option('only-missing');
        $fromId = $this->option('from-id');
        $sleepSeconds = max(0, (int) $this->option('sleep'));
        $stopOnError = (bool) $this->option('stop-on-error');
        $allowPartial = (bool) $this->option('allow-partial');
        $verbose = (bool) $this->option('show-fields');

        $query = $this->buildAccountQuery($onlyMissing, $fromId);
        $totalCandidates = (clone $query)->count();
        $accounts = $this->accountsForBatch($onlyMissing, $fromId, $limit);

        if ($accounts->isEmpty()) {
            $this->info('No hay cuentas Odessa que coincidan con los filtros.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%sCandidatas: %d | Lote: %d%s%s',
            $dryRun ? '[DRY-RUN] ' : '',
            $totalCandidates,
            $accounts->count(),
            $onlyMissing ? ' | filtro: solo pendientes' : '',
            $fromId !== null && $fromId !== '' ? " | desde id {$fromId}" : '',
        ));
        $this->line('ODESSA_URL: '.(string) config('services.odessa.url'));
        $this->newLine();

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'dry_run_changes' => 0,
            'mismatch' => 0,
            'api_error' => 0,
            'other_error' => 0,
        ];

        $failures = [];
        $lastProcessedId = null;

        foreach ($accounts as $index => $account) {
            $stats['processed']++;
            $lastProcessedId = (int) $account->id;

            try {
                $result = $syncOdessaUserDataAction($account, $dryRun, $force);

                if ($result->hasChanges()) {
                    if ($dryRun) {
                        $stats['dry_run_changes']++;
                        $status = 'cambios';
                    } else {
                        $stats['updated']++;
                        $status = 'actualizada';
                    }
                } else {
                    $stats['unchanged']++;
                    $status = 'sin cambios';
                }

                $this->line(sprintf(
                    '[%s] cuenta #%d (odessa_identifier=%s)',
                    $status,
                    $account->id,
                    $account->odessa_identifier,
                ));

                if ($verbose && $result->hasChanges()) {
                    $this->printAttributeDiff($result);
                }
            } catch (OdessaUserDataSyncMismatchException $e) {
                $stats['mismatch']++;
                $message = $e->getMessage();
                $failures[] = $this->formatFailure($account, 'mismatch', $message);
                $this->warn(sprintf('[mismatch] cuenta #%d: %s', $account->id, $message));

                if ($stopOnError) {
                    $this->printSummary($stats, $dryRun, $failures, $lastProcessedId, $totalCandidates, $allowPartial);

                    return self::FAILURE;
                }
            } catch (OdessaGetUserDataFailedException $e) {
                $stats['api_error']++;
                $message = OdessaApiErrorFormatter::summarize($e->getMessage());
                $failures[] = $this->formatFailure($account, 'api_error', $message);
                $this->warn(sprintf('[api_error] cuenta #%d: %s', $account->id, $message));

                if ($stopOnError) {
                    $this->printSummary($stats, $dryRun, $failures, $lastProcessedId, $totalCandidates, $allowPartial);

                    return self::FAILURE;
                }
            } catch (Throwable $e) {
                $stats['other_error']++;
                $message = OdessaApiErrorFormatter::summarize($e->getMessage());
                $failures[] = $this->formatFailure($account, 'error', $message);
                $this->error(sprintf('[error] cuenta #%d: %s', $account->id, $message));

                if ($stopOnError) {
                    $this->printSummary($stats, $dryRun, $failures, $lastProcessedId, $totalCandidates, $allowPartial);

                    return self::FAILURE;
                }
            }

            if ($sleepSeconds > 0 && $index < $accounts->count() - 1) {
                sleep($sleepSeconds);
            }
        }

        $this->printSummary($stats, $dryRun, $failures, $lastProcessedId, $totalCandidates, $allowPartial);

        return $this->resolveExitCode($stats, $failures, $allowPartial);
    }

    /**
     * @param  array<string, int>  $stats
     * @param  list<array{id: int, odessa_identifier: string, type: string, message: string}>  $failures
     */
    protected function resolveExitCode(array $stats, array $failures, bool $allowPartial): int
    {
        if ($failures === []) {
            return self::SUCCESS;
        }

        $successful = $stats['updated'] + $stats['unchanged'] + $stats['dry_run_changes'];

        if ($allowPartial && $successful > 0) {
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * @return Collection<int, OdessaAfiliateAccount>
     */
    protected function accountsForBatch(bool $onlyMissing, mixed $fromId, int $limit): Collection
    {
        return $this->buildAccountQuery($onlyMissing, $fromId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function buildAccountQuery(bool $onlyMissing, mixed $fromId): Builder
    {
        return OdessaAfiliateAccount::query()
            ->when($onlyMissing, function (Builder $query) {
                $query->where(function (Builder $nested) {
                    $nested->whereNull('client_id')
                        ->orWhereNull('empresa')
                        ->orWhereNull('nombre')
                        ->orWhereNull('planta_id')
                        ->orWhereNull('partner_identifier');
                });
            })
            ->when($fromId !== null && $fromId !== '', fn (Builder $query) => $query->where('id', '>=', (int) $fromId));
    }

    private function printAttributeDiff(SyncOdessaUserDataResult $result): void
    {
        $rows = [];

        foreach (SyncOdessaUserDataAction::SYNCED_ATTRIBUTES as $attribute) {
            $previous = $result->previousAttributes[$attribute];
            $next = $result->newAttributes[$attribute];
            $rows[] = [
                $attribute,
                $previous ?? '—',
                $next ?? '—',
            ];
        }

        $this->table(['Campo', 'Actual', 'Odessa'], $rows);
    }

    /**
     * @param  array<string, int>  $stats
     * @param  list<array{id: int, odessa_identifier: string, type: string, message: string}>  $failures
     */
    private function printSummary(
        array $stats,
        bool $dryRun,
        array $failures,
        ?int $lastProcessedId,
        int $totalCandidates,
        bool $allowPartial = false,
    ): void {
        $this->newLine();
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->info($prefix.'Resumen batch:');
        $this->line('  Procesadas: '.$stats['processed']);
        $this->line('  Actualizadas: '.$stats['updated']);
        $this->line('  Sin cambios: '.$stats['unchanged']);
        $this->line('  Con cambios (dry-run): '.$stats['dry_run_changes']);
        $this->line('  Mismatch: '.$stats['mismatch']);
        $this->line('  Error API Odessa: '.$stats['api_error']);
        $this->line('  Otros errores: '.$stats['other_error']);

        if ($lastProcessedId !== null && $stats['processed'] < $totalCandidates) {
            $this->newLine();
            $this->line("Para continuar: php artisan odessa:sync-user-data-batch --only-missing --from-id=".($lastProcessedId + 1)." --limit=<N>".($dryRun ? ' --dry-run' : ''));
        }

        if ($failures !== []) {
            $this->newLine();
            $this->warn('Cuentas con error ('.count($failures).'):');
            $this->table(
                ['ID', 'odessa_identifier', 'Tipo', 'Detalle'],
                array_map(
                    fn (array $failure) => [
                        (string) $failure['id'],
                        $failure['odessa_identifier'],
                        $failure['type'],
                        $failure['message'],
                    ],
                    $failures,
                ),
            );

            $this->line('Nota: errores como "Socio inactivo." provienen de getToken/ y la cuenta se omite; el batch puede continuar.');
        }

        if ($allowPartial && $failures !== []) {
            $successful = $stats['updated'] + $stats['unchanged'] + $stats['dry_run_changes'];

            if ($successful > 0) {
                $this->newLine();
                $this->info("Éxito parcial permitido (--allow-partial): {$successful} cuenta(s) OK, ".count($failures).' con error. Exit code: 0.');
            }
        }
    }

    /**
     * @return array{id: int, odessa_identifier: string, type: string, message: string}
     */
    private function formatFailure(OdessaAfiliateAccount $account, string $type, string $message): array
    {
        return [
            'id' => (int) $account->id,
            'odessa_identifier' => (string) $account->odessa_identifier,
            'type' => $type,
            'message' => $message,
        ];
    }
}
