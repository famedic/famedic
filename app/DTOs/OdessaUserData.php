<?php

namespace App\DTOs;

class OdessaUserData
{
    public function __construct(
        public int $asociacionId,
        public int $empresaId,
        public int $socioId,
        public int $plantaId,
        public string $nombre,
        public string $paterno,
        public string $materno,
        public string $tipoTrab,
        public string $tipoPago,
        public int $idOdessa,
        public int $idExterno,
        public string $formaPago,
        public ?int $clienteId = null,
        public ?string $empresa = null,
        public ?bool $autenticacionSso = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            asociacionId: (int) $data['AsociacionId'],
            empresaId: (int) $data['EmpresaId'],
            socioId: (int) $data['SocioId'],
            plantaId: (int) $data['PlantaId'],
            nombre: (string) $data['Nombre'],
            paterno: (string) $data['Paterno'],
            materno: (string) $data['Materno'],
            tipoTrab: (string) ($data['TipoTrab'] ?? ''),
            tipoPago: (string) $data['TipoPago'],
            idOdessa: (int) $data['IdOdessa'],
            idExterno: (int) $data['IdExterno'],
            formaPago: (string) $data['FormaPago'],
            clienteId: self::resolveClienteId($data),
            empresa: isset($data['Empresa']) ? (string) $data['Empresa'] : null,
            autenticacionSso: isset($data['AutenticacionSSO']) ? (bool) $data['AutenticacionSSO'] : null,
        );
    }

    private static function resolveClienteId(array $data): ?int
    {
        if (array_key_exists('ClienteId', $data)) {
            return (int) $data['ClienteId'];
        }

        if (array_key_exists('ClienteID', $data)) {
            return (int) $data['ClienteID'];
        }

        return null;
    }
}
