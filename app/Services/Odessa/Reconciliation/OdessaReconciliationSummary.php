<?php

namespace App\Services\Odessa\Reconciliation;

class OdessaReconciliationSummary
{
    /** @var array<string, int> */
    public array $matchTypes = [];

    /** @var array<string, int> */
    public array $statuses = [];

    public int $total = 0;

    public int $uniqueTotal = 0;

    public int $duplicates = 0;

    public int $found = 0;

    public int $emailOnlyWouldHaveMissed = 0;

    public int $withMembershipNumber = 0;

    public int $withActiveMembership = 0;

    public int $withExpiredMembership = 0;

    public int $withoutMembership = 0;

    /** @var array<string, int> */
    public array $membershipMetrics = [];

    /** @var array<string, int> */
    public array $emailMetrics = [];

    /** @var array<string, int> */
    public array $murguiaStatuses = [];

    /** @var array<string, int> */
    public array $murguiaAuditStatuses = [];

    /** @var array<string, int> */
    public array $auditReasons = [];

    /** @var array<string, int> */
    public array $sourceActions = [];

    /** @var array<string, int> */
    public array $sourceActionStatuses = [];

    /** @param list<OdessaReconciliationResult> $results */
    public static function fromResults(array $results): self
    {
        $summary = new self;
        $summary->total = count($results);
        $uniqueResults = array_values(array_filter($results, fn (OdessaReconciliationResult $result) => ! $result->source->isDuplicate));
        $summary->uniqueTotal = count($uniqueResults);
        $summary->duplicates = $summary->total - $summary->uniqueTotal;

        foreach ($uniqueResults as $result) {
            $summary->matchTypes[$result->matchType] = ($summary->matchTypes[$result->matchType] ?? 0) + 1;
            $summary->statuses[$result->status] = ($summary->statuses[$result->status] ?? 0) + 1;
            $summary->auditReasons[$result->auditReason ?? 'SIN_AUDIT_REASON'] = ($summary->auditReasons[$result->auditReason ?? 'SIN_AUDIT_REASON'] ?? 0) + 1;
            $row = $result->toArray();
            $summary->sourceActions[$row['source_action']] = ($summary->sourceActions[$row['source_action']] ?? 0) + 1;
            $summary->sourceActionStatuses[$row['source_action_status']] = ($summary->sourceActionStatuses[$row['source_action_status']] ?? 0) + 1;
            if ($result->murguiaStatus) {
                $summary->murguiaStatuses[$result->murguiaStatus] = ($summary->murguiaStatuses[$result->murguiaStatus] ?? 0) + 1;
            }
            if ($result->murguiaAuditStatus) {
                $summary->murguiaAuditStatuses[$result->murguiaAuditStatus] = ($summary->murguiaAuditStatuses[$result->murguiaAuditStatus] ?? 0) + 1;
            }

            if ($result->existsInFamedic) {
                $summary->found++;
            }

            if ($result->existsInFamedic
                && $result->source->email
                && $result->matched?->email_normalized !== $result->source->email
                && $result->matchType !== OdessaReconciliationMatchTypes::CONFIRMED_EMAIL) {
                $summary->emailOnlyWouldHaveMissed++;
            }

            if ($result->matched?->medical_attention_identifier) {
                $summary->withMembershipNumber++;
            }

            $summary->countMembershipMetrics($result);
            $summary->countEmailMetrics($result, $row);

            if ($result->matched?->subscription_status === 'ACTIVE') {
                $summary->withActiveMembership++;
            } elseif (in_array($result->matched?->subscription_status, ['EXPIRED', 'FUTURE', 'DELETED_ONLY'], true)) {
                $summary->withExpiredMembership++;
            }

            if ($result->existsInFamedic && (! $result->matched?->medical_attention_identifier || ! $result->matched?->subscription_id)) {
                $summary->withoutMembership++;
            }
        }

        ksort($summary->matchTypes);
        ksort($summary->statuses);
        ksort($summary->membershipMetrics);
        ksort($summary->emailMetrics);
        ksort($summary->murguiaStatuses);
        ksort($summary->murguiaAuditStatuses);
        ksort($summary->auditReasons);
        ksort($summary->sourceActions);
        ksort($summary->sourceActionStatuses);

        return $summary;
    }

