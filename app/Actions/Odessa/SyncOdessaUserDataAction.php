<?php

namespace App\Actions\Odessa;

use App\DTOs\OdessaUserData;
use App\DTOs\SyncOdessaUserDataResult;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Exceptions\OdessaUserDataSyncMismatchException;
use App\Models\OdessaAfiliateAccount;
use Exception;

class SyncOdessaUserDataAction
{
    /** @var list<string> */
    public const SYNCED_ATTRIBUTES = [
        'client_id',
        'empresa',
        'nombre',
        'planta_id',
        'partner_identifier',
    ];

    public function __construct(
        private GetUserDataAction $getUserDataAction,
    ) {}

    public function __invoke(
        OdessaAfiliateAccount $odessaAfiliateAccount,
        bool $dryRun = false,
        bool $force = false,
    ): SyncOdessaUserDataResult {
        try {
            $userDataList = ($this->getUserDataAction)($odessaAfiliateAccount);
        } catch (OdessaGetUserDataFailedException|OdessaUserDataSyncMismatchException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new OdessaGetUserDataFailedException($e->getMessage(), previous: $e);
        }

        if ($userDataList === []) {
            throw new OdessaGetUserDataFailedException(json_encode([
                'message' => 'Odessa respondió OK pero UserData está vacío.',
            ]));
        }

        $userData = $this->resolveUserDataRecord($odessaAfiliateAccount, $userDataList, $force);
        $newAttributes = $this->mapUserDataToAttributes($userData);
        $previousAttributes = $this->extractSyncedAttributes($odessaAfiliateAccount);

        if (! $dryRun) {
            $odessaAfiliateAccount->update($newAttributes);
            $odessaAfiliateAccount->refresh();
        }

        return new SyncOdessaUserDataResult(
            account: $odessaAfiliateAccount,
            userData: $userData,
            previousAttributes: $previousAttributes,
            newAttributes: $newAttributes,
            persisted: ! $dryRun,
        );
    }

    /**
     * @param  OdessaUserData[]  $userDataList
     */
    private function resolveUserDataRecord(
        OdessaAfiliateAccount $account,
        array $userDataList,
        bool $force,
    ): OdessaUserData {
        $userData = $this->findBestMatch($account, $userDataList);

        if ($force) {
            return $userData;
        }

        if ((string) $userData->idOdessa !== (string) $account->odessa_identifier) {
            throw new OdessaUserDataSyncMismatchException(sprintf(
                'IdOdessa (%s) no coincide con odessa_identifier (%s).',
                $userData->idOdessa,
                $account->odessa_identifier,
            ));
        }

        if ((string) $userData->idExterno !== (string) $account->id) {
            throw new OdessaUserDataSyncMismatchException(sprintf(
                'IdExterno (%s) no coincide con odessa_afiliate_accounts.id (%s).',
                $userData->idExterno,
                $account->id,
            ));
        }

        return $userData;
    }

    /**
     * @param  OdessaUserData[]  $userDataList
     */
    private function findBestMatch(OdessaAfiliateAccount $account, array $userDataList): OdessaUserData
    {
        $exactMatches = array_values(array_filter(
            $userDataList,
            fn (OdessaUserData $userData) => (string) $userData->idOdessa === (string) $account->odessa_identifier
                && (string) $userData->idExterno === (string) $account->id,
        ));

        if (count($exactMatches) === 1) {
            return $exactMatches[0];
        }

        $odessaMatches = array_values(array_filter(
            $userDataList,
            fn (OdessaUserData $userData) => (string) $userData->idOdessa === (string) $account->odessa_identifier,
        ));

        if (count($odessaMatches) === 1) {
            return $odessaMatches[0];
        }

        if (count($userDataList) === 1) {
            return $userDataList[0];
        }

        throw new OdessaUserDataSyncMismatchException(
            'No se pudo determinar un único registro UserData para la cuenta. Usa --force si deseas sincronizar el primer candidato.',
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function mapUserDataToAttributes(OdessaUserData $userData): array
    {
        return [
            'client_id' => $userData->clienteId !== null ? (string) $userData->clienteId : null,
            'empresa' => $userData->empresa,
            'nombre' => $userData->nombre,
            'planta_id' => (string) $userData->plantaId,
            'partner_identifier' => (string) $userData->socioId,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extractSyncedAttributes(OdessaAfiliateAccount $account): array
    {
        return array_map(
            fn (string $attribute) => $account->{$attribute} !== null ? (string) $account->{$attribute} : null,
            array_combine(self::SYNCED_ATTRIBUTES, self::SYNCED_ATTRIBUTES),
        );
    }
}
