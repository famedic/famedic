<?php

namespace App\Services\Odessa\Reconciliation;

use stdClass;

class OdessaCollaboratorMatcher
{
    public function __construct(
        private readonly OdessaReconciliationDbIndex $index,
    ) {}

    public function match(OdessaCollaboratorSourceRow $source): OdessaReconciliationResult
    {
        $result = new OdessaReconciliationResult($source);

        if ($source->odessaId) {
            $matched = $this->matchUnique($this->index->activeByOdessaId[$source->odessaId] ?? []);
            if ($matched instanceof stdClass) {
                return $this->confirmed($result, $matched, OdessaReconciliationMatchTypes::CONFIRMED_ODESSA_ID, 'alta', [
                    "Excel ID ODESSA {$source->odessaId} = DB ID ODESSA {$matched->odessa_identifier}",
                ]);
            }
            if ($matched === false) {
                return $this->ambiguous($result, $this->index->activeByOdessaId[$source->odessaId], 'ID ODESSA duplicado en DB.', 'DUPLICATE_ODESSA_ID');
            }
            if (($this->index->trashedByOdessaId[$source->odessaId] ?? []) !== []) {
                return $this->deleted($result, $this->index->trashedByOdessaId[$source->odessaId], 'ONLY_SOFT_DELETED_MATCH', 'ID ODESSA encontrado solo en registros eliminados.');
            }
        }

        if ($source->companyExternalId && $source->employeeNumber) {
            $key = $source->companyExternalId.'|'.$source->employeeNumber;
            $matched = $this->matchUnique($this->index->activeByCompanyPartner[$key] ?? []);
            if ($matched instanceof stdClass) {
                return $this->confirmed($result, $matched, OdessaReconciliationMatchTypes::CONFIRMED_COMPANY_PARTNER, 'alta', [
                    "Excel empresa {$source->companyExternalId} = DB empresa externa {$matched->company_external_id_db}",
                    "Excel socio {$source->employeeNumber} = DB socio {$matched->partner_identifier}",
                ]);
            }
            if ($matched === false) {
                return $this->ambiguous($result, $this->index->activeByCompanyPartner[$key], 'Empresa + socio duplicado en DB.', 'DUPLICATE_COMPANY_PARTNER');
            }
            if (($this->index->trashedByCompanyPartner[$key] ?? []) !== []) {
                return $this->deleted($result, $this->index->trashedByCompanyPartner[$key], 'ONLY_SOFT_DELETED_MATCH', 'Empresa + socio encontrado solo en registros eliminados.');
            }
        }

        if ($source->membershipIdentifier) {
            $matched = $this->matchUnique($this->index->activeByMembership[$source->membershipIdentifier] ?? []);
            if ($matched instanceof stdClass) {
                return $this->confirmed($result, $matched, OdessaReconciliationMatchTypes::CONFIRMED_MEMBERSHIP, 'alta', [
                    "Excel membresía {$source->membershipIdentifier} = DB membresía {$matched->medical_attention_identifier}",
                ]);
            }
            if ($matched === false) {
                return $this->ambiguous($result, $this->index->activeByMembership[$source->membershipIdentifier], 'Número de membresía duplicado en DB.', 'DUPLICATE_MEMBERSHIP_IDENTIFIER');
            }
        }

        if ($source->email) {
            $matched = $this->matchUnique($this->index->activeByEmail[$source->email] ?? []);
            if ($matched instanceof stdClass) {
                return $this->confirmed($result, $matched, OdessaReconciliationMatchTypes::CONFIRMED_EMAIL, 'media', [
                    "Excel email {$source->email} = DB email {$matched->email}",
                ]);
            }
            if ($matched === false) {
                return $this->ambiguous($result, $this->index->activeByEmail[$source->email], 'Email duplicado en DB.', 'POSSIBLE_EXISTING_USER');
            }
            if (($this->index->trashedByEmail[$source->email] ?? []) !== []) {
                return $this->deleted($result, $this->index->trashedByEmail[$source->email], 'ONLY_SOFT_DELETED_MATCH', 'Email encontrado solo en registros eliminados.');
            }
        }

        $identityKey = $source->identityKey();
        if ($identityKey) {
            $matched = $this->matchUnique($this->index->activeByIdentity[$identityKey] ?? []);
            if ($matched instanceof stdClass) {
                $result->existsInFamedic = true;
                $result->activeMatchFound = true;
                $result->matched = $matched;
                $result->matchType = OdessaReconciliationMatchTypes::PROBABLE_IDENTITY;
                $result->matchConfidence = 'probable';
                $result->status = OdessaReconciliationStatuses::MANUAL_REVIEW;
                $result->identityStatus = 'PROBABLE_IDENTITY';
                $result->auditReason = 'IDENTITY_CANDIDATE_FOUND';
                $result->evidence[] = 'Nombre + apellidos + fecha de nacimiento coinciden.';
                $result->reviewNotes[] = 'Match probable por identidad; requiere confirmación manual por falta de identificador fuerte.';
                $this->appendComparisonEvidence($result);
                $this->attachMurguiaLog($result);

                return $result;
            }
            if ($matched === false) {
                $result = $this->ambiguous($result, $this->index->activeByIdentity[$identityKey], 'Identidad coincide con múltiples usuarios.');
                $result->auditReason = 'MULTIPLE_IDENTITY_CANDIDATES';
                $result->dataQualityFlags[] = 'POSSIBLE_DUPLICATE_PERSON';

                return $result;
            }
            if (($this->index->trashedByIdentity[$identityKey] ?? []) !== []) {
                return $this->deleted($result, $this->index->trashedByIdentity[$identityKey], 'ONLY_SOFT_DELETED_MATCH', 'Identidad encontrada solo en registros eliminados.');
            }
        }

        $looseIdentityKey = $source->looseIdentityKey();
        if ($looseIdentityKey) {
            $candidates = $this->index->activeByLooseIdentity[$looseIdentityKey] ?? [];
            if (count($candidates) === 1) {
                $result->candidateSummaries = $this->summaries($candidates);
                $result->auditReason = 'IDENTITY_CANDIDATE_FOUND';
                $result->reviewNotes[] = 'Hay candidato por nombre sin fecha de nacimiento, no se eleva a match.';
            } elseif (count($candidates) > 1) {
                $result->candidateSummaries = $this->summaries($candidates);
                $result->auditReason = 'MULTIPLE_IDENTITY_CANDIDATES';
                $result->reviewNotes[] = 'Hay múltiples candidatos por nombre sin fecha de nacimiento, no se eleva a match.';
                $result->dataQualityFlags[] = 'POSSIBLE_DUPLICATE_PERSON';
            } elseif (count($candidates) === 1 && ! $result->existsInFamedic) {
                $result->dataQualityFlags[] = 'POSSIBLE_EXISTING_USER';
            }
        }

        $result->status = OdessaReconciliationStatuses::NOT_FOUND;
        $result->auditReason ??= $this->notFoundReason($source);
        $result->reviewNotes[] = 'No se encontró coincidencia activa por ID ODESSA, empresa+socio, membresía, email ni identidad fuerte.';
        $this->attachMurguiaHistory($result);

        return $result;
    }

