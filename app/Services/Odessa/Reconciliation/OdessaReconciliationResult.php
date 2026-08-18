<?php

namespace App\Services\Odessa\Reconciliation;

use App\Enums\MedicalSubscriptionType;
use App\Models\CertificateAccount;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;

class OdessaReconciliationResult
{
    public function __construct(
        public readonly OdessaCollaboratorSourceRow $source,
        public bool $existsInFamedic = false,
        public string $matchType = OdessaReconciliationMatchTypes::NONE,
        public string $matchConfidence = 'none',
        public ?object $matched = null,
        public string $status = OdessaReconciliationStatuses::NOT_FOUND,
        public array $evidence = [],
        public array $reviewNotes = [],
        public array $candidateSummaries = [],
        public ?bool $existsInMurguiaReport = null,
        public ?array $murguiaRow = null,
        public ?string $murguiaStatus = null,
        public ?string $murguiaAuditStatus = null,
        public ?object $lastMurguiaLog = null,
        public ?string $auditReason = null,
        public string $identityStatus = 'NO_MATCH',
        public string $accountStatus = 'NO_ACCOUNT',
        public string $membershipStatus = 'MISSING',
        public array $dataQualityFlags = [],
        public bool $activeMatchFound = false,
        public bool $softDeletedMatchFound = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $m = $this->matched;
        $sourceBirthDate = $this->source->birthDate?->toDateString();

        return [
            'source_sheet' => $this->source->sourceSheet,
            'source_row' => $this->source->sourceRow,
            'source_action' => $this->source->sourceAction,
            'source_action_color' => $this->source->sourceActionColor,
            'source_action_status' => $this->sourceActionStatus(),
            'source_action_blocked_reasons' => implode('; ', $this->sourceActionBlockedReasons()),
            'canonical_id' => $this->source->canonicalId,
            'duplicate_group_id' => $this->source->duplicateGroupId,
            'duplicate_count' => $this->source->duplicateCount,
            'is_duplicate' => $this->boolText($this->source->isDuplicate),
            'duplicate_reason' => $this->source->duplicateReason,
            'canonical_row' => $this->source->canonicalRow,
            'company_external_id' => $this->source->companyExternalId,
            'employee_number' => $this->source->employeeNumber,
            'company_excel' => $this->source->companyExternalId,
            'employee_excel' => $this->source->employeeNumber,
            'first_name' => $this->source->firstName,
            'paternal_lastname' => $this->source->paternalLastname,
            'maternal_lastname' => $this->source->maternalLastname,
            'name_excel' => $this->source->fullName(),
            'birth_date' => $sourceBirthDate,
            'birth_date_excel' => $sourceBirthDate,
            'email_excel' => $this->source->email,
            'odessa_id_excel' => $this->source->odessaId,
            'exists_in_famedic' => $this->existsInFamedic ? 'Sí' : 'No',
            'match_type' => $this->matchType,
            'match_confidence' => $this->matchConfidence,
            'user_id' => $m?->user_id,
            'customer_id' => $m?->customer_id,
            'odessa_account_id' => $m?->odessa_account_id,
            'email_db' => $m?->email,
            'odessa_id_db' => $m?->odessa_identifier,
            'name_db' => trim(implode(' ', array_filter([$m?->name, $m?->paternal_lastname, $m?->maternal_lastname]))),
            'birth_date_db' => $m?->birth_date,
            'company_internal_id' => $m?->company_internal_id,
            'company_external_id_db' => $m?->company_external_id_db,
            'partner_identifier_db' => $m?->partner_identifier,
            'customerable_type' => $m?->customerable_type,
            'account_type_label' => $m ? ($m->account_type_label ?? $this->accountTypeLabel($m->customerable_type ?? null)) : null,
            'medical_attention_identifier' => $m?->medical_attention_identifier,
            'subscription_id' => $m?->subscription_id,
            'subscription_type' => $m?->subscription_type,
            'subscription_type_label' => $m ? ($m->subscription_type_label ?? $this->subscriptionTypeLabel($m->subscription_type ?? null)) : null,
            'subscription_start_date' => $m?->subscription_start_date,
            'subscription_end_date' => $m?->subscription_end_date,
            'subscription_active' => $m ? ($m->subscription_active ? 'Sí' : 'No') : null,
            'subscription_status' => $m?->subscription_status,
            'subscription_status_label' => $m ? ($m->subscription_status_label ?? $this->subscriptionStatusLabel($m->subscription_status ?? null)) : 'Sin registro',
            'membership_active_label' => $m?->subscription_status === 'ACTIVE' ? 'Sí' : 'No',
            'subscription_count' => $m?->subscription_count,
            'synced_with_murguia_at' => $m?->synced_with_murguia_at,
            'active_match_found' => $this->boolText($this->activeMatchFound),
            'soft_deleted_match_found' => $this->boolText($this->softDeletedMatchFound),
            'user_deleted' => null,
            'customer_deleted' => $this->boolText($m ? $m->customer_deleted_at !== null : null),
            'odessa_account_deleted' => $this->boolText($m ? $m->odessa_deleted_at !== null : null),
            'subscription_deleted' => $this->boolText($m ? $m->subscription_deleted_at !== null || $m->has_only_deleted_subscription : null),
            'email_matches' => $this->boolText($this->emailMatches()),
            'email_status' => $this->emailStatus(),
            'odessa_id_matches' => $this->boolText($this->source->odessaId && (string) $m?->odessa_identifier === $this->source->odessaId),
            'company_matches' => $this->boolText($this->source->companyExternalId && (string) $m?->company_external_id_db === $this->source->companyExternalId),
            'partner_matches' => $this->boolText($this->source->employeeNumber && (string) $m?->partner_identifier === $this->source->employeeNumber),
            'birth_date_matches' => $this->boolText($sourceBirthDate && (string) $m?->birth_date === $sourceBirthDate),
            'birth_date_match' => $this->boolText($sourceBirthDate && (string) $m?->birth_date === $sourceBirthDate),
            'name_matches' => $this->boolText($this->fullNameMatches()),
            'name_match' => $this->boolText($this->partMatches($this->source->firstName, $m?->name)),
            'paternal_lastname_match' => $this->boolText($this->partMatches($this->source->paternalLastname, $m?->paternal_lastname)),
            'maternal_lastname_match' => $this->boolText($this->partMatches($this->source->maternalLastname, $m?->maternal_lastname)),
            'full_name_match' => $this->boolText($this->fullNameMatches()),
            'final_status' => $this->status,
            'status' => $this->status,
            'identity_status' => $this->identityStatus,
            'account_status' => $this->accountStatus,
            'membership_status' => $this->membershipStatus,
            'data_quality_flags' => implode('; ', $this->dataQualityFlags()),
            'audit_reason' => $this->auditReason,
            'review_notes' => implode('; ', array_merge($this->source->duplicateNotes, $this->reviewNotes)),
            'evidence' => implode('; ', $this->evidence),
            'candidate_count' => count($this->candidateSummaries),
            'candidates' => implode(' | ', $this->candidateSummaries),
            'exists_in_murguia_report' => $this->existsInMurguiaReport === null ? null : ($this->existsInMurguiaReport ? 'Sí' : 'No'),
            'murguia_status' => $this->murguiaStatus,
            'murguia_audit_status' => $this->murguiaAuditStatus,
            'last_murguia_log_id' => $this->lastMurguiaLog?->id,
            'last_murguia_log_action' => $this->lastMurguiaLog?->action,
            'last_murguia_log_status' => $this->lastMurguiaLog?->status,
            'last_murguia_log_email' => $this->lastMurguiaLog?->email,
            'last_murguia_log_message' => $this->lastMurguiaLog?->message,
            'last_murguia_log_date' => $this->lastMurguiaLog?->created_at,
            'email_only_status' => $this->emailOnlyStatus(),
        ];
    }

