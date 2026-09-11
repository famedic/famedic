<?php

namespace App\Services\Odessa\Reconciliation;

use App\Exports\OdessaCollaboratorReconciliationExport;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class OdessaReconciliationService
{
    public function __construct(
        private readonly OdessaCollaboratorExcelParser $parser,
    ) {}

    public function reconcile(string $path, ?string $murguiaPath = null, ?string $outputPath = null): OdessaReconciliationReport
    {
        $this->ensureExportMemoryLimit();

        $absolutePath = $this->absolutePath($path);
        $sourceRows = $this->parser->parse($absolutePath);
        if ($sourceRows === []) {
            throw new \InvalidArgumentException('El archivo no contiene las columnas necesarias para identificar colaboradores o no tiene filas válidas.');
        }

        $index = OdessaReconciliationDbIndex::build();
        $matcher = new OdessaCollaboratorMatcher($index);

        $results = array_map(fn (OdessaCollaboratorSourceRow $row) => $matcher->match($row), $sourceRows);

        if ($murguiaPath !== null) {
            $murguiaRows = $this->parser->parse($this->absolutePath($murguiaPath), includeFormatting: false);
            if ($murguiaRows === []) {
                throw new \InvalidArgumentException('El archivo Murguía no contiene columnas reconocibles o no tiene filas válidas.');
            }

            $this->attachMurguiaPresence($results, $murguiaRows);
        }

        $summary = OdessaReconciliationSummary::fromResults($results);
        $exportPath = $outputPath ?? 'reconciliation/odessa-collaborators-reconciliation-'.now()->format('Y-m-d_His').'.xlsx';

        Excel::store(
            new OdessaCollaboratorReconciliationExport($results, $summary, $murguiaPath !== null),
            $exportPath,
            'local',
        );

        return new OdessaReconciliationReport($absolutePath, $results, $summary, Storage::disk('local')->path($exportPath));
    }

    private function ensureExportMemoryLimit(): void
    {
        $current = ini_get('memory_limit');
        if ($current === false || $current === '-1') {
            return;
        }

        if (! preg_match('/^(\d+)([gmk])?$/i', trim($current), $matches)) {
            return;
        }

        $value = (int) $matches[1];
        $unit = mb_strtolower($matches[2] ?? 'm');
        $megabytes = match ($unit) {
            'g' => $value * 1024,
            'k' => (int) ceil($value / 1024),
            default => $value,
        };

        if ($megabytes < 256) {
            ini_set('memory_limit', '256M');
        }
    }

    private function absolutePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->path($path);
        }

        throw new \InvalidArgumentException("No existe el archivo: {$path}");
    }

    /**
     * @param  list<OdessaReconciliationResult>  $results
     * @param  list<OdessaCollaboratorSourceRow>  $murguiaRows
     */
    private function attachMurguiaPresence(array $results, array $murguiaRows): void
    {
        $byCompanyPartner = [];
        $byEmployee = [];
        $byIdentity = [];
        $byEmail = [];

        foreach ($murguiaRows as $row) {
            if ($row->companyExternalId && $row->employeeNumber) {
                $byCompanyPartner[$row->companyExternalId.'|'.$row->employeeNumber] = $row;
            }
            if ($row->employeeNumber) {
                $byEmployee[$row->employeeNumber] ??= $row;
            }
            if ($row->identityKey()) {
                $byIdentity[$row->identityKey()] = $row;
            }
            if ($row->email) {
                $byEmail[$row->email] = $row;
            }
        }

        foreach ($results as $result) {
            $source = $result->source;
            $murguia = null;

            if ($source->companyExternalId && $source->employeeNumber) {
                $murguia = $byCompanyPartner[$source->companyExternalId.'|'.$source->employeeNumber] ?? null;
            }
            if (! $murguia && $source->employeeNumber) {
                $murguia = $byEmployee[$source->employeeNumber] ?? null;
            }
            if (! $murguia && $source->identityKey()) {
                $murguia = $byIdentity[$source->identityKey()] ?? null;
            }
            if (! $murguia && $source->email) {
                $murguia = $byEmail[$source->email] ?? null;
            }

            $result->existsInMurguiaReport = $murguia !== null;
            $result->murguiaRow = $murguia ? [
                'sheet' => $murguia->sourceSheet,
                'row' => $murguia->sourceRow,
                'email' => $murguia->email,
                'employee' => $murguia->employeeNumber,
            ] : null;

            $result->murguiaStatus = match (true) {
                $result->existsInFamedic && $murguia !== null => 'FAMEDIC_Y_MURGUIA',
                $result->existsInFamedic && $murguia === null => 'FAMEDIC_NO_MURGUIA',
                ! $result->existsInFamedic && $murguia !== null => 'MURGUIA_NO_FAMEDIC',
                default => null,
            };

            $result->murguiaAuditStatus = $this->murguiaAuditStatus($result);
        }
    }

    private function murguiaAuditStatus(OdessaReconciliationResult $result): ?string
    {
        if ($result->murguiaStatus === 'MURGUIA_NO_FAMEDIC') {
            return $result->lastMurguiaLog
                ? 'MURGUIA_HISTORY_ONLY'
                : 'MURGUIA_REPORT_NOT_FOUND_IN_FAMEDIC';
        }

        if ($result->murguiaStatus !== 'FAMEDIC_NO_MURGUIA') {
            return $result->lastMurguiaLog?->status === 'error' ? 'MURGUIA_SYNC_ERROR' : null;
        }

        if ($result->lastMurguiaLog?->status === 'error') {
            return 'MURGUIA_SYNC_ERROR';
        }

        if ($result->lastMurguiaLog) {
            return 'MURGUIA_HISTORY_ONLY';
        }

        return match ($result->matched?->subscription_status) {
            'ACTIVE' => 'FAMEDIC_ACTIVE_MEMBERSHIP_NOT_IN_MAY_REPORT',
            'EXPIRED', 'FUTURE' => 'FAMEDIC_EXPIRED_NOT_IN_MAY_REPORT',
            default => $result->matched?->synced_with_murguia_at
                ? 'FAMEDIC_NOT_SYNCED_RECENTLY'
                : 'FAMEDIC_WITHOUT_MEMBERSHIP_NOT_IN_MAY_REPORT',
        };
    }
}
