<?php

namespace App\Support\Odessa;

use App\Actions\Odessa\SyncOdessaUserDataAction;
use App\DTOs\SyncOdessaUserDataResult;

class OdessaUserDataSyncPresenter
{
    /** @var array<string, string> */
    public const ATTRIBUTE_LABELS = [
        'client_id' => 'Cliente ID',
        'empresa' => 'Empresa',
        'nombre' => 'Nombre',
        'planta_id' => 'Planta ID',
        'partner_identifier' => 'Socio ID',
    ];

    /**
     * @return array{
     *     previousAttributes: array<string, string|null>,
     *     newAttributes: array<string, string|null>,
     *     hasChanges: bool,
     *     diff: list<array{attribute: string, label: string, current: string|null, remote: string|null, status: string}>,
     *     userData: array<string, int|string|null|bool>
     * }
     */
    public static function fromResult(SyncOdessaUserDataResult $result): array
    {
        return [
            'previousAttributes' => $result->previousAttributes,
            'newAttributes' => $result->newAttributes,
            'hasChanges' => $result->hasChanges(),
            'diff' => self::buildDiff($result),
            'userData' => self::userDataToArray($result),
        ];
    }

    /**
     * @return list<array{attribute: string, label: string, current: string|null, remote: string|null, status: string}>
     */
    public static function buildDiff(SyncOdessaUserDataResult $result): array
    {
        $rows = [];

        foreach (SyncOdessaUserDataAction::SYNCED_ATTRIBUTES as $attribute) {
            $current = $result->previousAttributes[$attribute] ?? null;
            $remote = $result->newAttributes[$attribute] ?? null;

            $rows[] = [
                'attribute' => $attribute,
                'label' => self::ATTRIBUTE_LABELS[$attribute] ?? $attribute,
                'current' => $current,
                'remote' => $remote,
                'status' => $current === $remote ? 'unchanged' : 'update',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int|string|null|bool>
     */
    private static function userDataToArray(SyncOdessaUserDataResult $result): array
    {
        $userData = $result->userData;

        return [
            'idOdessa' => $userData->idOdessa,
            'idExterno' => $userData->idExterno,
            'clienteId' => $userData->clienteId,
            'empresa' => $userData->empresa,
            'nombre' => $userData->nombre,
            'socioId' => $userData->socioId,
            'plantaId' => $userData->plantaId,
            'paterno' => $userData->paterno,
            'materno' => $userData->materno,
            'autenticacionSso' => $userData->autenticacionSso,
        ];
    }
}