    /** @param list<stdClass> $candidates */
    private function matchUnique(array $candidates): stdClass|bool|null
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = ($candidate->customer_id ?? 'user').':'.$candidate->user_id.':'.($candidate->odessa_account_id ?? 'no-odessa');
            $unique[$key] = $candidate;
        }

        if (count($unique) === 1) {
            return array_values($unique)[0];
        }

        return count($unique) > 1 ? false : null;
    }

    /** @param list<string> $evidence */
    private function confirmed(OdessaReconciliationResult $result, stdClass $matched, string $matchType, string $confidence, array $evidence): OdessaReconciliationResult
    {
        $result->existsInFamedic = true;
        $result->activeMatchFound = true;
        $result->matched = $matched;
        $result->matchType = $matchType;
        $result->matchConfidence = $confidence;
        $result->evidence = array_merge($result->evidence, $evidence);

        $this->appendComparisonEvidence($result);
        $this->resolveStatus($result);
        $this->attachMurguiaLog($result);

        return $result;
    }

    private function resolveStatus(OdessaReconciliationResult $result): void
    {
        $m = $result->matched;

        if (! $m?->customer_id || $m->customerable_type !== 'App\\Models\\OdessaAfiliateAccount') {
            $result->status = OdessaReconciliationStatuses::USER_WITHOUT_ODESSA;
            $result->accountStatus = 'NON_ODESSA_CUSTOMER';
            $result->identityStatus = 'MATCHED_NON_ODESSA';
            $result->reviewNotes[] = 'Existe usuario/customer, pero no tiene relación ODESSA activa.';

            return;
        }

        $result->accountStatus = 'ODESSA_ACTIVE';
        $result->identityStatus = 'CONFIRMED';
        $result->membershipStatus = $m->subscription_status ?? 'MISSING';

        if (! $m->medical_attention_identifier && ! $m->subscription_id) {
            $result->status = OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP;
            $result->reviewNotes[] = 'Afiliado ODESSA sin número de membresía y sin suscripción médica.';

            return;
        }

        if (! $m->medical_attention_identifier) {
            $result->status = OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP;
            $result->dataQualityFlags[] = 'SUBSCRIPTION_WITHOUT_IDENTIFIER';
            $result->reviewNotes[] = 'Tiene suscripción médica, pero no tiene número de membresía/noCredito.';

            return;
        }

        if (! $m->subscription_id || $m->subscription_status === 'DELETED_ONLY') {
            $result->status = OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP;
            $result->dataQualityFlags[] = 'IDENTIFIER_WITHOUT_SUBSCRIPTION';
            $result->reviewNotes[] = 'Tiene número de membresía/noCredito, pero no tiene suscripción médica activa o no eliminada.';

            return;
        }

        if ($m->subscription_status !== 'ACTIVE') {
            $result->status = OdessaReconciliationStatuses::EXPIRED_MEMBERSHIP;
            $result->reviewNotes[] = 'Tiene membresía/suscripción, pero no está vigente al momento de ejecutar.';

            return;
        }

        if ($result->source->email && $m->email_normalized !== $result->source->email) {
            $result->dataQualityFlags[] = 'EMAIL_DIFFERENT';
            $result->reviewNotes[] = 'El correo del Excel es diferente del correo actual en FAMEDIC.';
        }

        $result->status = OdessaReconciliationStatuses::COMPLETE;
    }

    private function appendComparisonEvidence(OdessaReconciliationResult $result): void
    {
        $m = $result->matched;
        if (! $m) {
            return;
        }

        if ($result->source->companyExternalId && $m->company_external_id_db) {
            $result->evidence[] = "Empresa: Excel {$result->source->companyExternalId} / DB {$m->company_external_id_db}";
        }
        if ($result->source->employeeNumber && $m->partner_identifier) {
            $result->evidence[] = "Socio: Excel {$result->source->employeeNumber} / DB {$m->partner_identifier}";
        }
        if ($result->source->email && $m->email_normalized !== $result->source->email) {
            $result->evidence[] = "Email diferente: Excel {$result->source->email} / DB {$m->email}";
            $result->dataQualityFlags[] = 'EMAIL_DIFFERENT';
        }
        if ($result->source->birthDate && (string) $m->birth_date !== $result->source->birthDate->toDateString()) {
            $result->reviewNotes[] = "Fecha de nacimiento diferente: Excel {$result->source->birthDate->toDateString()} / DB {$m->birth_date}";
            $result->dataQualityFlags[] = 'BIRTH_DATE_DIFFERENT';
        }

        $sourceName = OdessaReconciliationNormalizer::comparableName($result->source->firstName, $result->source->paternalLastname, $result->source->maternalLastname);
        $dbName = OdessaReconciliationNormalizer::comparableName($m->name, $m->paternal_lastname, $m->maternal_lastname);
        if ($sourceName !== '' && $dbName !== '' && $sourceName !== $dbName) {
            $result->reviewNotes[] = 'Nombre Excel diferente del nombre en DB.';
            $result->dataQualityFlags[] = 'NAME_DIFFERENT';
        }

        if (in_array('NAME_DIFFERENT', $result->dataQualityFlags, true)
            && in_array('BIRTH_DATE_DIFFERENT', $result->dataQualityFlags, true)
            && $result->matchType === OdessaReconciliationMatchTypes::CONFIRMED_ODESSA_ID) {
            $result->dataQualityFlags[] = 'DISCREPANCIA_IDENTITY';
            $result->auditReason = 'DISCREPANCIA_IDENTITY';
        }
    }

    /** @param list<stdClass> $candidates */
    private function ambiguous(OdessaReconciliationResult $result, array $candidates, string $note, ?string $flag = null): OdessaReconciliationResult
    {
        $result->matchType = OdessaReconciliationMatchTypes::AMBIGUOUS;
        $result->matchConfidence = 'ambigua';
        $result->status = OdessaReconciliationStatuses::DISCREPANCY;
        $result->auditReason ??= 'MULTIPLE_IDENTITY_CANDIDATES';
        $result->reviewNotes[] = $note;
        $result->candidateSummaries = $this->summaries($candidates);
        if ($flag) {
            $result->dataQualityFlags[] = $flag;
        }

        return $result;
    }

    /** @param list<stdClass> $candidates */
    private function deleted(OdessaReconciliationResult $result, array $candidates, string $reason, string $note): OdessaReconciliationResult
    {
        $result->matchType = OdessaReconciliationMatchTypes::DELETED;
        $result->matchConfidence = 'eliminado';
        $result->status = OdessaReconciliationStatuses::DELETED_RECORD;
        $result->softDeletedMatchFound = true;
        $result->auditReason = $reason;
        $result->reviewNotes[] = $note;
        $result->candidateSummaries = $this->summaries($candidates);
        $result->matched = $candidates[0] ?? null;
        $this->attachMurguiaLog($result);

        return $result;
    }

    private function notFoundReason(OdessaCollaboratorSourceRow $source): string
    {
        if ($source->companyExternalId && ! isset($this->index->companyInternalIdsByExternal[$source->companyExternalId])) {
            return 'COMPANY_NOT_FOUND';
        }

        if ($source->companyExternalId && $source->employeeNumber) {
            return 'COMPANY_PARTNER_NOT_FOUND';
        }

        if ($source->email) {
            return 'EMAIL_NOT_FOUND';
        }

        if ($source->odessaId) {
            return 'NO_ODESSA_ID_FOUND';
        }

        return 'NO_REFERENCE_FOUND';
    }

    private function attachMurguiaLog(OdessaReconciliationResult $result): void
    {
        if ($result->matched?->customer_id) {
            $result->lastMurguiaLog = $this->index->lastMurguiaLogByCustomerId[(int) $result->matched->customer_id] ?? null;
        }

        if (! $result->lastMurguiaLog && $result->matched?->medical_attention_identifier) {
            $result->lastMurguiaLog = $this->index->lastMurguiaLogByMembership[(string) $result->matched->medical_attention_identifier] ?? null;
        }

        if (! $result->lastMurguiaLog && $result->matched?->email_normalized) {
            $result->lastMurguiaLog = $this->index->lastMurguiaLogByEmail[$result->matched->email_normalized] ?? null;
        }
    }

    private function attachMurguiaHistory(OdessaReconciliationResult $result): void
    {
        if ($result->source->membershipIdentifier) {
            $result->lastMurguiaLog = $this->index->lastMurguiaLogByMembership[$result->source->membershipIdentifier] ?? null;
        }

        if (! $result->lastMurguiaLog && $result->source->email) {
            $result->lastMurguiaLog = $this->index->lastMurguiaLogByEmail[$result->source->email] ?? null;
        }

        if ($result->lastMurguiaLog) {
            $result->auditReason = 'ONLY_MURGUIA_HISTORY_FOUND';
            $result->reviewNotes[] = 'No existe match activo en FAMEDIC, pero hay historial en murguia_sync_logs.';
        }
    }

    /** @param list<stdClass> $candidates @return list<string> */
    private function summaries(array $candidates): array
    {
        return array_map(function (stdClass $candidate) {
            return sprintf(
                'user:%s customer:%s odessa_account:%s email:%s odessa:%s empresa:%s socio:%s',
                $candidate->user_id ?? '-',
                $candidate->customer_id ?? '-',
                $candidate->odessa_account_id ?? '-',
                $candidate->email ?? '-',
                $candidate->odessa_identifier ?? '-',
                $candidate->company_external_id_db ?? '-',
                $candidate->partner_identifier ?? '-',
            );
        }, $candidates);
    }
}
