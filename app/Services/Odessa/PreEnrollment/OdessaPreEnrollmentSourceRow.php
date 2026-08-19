<?php

namespace App\Services\Odessa\PreEnrollment;

use App\Services\Odessa\Reconciliation\OdessaCollaboratorSourceRow;
use App\Services\Odessa\Reconciliation\OdessaReconciliationNormalizer;
use Carbon\CarbonImmutable;

class OdessaPreEnrollmentSourceRow
{
    public function __construct(
        public readonly string $sourceSheet,
        public readonly int $sourceRow,
        public readonly ?string $companyExternalIdentifier,
        public readonly ?string $employeeIdentifier,
        public readonly ?string $firstName,
        public readonly ?string $paternalLastName,
        public readonly ?string $maternalLastName,
        public readonly ?CarbonImmutable $birthDate,
        public readonly ?string $sourceEmail,
        public readonly ?string $odessaIdentifier,
        public readonly string $sourceAction,
        public readonly array $raw,
    ) {}

    public function identityKey(): ?string
    {
        if (! $this->birthDate || trim($this->fullName()) === '') {
            return null;
        }

        return OdessaReconciliationNormalizer::identityKey(
            $this->firstName,
            $this->paternalLastName,
            $this->maternalLastName,
            $this->birthDate->toDateString(),
        );
    }

    public function looseIdentityKey(): ?string
    {
        $name = OdessaReconciliationNormalizer::comparableName(
            $this->firstName,
            $this->paternalLastName,
            $this->maternalLastName,
        );

        return $name === '' ? null : $name;
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->firstName,
            $this->paternalLastName,
            $this->maternalLastName,
        ])));
    }

    public function toCollaboratorSourceRow(): OdessaCollaboratorSourceRow
    {
        return new OdessaCollaboratorSourceRow(
            sourceSheet: $this->sourceSheet,
            sourceRow: $this->sourceRow,
            companyExternalId: $this->companyExternalIdentifier,
            employeeNumber: $this->employeeIdentifier,
            firstName: $this->firstName,
            paternalLastname: $this->paternalLastName,
            maternalLastname: $this->maternalLastName,
            birthDate: $this->birthDate,
            email: $this->sourceEmail,
            odessaId: $this->odessaIdentifier,
            membershipIdentifier: null,
            sourceAction: $this->sourceAction,
            sourceActionColor: null,
            raw: $this->raw,
        );
    }
}