    /** @return list<array{metric: string, value: int}> */
    public function rows(): array
    {
        $rows = [
            ['metric' => 'Colaboradores procesados', 'value' => $this->total],
            ['metric' => 'Personas únicas', 'value' => $this->uniqueTotal],
            ['metric' => 'Duplicados Excel', 'value' => $this->duplicates],
            ['metric' => 'Encontrados en FAMEDIC (únicos)', 'value' => $this->found],
            ['metric' => 'Recuperados que email-only habría marcado como no encontrados', 'value' => $this->emailOnlyWouldHaveMissed],
            ['metric' => 'Con número de membresía', 'value' => $this->withMembershipNumber],
            ['metric' => 'Con membresía activa', 'value' => $this->withActiveMembership],
            ['metric' => 'Con membresía vencida/inactiva', 'value' => $this->withExpiredMembership],
            ['metric' => 'Sin membresía', 'value' => $this->withoutMembership],
        ];

        foreach ($this->matchTypes as $key => $value) {
            $rows[] = ['metric' => 'Match: '.$key, 'value' => $value];
        }

        foreach ($this->statuses as $key => $value) {
            $rows[] = ['metric' => 'Estado: '.$key, 'value' => $value];
        }

        foreach ($this->membershipMetrics as $key => $value) {
            $rows[] = ['metric' => 'Membresía: '.$key, 'value' => $value];
        }

        foreach ($this->emailMetrics as $key => $value) {
            $rows[] = ['metric' => 'Email: '.$key, 'value' => $value];
        }

        foreach ($this->murguiaStatuses as $key => $value) {
            $rows[] = ['metric' => 'Murguía: '.$key, 'value' => $value];
        }

        foreach ($this->murguiaAuditStatuses as $key => $value) {
            $rows[] = ['metric' => 'Auditoría Murguía: '.$key, 'value' => $value];
        }

        foreach ($this->auditReasons as $key => $value) {
            $rows[] = ['metric' => 'Razón auditoría: '.$key, 'value' => $value];
        }

        foreach ($this->sourceActions as $key => $value) {
            $rows[] = ['metric' => 'Acción ODESSA: '.$key, 'value' => $value];
        }

        foreach ($this->sourceActionStatuses as $key => $value) {
            $rows[] = ['metric' => 'Estado acción: '.$key, 'value' => $value];
        }

        return $rows;
    }

    private function countMembershipMetrics(OdessaReconciliationResult $result): void
    {
        $m = $result->matched;
        if (! $result->existsInFamedic || ! $m) {
            return;
        }

        $keys = [];
        $keys[] = $m->medical_attention_identifier ? 'has_medical_attention_identifier' : 'missing_medical_attention_identifier';
        $keys[] = $m->subscription_id ? 'has_subscription' : 'missing_subscription';
        $keys[] = match ($m->subscription_status) {
            'ACTIVE' => 'has_active_subscription',
            'EXPIRED' => 'has_expired_subscription',
            'FUTURE' => 'has_future_subscription',
            'DELETED_ONLY' => 'has_only_deleted_subscription',
            default => 'missing_subscription',
        };

        if ($m->medical_attention_identifier && ! $m->subscription_id) {
            $keys[] = 'has_identifier_but_no_subscription';
        }
        if (! $m->medical_attention_identifier && $m->subscription_id) {
            $keys[] = 'has_subscription_but_no_identifier';
        }

        foreach (array_unique($keys) as $key) {
            $this->membershipMetrics[$key] = ($this->membershipMetrics[$key] ?? 0) + 1;
        }
    }

    private function countEmailMetrics(OdessaReconciliationResult $result, ?array $row = null): void
    {
        $row ??= $result->toArray();
        $emailStatus = $row['email_status'];
        $emailOnlyStatus = $row['email_only_status'];
        $this->emailMetrics[$emailStatus] = ($this->emailMetrics[$emailStatus] ?? 0) + 1;
        $this->emailMetrics[$emailOnlyStatus] = ($this->emailMetrics[$emailOnlyStatus] ?? 0) + 1;

        if ($emailStatus === 'email_different') {
            $key = 'email_different_'.$result->matchType;
            $this->emailMetrics[$key] = ($this->emailMetrics[$key] ?? 0) + 1;
        }
    }
}
