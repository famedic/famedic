<?php

namespace App\Services\Odessa\Reconciliation;

use Carbon\CarbonImmutable;

class OdessaCollaboratorSourceRow
{
    public function __construct(
        public readonly string $sourceSheet,
        public readonly int $sourceRow,
        public readonly ?string $companyExternalId,
        public readonly ?string $employeeNumber,
        public readonly ?string $firstName,
        public readonly ?string $paternalLastname,
        public readonly ?string $maternalLastname,
        public readonly ?CarbonImmutable $birthDate,
        public readonly ?string $email,
        public readonly ?string $odessaId,
        public readonly ?string $membershipIdentifier = null,
        public readonly string $sourceAction = 'NONE',
        public readonly ?string $sourceActionColor = null,
        public readonly array $raw = [],
        public array $duplicateNotes = [],
        public ?string $duplicateGroupId = null,
        public bool $isDuplicate = false,
        public ?string $duplicateReason = null,
        public ?int $canonicalRow = null,
        public ?string $canonicalId = null,
        public int $duplicateCount = 1,
    ) {}

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->firstName,
            $this->paternalLastname,
            $this->maternalLastname,
        ])));
    }

    public function identityKey(): ?string
    {
        if (! $this->birthDate || $this->fullName() === '') {
            return null;
        }

        return OdessaReconciliationNormalizer::identityKey(
            $this->firstName,
            $this->paternalLastname,
            $this->maternalLastname,
            $this->birthDate->toDateString(),
        );
    }

    public function looseIdentityKey(): ?string
    {
        $name = OdessaReconciliationNormalizer::comparableName(
            $this->firstName,
            $this->paternalLastname,
            $this->maternalLastname,
        );

        return $name === '' ? null : $name;
    }
}