    private function accountTypeLabel(?string $customerableType): ?string
    {
        return match ($customerableType) {
            OdessaAfiliateAccount::class => 'Odessa',
            RegularAccount::class => 'Regular',
            FamilyAccount::class => 'Familiar',
            CertificateAccount::class => 'Certificado / convenio',
            null => null,
            default => class_basename($customerableType),
        };
    }

    private function subscriptionTypeLabel(?string $type): ?string
    {
        if (! $type) {
            return 'Ninguna';
        }

        if ($type === MedicalSubscriptionType::INSTITUTIONAL->value) {
            return 'Institucional Odessa';
        }

        return MedicalSubscriptionType::tryFrom($type)?->label() ?? $type;
    }

    private function subscriptionStatusLabel(?string $status): string
    {
        return match ($status) {
            'ACTIVE' => 'Activa',
            'FUTURE' => 'Futura',
            'EXPIRED' => 'Vencida',
            'MISSING' => 'Sin membresía',
            'DELETED_ONLY' => 'Eliminada/Histórica',
            default => 'Sin registro',
        };
    }

    private function boolText(?bool $value): ?string
    {
        return $value === null ? null : ($value ? 'Sí' : 'No');
    }

    private function fullNameMatches(): ?bool
    {
        if (! $this->matched) {
            return null;
        }

        return OdessaReconciliationNormalizer::comparableName($this->source->firstName, $this->source->paternalLastname, $this->source->maternalLastname)
            === OdessaReconciliationNormalizer::comparableName($this->matched->name, $this->matched->paternal_lastname, $this->matched->maternal_lastname);
    }

