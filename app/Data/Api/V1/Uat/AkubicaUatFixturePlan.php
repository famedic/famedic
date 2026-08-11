<?php

namespace App\Data\Api\V1\Uat;

use Carbon\CarbonImmutable;

final readonly class AkubicaUatFixturePlan
{
    /**
     * @param  array<string, string>  $configured
     * @param  array<string, array<string, int>>  $counts
     * @param  array<string, string>  $identityHashes
     * @param  array<string, string>  $storageHashes
     * @param  array<int, string>  $storagePaths
     * @param  array<string, string>  $idempotencyActorHashes
     */
    public function __construct(
        public string $namespace,
        public int $fixtureVersion,
        public string $action,
        public array $configured,
        public array $counts,
        public array $identityHashes,
        public array $storageHashes,
        public array $storagePaths,
        public array $idempotencyActorHashes,
        public CarbonImmutable $expiresAt,
    ) {
    }

    public function toSanitizedArray(): array
    {
        return [
            'action' => $this->action,
            'namespace' => $this->namespace,
            'fixture_version' => $this->fixtureVersion,
            'configured' => $this->configured,
            'counts' => $this->counts,
            'identity_hashes' => $this->identityHashes,
            'storage_hashes' => $this->storageHashes,
            'storage_allowlist' => $this->storagePaths,
            'idempotency_actor_hashes' => $this->idempotencyActorHashes,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'plan_hash' => $this->planHash(),
        ];
    }

    public function planHash(): string
    {
        $payload = [
            'namespace' => $this->namespace,
            'fixture_version' => $this->fixtureVersion,
            'action' => $this->action,
            'configured' => $this->configured,
            'counts' => $this->counts,
            'identity_hashes' => $this->identityHashes,
            'storage_hashes' => $this->storageHashes,
            'storage_allowlist' => $this->storagePaths,
            'idempotency_actor_hashes' => $this->idempotencyActorHashes,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
