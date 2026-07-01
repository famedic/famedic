<?php

namespace App\Console\Commands\Odessa;

use App\Actions\Odessa\SyncOdessaUserDataAction;
use App\DTOs\SyncOdessaUserDataResult;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Exceptions\OdessaUserDataSyncMismatchException;
use App\Models\OdessaAfiliateAccount;
use App\Support\Odessa\OdessaApiErrorFormatter;
use Illuminate\Console\Command;
use Throwable;

class SyncUserDataCommand extends Command
{
    protected $signature = 'odessa:sync-user-data
                            {odessa_afiliate_account_id : ID de odessa_afiliate_accounts}
                            {--dry-run : Consulta Odessa y muestra cambios sin persistir}
                            {--force : Sincronizar aunque IdOdessa o IdExterno no coincidan}';

    protected $description = 'Sincroniza manualmente client_id, empresa, nombre, planta_id y partner_identifier desde getUserData de Odessa';

    public function handle(SyncOdessaUserDataAction $syncOdessaUserDataAction): int
    {
        $accountId = (string) $this->argument('odessa_afiliate_account_id');
        $account = OdessaAfiliateAccount::with('customer.user')->find($accountId);

        if ($account === null) {
            $this->error("No se encontró OdessaAfiliateAccount con id: {$accountId}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->printAccountSummary($account);
        $this->newLine();
        $this->info($dryRun
            ? 'Consultando getUserData en Odessa (dry-run)...'
            : 'Sincronizando datos desde getUserData en Odessa...');

        try {
            $result = $syncOdessaUserDataAction($account, $dryRun, $force);
        } catch (OdessaUserDataSyncMismatchException $e) {
            $this->error('Validación de vínculo fallida: '.$e->getMessage());
            $this->line('Usa --force solo si confirmas que el registro UserData corresponde a esta cuenta.');

            return self::FAILURE;
        } catch (OdessaGetUserDataFailedException $e) {
            $this->error('Odessa respondió con error:');
            $this->line(OdessaApiErrorFormatter::summarize($e->getMessage()));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Error al sincronizar getUserData: '.OdessaApiErrorFormatter::summarize($e->getMessage()));

            return self::FAILURE;
        }

        $this->printUserDataSummary($result);
        $this->newLine();
        $this->printAttributeDiff($result);

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry-run: no se guardaron cambios en la base de datos.');

            return self::SUCCESS;
        }

        if (! $result->hasChanges()) {
            $this->newLine();
            $this->info('La cuenta ya tenía los mismos valores; no hubo cambios.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Sincronización completada.');

        return self::SUCCESS;
    }

    private function printAccountSummary(OdessaAfiliateAccount $account): void
    {
        $customer = $account->customer;
        $user = $customer?->user;

        $this->table(
            ['Campo', 'Valor'],
            [
                ['odessa_afiliate_accounts.id', (string) $account->id],
                ['odessa_identifier', (string) $account->odessa_identifier],
                ['customer_id', $customer ? (string) $customer->id : '—'],
                ['user_id', $user ? (string) $user->id : '—'],
                ['user_email', $user?->email ?? '—'],
                ['ODESSA_URL', (string) config('services.odessa.url')],
            ],
        );
    }

    private function printUserDataSummary(SyncOdessaUserDataResult $result): void
    {
        $userData = $result->userData;

        $this->table(
            ['Campo Odessa', 'Valor'],
            [
                ['IdOdessa', (string) $userData->idOdessa],
                ['IdExterno', (string) $userData->idExterno],
                ['ClienteId', $userData->clienteId !== null ? (string) $userData->clienteId : '—'],
                ['Empresa', $userData->empresa ?? '—'],
                ['Nombre', $userData->nombre],
                ['SocioId → partner_identifier', (string) $userData->socioId],
                ['PlantaId', (string) $userData->plantaId],
            ],
        );
    }

    private function printAttributeDiff(SyncOdessaUserDataResult $result): void
    {
        $this->info('Campos a sincronizar en odessa_afiliate_accounts:');

        $rows = [];

        foreach (SyncOdessaUserDataAction::SYNCED_ATTRIBUTES as $attribute) {
            $previous = $result->previousAttributes[$attribute];
            $next = $result->newAttributes[$attribute];
            $rows[] = [
                $attribute,
                $previous ?? '—',
                $next ?? '—',
                $previous === $next ? 'sin cambio' : 'actualizar',
            ];
        }

        $this->table(['Campo', 'Valor actual', 'Valor Odessa', 'Estado'], $rows);
    }

    private function printJsonIfPossible(string $message): void
    {
        $decoded = json_decode($message, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->line(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line($message);
    }
}