    private function partMatches(?string $source, ?string $db): ?bool
    {
        if (! $this->matched || (! $source && ! $db)) {
            return null;
        }

        return OdessaReconciliationNormalizer::comparableName($source)
            === OdessaReconciliationNormalizer::comparableName($db);
    }

    private function emailMatches(): ?bool
    {
        if (! $this->source->email && ! $this->matched?->email_normalized) {
            return null;
        }

        return $this->source->email !== null && $this->matched?->email_normalized === $this->source->email;
    }

    private function emailStatus(): string
    {
        if (! $this->source->email) {
            return 'email_missing_excel';
        }

        if (! $this->matched?->email_normalized) {
            return 'email_missing_db';
        }

        return $this->matched->email_normalized === $this->source->email ? 'email_same' : 'email_different';
    }

    private function emailOnlyStatus(): string
    {
        if (! $this->source->email) {
            return 'email_only_not_found';
        }

        if ($this->matchType === OdessaReconciliationMatchTypes::CONFIRMED_EMAIL) {
            return 'email_only_true_positive';
        }

        if ($this->existsInFamedic && $this->matched?->email_normalized !== $this->source->email) {
            return 'email_only_false_negative';
        }

        return $this->existsInFamedic ? 'would_email_only_find' : 'email_only_not_found';
    }

    /** @return list<string> */
    private function dataQualityFlags(): array
    {
        $flags = $this->dataQualityFlags;

        if ($this->source->duplicateCount > 1 && $this->source->duplicateReason) {
            $flags[] = $this->source->duplicateReason;
        }

        if ($this->source->sourceAction === OdessaCollaboratorExcelParser::ACTION_UNKNOWN) {
            $flags[] = 'UNKNOWN_SOURCE_ACTION_COLOR';
        }

        return array_values(array_unique($flags));
    }

    private function sourceActionStatus(): string
    {
        if ($this->source->sourceAction === OdessaCollaboratorExcelParser::ACTION_NONE) {
            return 'NO_ACTION';
        }

        if ($this->source->sourceAction === OdessaCollaboratorExcelParser::ACTION_UNKNOWN) {
            return 'BLOCKED';
        }

        if ($this->sourceActionBlockedReasons() !== []) {
            return 'BLOCKED';
        }

        if ($this->source->sourceAction === OdessaCollaboratorExcelParser::ACTION_ALTA) {
            if ($this->murguiaStatus === 'FAMEDIC_Y_MURGUIA' && $this->matched?->subscription_status === 'ACTIVE') {
                return 'ALREADY_ACTIVE';
            }

            return 'PENDING_ACTIVATION';
        }

        if ($this->source->sourceAction === OdessaCollaboratorExcelParser::ACTION_BAJA) {
            if ($this->murguiaStatus === 'FAMEDIC_NO_MURGUIA') {
                return 'ALREADY_INACTIVE';
            }

            return 'PENDING_DEACTIVATION';
        }

        return 'BLOCKED';
    }

    /** @return list<string> */
    private function sourceActionBlockedReasons(): array
    {
        if (! in_array($this->source->sourceAction, [
            OdessaCollaboratorExcelParser::ACTION_ALTA,
            OdessaCollaboratorExcelParser::ACTION_BAJA,
            OdessaCollaboratorExcelParser::ACTION_UNKNOWN,
        ], true)) {
            return [];
        }

        $reasons = [];
        $flags = $this->dataQualityFlags();

        if ($this->source->sourceAction === OdessaCollaboratorExcelParser::ACTION_UNKNOWN) {
            $reasons[] = 'MURGUIA_DATA_INSUFFICIENT';
        }

        if (! $this->existsInFamedic || ! $this->matched?->user_id) {
            $reasons[] = 'NO_FAMEDIC_MATCH';
        }

        if (! $this->matched?->customer_id) {
            $reasons[] = 'NO_CUSTOMER';
        }

        if (! $this->matched?->odessa_account_id) {
            $reasons[] = 'NO_ODESSA_ACCOUNT';
        }

        if (! $this->matched?->medical_attention_identifier) {
            $reasons[] = 'NO_CREDIT_NUMBER';
        }

        if ($this->identityStatus !== 'CONFIRMED') {
            $reasons[] = 'IDENTITY_NOT_CONFIRMED';
        }

        if (array_intersect($flags, [
            'POSSIBLE_DUPLICATE_PERSON',
            'POSSIBLE_EXISTING_USER',
            'DUPLICATE_ODESSA_ID',
            'DUPLICATE_COMPANY_PARTNER',
            'DUPLICATE_MEMBERSHIP_IDENTIFIER',
        ]) !== []) {
            $reasons[] = 'POSSIBLE_DUPLICATE';
        }

        return array_values(array_unique($reasons));
    }
}
