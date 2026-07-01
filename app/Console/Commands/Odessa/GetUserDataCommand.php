<?php

namespace App\Console\Commands\Odessa;

use App\Actions\Odessa\GetUserDataAction;
use App\DTOs\OdessaUserData;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Models\OdessaAfiliateAccount;
use App\Support\Odessa\OdessaApiErrorFormatter;
use Illuminate\Console\Command;
use Throwable;

class GetUserDataCommand extends Command
{
    protected $signature = 'odessa:get-user-data
                            {odessa_afiliate_account_id : ID de odessa_afiliate_accounts}
                            {--json : Imprimir el resultado mapeado en JSON}';

    protected $description = 'Consulta manual getUserData de Odessa para validar la respuesta real de una cuenta afiliada existente';

    public function handle(GetUserDataAction $getUserDataAction): int
    {
        $accountId = (string) $this->argument('odessa_afiliate_account_id');
        $account = OdessaAfiliateAccount::with('customer.user')->find($accountId);

        if ($account === null) {
            $this->error("No se encontró OdessaAfiliateAccount con id: {$accountId}");

            return self::FAILURE;
        }

        $this->printAccountSummary($account);
        $this->newLine();
        $this->info('Consultando getUserData en Odessa...');

        try {
            $userDataList = $getUserDataAction($account);
        } catch (OdessaGetUserDataFailedException $e) {
            $this->error('Odessa respondió con error:');
            $this->printJsonIfPossible(OdessaApiErrorFormatter::summarize($e->getMessage()));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Error al consultar getUserData: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($userDataList === []) {
            $this->warn('Odessa respondió OK (intError=0) pero UserData está vacío.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Registros en UserData: %d', count($userDataList)));
        $this->newLine();

        if ($this->option('json')) {
            $this->line(json_encode(
                array_map(fn (OdessaUserData $userData) => $this->dtoToArray($userData), $userDataList),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->printUserDataTable($userDataList);
        }

        $this->newLine();
        $this->printValidationHints($account, $userDataList);

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

    /**
     * @param  OdessaUserData[]  $userDataList
     */
    private function printUserDataTable(array $userDataList): void
    {
        $this->table(
            [
                '#',
                'IdOdessa',
                'IdExterno',
                'ClienteId',
                'Nombre',
                'Paterno',
                'Materno',
                'Empresa',
                'EmpresaId',
                'PlantaId',
                'SocioId',
                'TipoTrab',
                'TipoPago',
                'FormaPago',
                'SSO',
            ],
            array_map(
                fn (OdessaUserData $userData, int $index) => [
                    (string) ($index + 1),
                    (string) $userData->idOdessa,
                    (string) $userData->idExterno,
                    $userData->clienteId !== null ? (string) $userData->clienteId : '—',
                    $userData->nombre,
                    $userData->paterno,
                    $userData->materno,
                    $userData->empresa ?? '—',
                    (string) $userData->empresaId,
                    (string) $userData->plantaId,
                    (string) $userData->socioId,
                    $userData->tipoTrab !== '' ? $userData->tipoTrab : '—',
                    $userData->tipoPago,
                    $userData->formaPago,
                    $userData->autenticacionSso === null ? '—' : ($userData->autenticacionSso ? 'true' : 'false'),
                ],
                $userDataList,
                array_keys($userDataList),
            ),
        );
    }

    /**
     * @param  OdessaUserData[]  $userDataList
     */
    private function printValidationHints(OdessaAfiliateAccount $account, array $userDataList): void
    {
        $this->info('Validación local vs Odessa:');

        foreach ($userDataList as $index => $userData) {
            $label = sprintf('Registro %d', $index + 1);

            $this->line(sprintf(
                '  %s — IdOdessa %s odessa_identifier (%s)',
                $label,
                $this->matchLabel((string) $userData->idOdessa, (string) $account->odessa_identifier),
                (string) $account->odessa_identifier,
            ));

            $this->line(sprintf(
                '  %s — IdExterno %s odessa_afiliate_accounts.id (%s)',
                $label,
                $this->matchLabel((string) $userData->idExterno, (string) $account->id),
                (string) $account->id,
            ));
        }
    }

    private function matchLabel(string $odessaValue, string $localValue): string
    {
        return $odessaValue === $localValue ? 'coincide con' : 'NO coincide con';
    }

    private function dtoToArray(OdessaUserData $userData): array
    {
        return [
            'asociacionId' => $userData->asociacionId,
            'empresaId' => $userData->empresaId,
            'socioId' => $userData->socioId,
            'plantaId' => $userData->plantaId,
            'clienteId' => $userData->clienteId,
            'empresa' => $userData->empresa,
            'nombre' => $userData->nombre,
            'paterno' => $userData->paterno,
            'materno' => $userData->materno,
            'tipoTrab' => $userData->tipoTrab,
            'tipoPago' => $userData->tipoPago,
            'idOdessa' => $userData->idOdessa,
            'idExterno' => $userData->idExterno,
            'formaPago' => $userData->formaPago,
            'autenticacionSso' => $userData->autenticacionSso,
        ];
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
