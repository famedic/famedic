<?php

namespace App\Services\Murguia;

use App\Models\Customer;
use App\Models\FamilyAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MurguiaReconciliationService
{
    public const ISSUE_MATCHED_OK = 'matched_ok';

    public const ISSUE_PROVIDER_ONLY = 'provider_only';

    public const ISSUE_LOCAL_ONLY = 'local_only';

    public const ISSUE_PROVIDER_ACTIVE_LOCAL_EXPIRED = 'provider_active_local_expired';

    public const ISSUE_LOCAL_ACTIVE_PROVIDER_INACTIVE = 'local_active_provider_inactive';

    public const ISSUE_DUPLICATE_CREDITO_IN_FILE = 'duplicate_credito_in_file';

    public const ISSUE_DUPLICATE_EMAIL_IN_FILE = 'duplicate_email_in_file';

    public const ISSUE_NAME_MISMATCH = 'name_mismatch';

    public const ISSUE_MEMBERSHIP_TYPE_MISMATCH = 'membership_type_mismatch';

    public const MAX_ISSUES_STORED = 5000;

    public function __construct(
        protected MurguiaProviderSpreadsheetReader $reader,
        protected MurguiaReportService $reportService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(UploadedFile $file): array
    {
        $providerRows = $this->reader->read($file);
        $filename = $file->getClientOriginalName();

        if ($providerRows === []) {
            return [
                'meta' => [
                    'filename' => $filename,
                    'uploaded_at' => now()->toIso8601String(),
                    'provider_rows' => 0,
                    'truncated' => false,
                ],
                'summary' => $this->emptySummary(),
                'issues' => [],
                'error' => 'El archivo no contiene filas de datos.',
            ];
        }

        $localCustomers = $this->loadLocalInsuredPopulation();
        $localByCredito = $localCustomers
            ->filter(fn (Customer $c) => filled($c->medical_attention_identifier))
            ->keyBy(fn (Customer $c) => $this->reader->normalizeCredito((string) $c->medical_attention_identifier));

        $localByEmail = $localCustomers
            ->filter(fn (Customer $c) => $c->user !== null && filled($c->user->email))
            ->keyBy(fn (Customer $c) => mb_strtolower(trim((string) $c->user->email)));

        $matchedLocalIds = [];
        $issues = [];

        $this->appendDuplicateFileIssues($providerRows, $issues);

        foreach ($providerRows as $providerRow) {
            $local = $this->matchLocalCustomer($providerRow, $localByCredito, $localByEmail);

            if (! $local) {
                $issues[] = $this->buildIssue(
                    self::ISSUE_PROVIDER_ONLY,
                    $providerRow,
                    null,
                    'Existe en archivo proveedor pero no en BD local (por noCredito ni email).'
                );

                continue;
            }

            $matchedLocalIds[$local->id] = true;
            $issueTypes = $this->detectMismatchIssues($providerRow, $local);

            if ($issueTypes === []) {
                $issues[] = $this->buildIssue(
                    self::ISSUE_MATCHED_OK,
                    $providerRow,
                    $local,
                    'Coincidencia sin diferencias detectadas.'
                );

                continue;
            }

            foreach ($issueTypes as $type => $observation) {
                $issues[] = $this->buildIssue($type, $providerRow, $local, $observation);
            }
        }

        foreach ($localCustomers as $local) {
            if (isset($matchedLocalIds[$local->id])) {
                continue;
            }

            $issues[] = $this->buildIssue(
                self::ISSUE_LOCAL_ONLY,
                null,
                $local,
                'Existe en BD local pero no en archivo proveedor.'
            );
        }

        $truncated = count($issues) > self::MAX_ISSUES_STORED;
        if ($truncated) {
            $issues = array_slice($issues, 0, self::MAX_ISSUES_STORED);
        }

        return [
            'meta' => [
                'filename' => $filename,
                'uploaded_at' => now()->toIso8601String(),
                'provider_rows' => count($providerRows),
                'local_insured_count' => $localCustomers->count(),
                'truncated' => $truncated,
                'detected_header_row' => $this->reader->detectedHeaderRow,
            ],
            'summary' => $this->summarizeIssues($issues),
            'issues' => array_values($issues),
        ];
    }

    /**
     * @return Collection<int, Customer>
     */
    private function loadLocalInsuredPopulation(): Collection
    {
        return Customer::query()
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->whereNotNull('medical_attention_identifier')
                        ->where('medical_attention_identifier', '!=', '');
                })->orWhereHas('medicalAttentionSubscriptions');
            })
            ->with($this->reportServiceEagerLoads())
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportServiceEagerLoads(): array
    {
        return [
            'user',
            'customerable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    FamilyAccount::class => ['parentCustomer.user'],
                ]);
            },
            'medicalAttentionSubscriptions' => fn ($q) => $q->orderByDesc('end_date'),
            'medicalAttentionSubscriptions.transactions' => fn ($q) => $q->orderByDesc('transactions.created_at'),
            'murguiaSyncLogs' => fn ($q) => $q->orderByDesc('id')->limit(5),
        ];
    }

    /**
     * @param  array<string, mixed>  $providerRow
     * @param  Collection<string, Customer>  $localByCredito
     * @param  Collection<string, Customer>  $localByEmail
     */
    private function matchLocalCustomer(
        array $providerRow,
        Collection $localByCredito,
        Collection $localByEmail
    ): ?Customer {
        $credito = $providerRow['medical_attention_identifier'] ?? null;
        if ($credito && $localByCredito->has($credito)) {
            return $localByCredito->get($credito);
        }

        $email = $providerRow['email'] ?? null;
        if ($email && $localByEmail->has($email)) {
            return $localByEmail->get($email);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $providerRow
     * @return array<string, string>
     */
    private function detectMismatchIssues(array $providerRow, Customer $local): array
    {
        $issues = [];

        $providerActive = $this->resolveProviderActive($providerRow);
        $localActive = (bool) $local->medical_attention_subscription_is_active;

        if ($providerActive === true && ! $localActive) {
            $issues[self::ISSUE_PROVIDER_ACTIVE_LOCAL_EXPIRED] =
                'Activo en proveedor pero vencido/inactivo localmente.';
        }

        if ($providerActive === false && $localActive) {
            $issues[self::ISSUE_LOCAL_ACTIVE_PROVIDER_INACTIVE] =
                'Activo localmente pero inactivo en proveedor.';
        }

        if ($this->hasNameMismatch($providerRow, $local)) {
            $issues[self::ISSUE_NAME_MISMATCH] = sprintf(
                'Diferencia de nombre: proveedor "%s" vs local "%s".',
                $providerRow['full_name'] ?? '—',
                $this->reportService->mapCustomerRow($local)['full_name'] ?? '—'
            );
        }

        if ($this->hasMembershipTypeMismatch($providerRow, $local)) {
            $localRow = $this->reportService->mapCustomerRow($local);
            $issues[self::ISSUE_MEMBERSHIP_TYPE_MISMATCH] = sprintf(
                'Diferencia de tipo membresía: proveedor "%s" vs local "%s".',
                $providerRow['provider_membership_type'] ?? '—',
                $localRow['subscription_type'] ?? '—'
            );
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $providerRows
     * @param  list<array<string, mixed>>  $issues
     */
    private function appendDuplicateFileIssues(array $providerRows, array &$issues): void
    {
        $byCredito = collect($providerRows)
            ->filter(fn ($r) => filled($r['medical_attention_identifier'] ?? null))
            ->groupBy('medical_attention_identifier');

        foreach ($byCredito as $credito => $rows) {
            if ($rows->count() <= 1) {
                continue;
            }

            foreach ($rows as $row) {
                $issues[] = $this->buildIssue(
                    self::ISSUE_DUPLICATE_CREDITO_IN_FILE,
                    $row,
                    null,
                    "noCredito duplicado en archivo: {$credito} ({$rows->count()} filas)."
                );
            }
        }

        $byEmail = collect($providerRows)
            ->filter(fn ($r) => filled($r['email'] ?? null))
            ->groupBy('email');

        foreach ($byEmail as $email => $rows) {
            if ($rows->count() <= 1) {
                continue;
            }

            foreach ($rows as $row) {
                $issues[] = $this->buildIssue(
                    self::ISSUE_DUPLICATE_EMAIL_IN_FILE,
                    $row,
                    null,
                    "Email duplicado en archivo: {$email} ({$rows->count()} filas)."
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $providerRow
     * @return array<string, mixed>
     */
    private function buildIssue(
        string $issueType,
        ?array $providerRow,
        ?Customer $local,
        string $observation
    ): array {
        return [
            'issue_type' => $issueType,
            'observation' => $observation,
            'provider' => $providerRow ? [
                'row_number' => $providerRow['row_number'] ?? null,
                'medical_attention_identifier' => $providerRow['medical_attention_identifier'] ?? null,
                'email' => $providerRow['email'] ?? null,
                'full_name' => $providerRow['full_name'] ?? null,
                'provider_status' => $providerRow['provider_status'] ?? null,
                'provider_membership_type' => $providerRow['provider_membership_type'] ?? null,
                'provider_expires_at' => $providerRow['provider_expires_at'] ?? null,
            ] : null,
            'local' => $local ? $this->compactLocalRow($local) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compactLocalRow(Customer $local): array
    {
        $row = $this->reportService->mapCustomerRow($local);

        return [
            'customer_id' => $row['customer_id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'medical_attention_identifier' => $row['medical_attention_identifier'],
            'account_type' => $row['account_type'],
            'subscription_type' => $row['subscription_type'],
            'local_status' => $row['local_status'],
            'subscription_end_date' => $row['subscription_end_date'],
            'murguia_sync_status' => $row['murguia_sync_status'],
            'reconciliation_notes' => $row['reconciliation_notes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $providerRow
     */
    private function resolveProviderActive(array $providerRow): ?bool
    {
        $fromStatus = $this->isProviderActive($providerRow['provider_status'] ?? null);
        if ($fromStatus !== null) {
            return $fromStatus;
        }

        $expires = $providerRow['provider_expires_at'] ?? null;
        if (! filled($expires)) {
            return null;
        }

        try {
            $expiresStr = trim((string) $expires);
            $date = preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $expiresStr)
                ? \Carbon\Carbon::createFromFormat('d/m/Y', $expiresStr)?->endOfDay()
                : \Carbon\Carbon::parse($expiresStr)->endOfDay();

            return $date?->gte(now());
        } catch (\Throwable) {
            return null;
        }
    }

    private function isProviderActive(?string $status): ?bool
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($status));

        if (in_array($normalized, [
            'activo', 'active', 'vigente', 'alta', '1', 'si', 'sí', 'yes', 'true',
        ], true)) {
            return true;
        }

        if (in_array($normalized, [
            'inactivo', 'inactive', 'baja', 'vencido', 'cancelado', '0', 'no', 'false',
        ], true)) {
            return false;
        }

        return null;
    }

    private function hasNameMismatch(array $providerRow, Customer $local): bool
    {
        $providerName = $providerRow['full_name'] ?? null;
        if (! filled($providerName)) {
            return false;
        }

        $localName = $this->reportService->mapCustomerRow($local)['full_name'] ?? '';

        return $this->normalizeName($providerName) !== $this->normalizeName((string) $localName);
    }

    private function hasMembershipTypeMismatch(array $providerRow, Customer $local): bool
    {
        $providerType = $providerRow['provider_membership_type'] ?? null;
        if (! filled($providerType)) {
            return false;
        }

        $localType = $this->reportService->mapCustomerRow($local)['subscription_type'] ?? 'none';
        $providerNorm = $this->normalizeMembershipType($providerType);
        $localNorm = $this->normalizeMembershipType($localType);

        if ($providerNorm === null || $localNorm === null) {
            return false;
        }

        return $providerNorm !== $localNorm;
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_replace('/\s+/', ' ', $name) ?? $name;
    }

    private function normalizeMembershipType(string $value): ?string
    {
        $v = mb_strtolower(trim($value));

        return match (true) {
            str_contains($v, 'trial') || str_contains($v, 'prueba') => 'trial',
            str_contains($v, 'instituc') || str_contains($v, 'odessa') => 'institutional',
            str_contains($v, 'familiar') || str_contains($v, 'family') => 'family_member',
            str_contains($v, 'regular') => 'regular',
            $v === 'none', $v === 'ninguna' => 'none',
            default => $v,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, int>
     */
    private function summarizeIssues(array $issues): array
    {
        $summary = $this->emptySummary();

        foreach ($issues as $issue) {
            $type = $issue['issue_type'] ?? 'unknown';
            if (! isset($summary[$type])) {
                $summary[$type] = 0;
            }
            $summary[$type]++;
        }

        $summary['total_issues'] = count($issues);

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        return [
            'total_issues' => 0,
            self::ISSUE_MATCHED_OK => 0,
            self::ISSUE_PROVIDER_ONLY => 0,
            self::ISSUE_LOCAL_ONLY => 0,
            self::ISSUE_PROVIDER_ACTIVE_LOCAL_EXPIRED => 0,
            self::ISSUE_LOCAL_ACTIVE_PROVIDER_INACTIVE => 0,
            self::ISSUE_DUPLICATE_CREDITO_IN_FILE => 0,
            self::ISSUE_DUPLICATE_EMAIL_IN_FILE => 0,
            self::ISSUE_NAME_MISMATCH => 0,
            self::ISSUE_MEMBERSHIP_TYPE_MISMATCH => 0,
        ];
    }
}
