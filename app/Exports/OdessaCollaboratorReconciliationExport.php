<?php

namespace App\Exports;

use App\Exports\Sheets\OdessaReconciliationArraySheet;
use App\Services\Odessa\Reconciliation\OdessaReconciliationMatchTypes;
use App\Services\Odessa\Reconciliation\OdessaReconciliationResult;
use App\Services\Odessa\Reconciliation\OdessaReconciliationStatuses;
use App\Services\Odessa\Reconciliation\OdessaReconciliationSummary;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class OdessaCollaboratorReconciliationExport implements WithMultipleSheets
{
    /**
     * @param  list<OdessaReconciliationResult>  $results
     */
    public function __construct(
        private readonly array $results,
        private readonly OdessaReconciliationSummary $summary,
        private readonly bool $includeMurguia = false,
    ) {}

    public function sheets(): array
    {
        $rows = array_map(fn (OdessaReconciliationResult $result) => $result->toArray(), $this->results);
        $headings = $this->collaboratorHeadings();

        return [
            $this->summarySheet(),
            $this->sheet('Detalle', $headings, $rows),
            $this->sheet('Todos', $headings, $rows),
            $this->sheet('Altas', $headings, $rows, fn ($row) => ($row['source_action'] ?? null) === 'ALTA'),
            $this->sheet('Bajas', $headings, $rows, fn ($row) => ($row['source_action'] ?? null) === 'BAJA'),
            $this->sheet('Coincidencias exactas', $headings, $rows, fn ($row) => $this->isExactMatch($row)),
            $this->sheet('Posibles coincidencias', $headings, $rows, fn ($row) => $this->isPossibleMatch($row)),
            $this->sheet('Sin coincidencia', $headings, $rows, fn ($row) => ($row['status'] ?? null) === OdessaReconciliationStatuses::NOT_FOUND),
            $this->sheet('Errores datos', $headings, $rows, fn ($row) => $this->hasDataError($row)),
            $this->sheet('Acciones disponibles', $headings, $rows, fn ($row) => $this->hasAvailableAction($row)),
            $this->sheet('Acciones bloqueadas', $headings, $rows, fn ($row) => ($row['source_action_status'] ?? null) === 'BLOCKED'),
            $this->sheet('Acciones ejecutadas', $headings, $rows, fn ($row) => $this->hasExecutedAction($row)),
            $this->sheet('Murguía comparación', $headings, $rows, fn ($row) => $this->includeMurguia && ($row['murguia_status'] ?? null)),
            $this->dictionarySheet(),
        ];
    }

    private function summarySheet(): OdessaReconciliationArraySheet
    {
        $rows = [
            ['Total filas', $this->summary->total],
            ['Colaboradores únicos', $this->summary->uniqueTotal],
            ['Coincidencias exactas', $this->sumKeys($this->summary->matchTypes, [
                OdessaReconciliationMatchTypes::CONFIRMED_ODESSA_ID,
                OdessaReconciliationMatchTypes::CONFIRMED_COMPANY_PARTNER,
                OdessaReconciliationMatchTypes::CONFIRMED_MEMBERSHIP,
                OdessaReconciliationMatchTypes::CONFIRMED_EMAIL,
            ])],
            ['Posibles coincidencias', $this->sumKeys($this->summary->matchTypes, [
                OdessaReconciliationMatchTypes::PROBABLE_IDENTITY,
                OdessaReconciliationMatchTypes::AMBIGUOUS,
                OdessaReconciliationMatchTypes::DELETED,
            ])],
            ['Sin coincidencia', $this->summary->statuses[OdessaReconciliationStatuses::NOT_FOUND] ?? 0],
            ['Altas ODESSA', $this->summary->sourceActions['ALTA'] ?? 0],
            ['Bajas ODESSA', $this->summary->sourceActions['BAJA'] ?? 0],
            ['Correo diferente', $this->summary->emailMetrics['email_different'] ?? 0],
            ['Sin membresía usable', ($this->summary->statuses[OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP] ?? 0) + ($this->summary->statuses[OdessaReconciliationStatuses::EXPIRED_MEMBERSHIP] ?? 0)],
            ['Incluye reporte Murguía', $this->includeMurguia ? 'Sí' : 'No'],
        ];

        return new OdessaReconciliationArraySheet('Resumen', ['Métrica', 'Valor'], $rows);
    }

    /** @return list<string> */
    private function collaboratorHeadings(): array
    {
        return [
            'Hoja origen',
            'Fila origen',
            '# de empresa',
            '# empleado',
            'Apellido Paterno',
            'Apellido Materno',
            'Nombre',
            'Fecha de nacimiento',
            'Email ODESSA',
            'Email FAMEDIC',
            'ID-ODESSA',
            'ID ODESSA FAMEDIC',
            'Otro correo / cuenta FAMEDIC',
            'No. membresía / noCredito',
            'Tipo de cuenta',
            'Tipo de membresía',
            'Estado membresía',
            'Membresía activa',
            'Fecha inicio membresía',
            'Fecha de vencimiento',
            'Acción ODESSA',
            'source_action_status',
            'match_status',
            'match_confidence',
            'Estatus conciliación',
            'murguia_status',
            'murguia_audit_status',
            'Acción sugerida',
            'Acción ejecutable',
            'Bloqueo',
            'Evidencia',
            'Alertas',
            'Candidatos',
            'Último resultado de acción',
            'Última fecha de acción',
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function sheet(string $title, array $headings, array $rows, ?callable $predicate = null): OdessaReconciliationArraySheet
    {
        $filtered = $predicate
            ? array_values(array_filter($rows, $predicate))
            : $rows;

        return new OdessaReconciliationArraySheet(
            $title,
            $headings,
            $this->collaboratorRows($filtered),
            array_map(fn (array $row) => $row['source_action'] ?? null, $filtered),
        );
    }

    /** @param list<array<string, mixed>> $rows @return list<list<mixed>> */
    private function collaboratorRows(array $rows): array
    {
        return array_map(fn (array $row) => [
            $row['source_sheet'] ?? null,
            $row['source_row'] ?? null,
            $row['company_excel'] ?? null,
            $row['employee_excel'] ?? null,
            $row['paternal_lastname'] ?? null,
            $row['maternal_lastname'] ?? null,
            $row['first_name'] ?? null,
            $this->excelDate($row['birth_date_excel'] ?? null),
            $row['email_excel'] ?? null,
            $row['email_db'] ?? null,
            $row['odessa_id_excel'] ?? null,
            $row['odessa_id_db'] ?? null,
            $this->famedicAccountText($row),
            $row['medical_attention_identifier'] ?? null,
            $row['account_type_label'] ?? null,
            $this->subscriptionTypeForExport($row),
            $this->membershipStatusForExport($row),
            $this->membershipActiveForExport($row),
            $this->excelDate($row['subscription_start_date'] ?? null),
            $this->excelDate($row['subscription_end_date'] ?? null),
            $row['source_action'] ?? null,
            $row['source_action_status'] ?? null,
            $row['match_type'] ?? null,
            $row['match_confidence'] ?? null,
            $row['status'] ?? null,
            $row['murguia_status'] ?? null,
            $row['murguia_audit_status'] ?? null,
            $this->suggestedAction($row),
            $this->actionExecutable($row),
            $row['source_action_blocked_reasons'] ?? null,
            $row['evidence'] ?? null,
            $row['data_quality_flags'] ?? null,
            $row['candidates'] ?? null,
            $row['last_murguia_log_status'] ?? null,
            $this->excelDate($row['last_murguia_log_date'] ?? null),
        ], $rows);
    }

    private function dictionarySheet(): OdessaReconciliationArraySheet
    {
        $rows = [
            ['Tipo de cuenta', 'Tipo de cuenta local del customer FAMEDIC: Odessa, Regular, Familiar o Certificado / convenio.'],
            ['Tipo de membresía', 'Tipo amigable de medical_attention_subscriptions.type. Para institutional se muestra Institucional Odessa; sin suscripción se muestra Ninguna.'],
            ['Estado membresía', 'Estado derivado desde medical_attention_subscriptions.start_date/end_date: Activa, Vencida, Futura, Sin membresía o Eliminada/Histórica.'],
            ['Membresía activa', 'Sí sólo cuando el estado derivado de membresía es ACTIVE.'],
            ['Fecha inicio membresía', 'Fecha start_date de la suscripción médica seleccionada para conciliación.'],
            ['Fecha de vencimiento', 'Fecha end_date de la suscripción médica seleccionada para conciliación.'],
            ['Acción ejecutable', 'Sí cuando source_action_status está pendiente de alta o baja; No en casos bloqueados, ya aplicados o sin acción.'],
            ['Bloqueo', 'Razones por las que una acción no se puede ejecutar con seguridad.'],
        ];

        return new OdessaReconciliationArraySheet('Diccionario columnas', ['Columna', 'Descripción'], $rows);
    }

    private function famedicAccountText(array $row): ?string
    {
        if (($row['exists_in_famedic'] ?? null) !== 'Sí') {
            return null;
        }

        return $row['email_db'] ?? $row['account_type_label'] ?? null;
    }

    private function subscriptionTypeForExport(array $row): string
    {
        $type = $row['subscription_type'] ?? null;
        if (! $type) {
            return 'Ninguna';
        }

        return match ($type) {
            'trial' => 'Trial',
            'regular' => 'Regular',
            'institutional' => 'Institucional Odessa',
            'family_member' => 'Miembro familiar',
            default => $row['subscription_type_label'] ?? $type,
        };
    }

    private function membershipStatusForExport(array $row): string
    {
        if (($row['exists_in_famedic'] ?? null) !== 'Sí') {
            return 'Sin registro';
        }

        return match ($row['subscription_status'] ?? null) {
            'ACTIVE' => 'Activa',
            'FUTURE' => 'Futura',
            'EXPIRED' => 'Vencida',
            'MISSING' => 'Sin membresía',
            'DELETED_ONLY' => 'Eliminada/Histórica',
            default => 'Sin registro',
        };
    }

    private function membershipActiveForExport(array $row): string
    {
        return ($row['subscription_status'] ?? null) === 'ACTIVE' ? 'Sí' : 'No';
    }

    private function suggestedAction(array $row): string
    {
        return match ($row['source_action'] ?? null) {
            'ALTA' => 'Alta Murguía individual',
            'BAJA' => 'Baja Murguía individual',
            default => 'No aplica',
        };
    }

    private function actionExecutable(array $row): string
    {
        return in_array($row['source_action_status'] ?? null, ['PENDING_ACTIVATION', 'PENDING_DEACTIVATION'], true)
            ? 'Sí'
            : 'No';
    }

    private function isExactMatch(array $row): bool
    {
        return in_array($row['match_type'] ?? null, [
            OdessaReconciliationMatchTypes::CONFIRMED_ODESSA_ID,
            OdessaReconciliationMatchTypes::CONFIRMED_COMPANY_PARTNER,
            OdessaReconciliationMatchTypes::CONFIRMED_MEMBERSHIP,
            OdessaReconciliationMatchTypes::CONFIRMED_EMAIL,
        ], true);
    }

    private function isPossibleMatch(array $row): bool
    {
        return in_array($row['match_type'] ?? null, [
            OdessaReconciliationMatchTypes::PROBABLE_IDENTITY,
            OdessaReconciliationMatchTypes::AMBIGUOUS,
            OdessaReconciliationMatchTypes::DELETED,
        ], true) || $this->hasDuplicateRisk($row);
    }

    private function hasDataError(array $row): bool
    {
        return ($row['source_action_status'] ?? null) === 'FAILED'
            || ($row['murguia_audit_status'] ?? null) === 'MURGUIA_SYNC_ERROR'
            || $this->flags($row) !== []
            || ! ($row['medical_attention_identifier'] ?? null);
    }

    private function hasAvailableAction(array $row): bool
    {
        return in_array($row['source_action_status'] ?? null, ['PENDING_ACTIVATION', 'PENDING_DEACTIVATION'], true);
    }

    private function hasExecutedAction(array $row): bool
    {
        return in_array($row['source_action_status'] ?? null, ['ACTIVATED', 'DEACTIVATED', 'ALREADY_ACTIVE', 'ALREADY_INACTIVE', 'COMPLETED'], true)
            || ($row['last_murguia_log_status'] ?? null) !== null;
    }

    private function hasDuplicateRisk(array $row): bool
    {
        return array_intersect($this->flags($row), [
            'POSSIBLE_DUPLICATE_PERSON',
            'POSSIBLE_EXISTING_USER',
            'DUPLICATE_ODESSA_ID',
            'DUPLICATE_COMPANY_PARTNER',
            'DUPLICATE_MEMBERSHIP_IDENTIFIER',
        ]) !== [];
    }

    /** @return list<string> */
    private function flags(array $row): array
    {
        return array_values(array_filter(array_map('trim', explode(';', (string) ($row['data_quality_flags'] ?? '')))));
    }

    private function sumKeys(array $values, array $keys): int
    {
        return array_sum(array_map(fn (string $key) => $values[$key] ?? 0, $keys));
    }

    private function excelDate(mixed $value): mixed
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof CarbonInterface || $value instanceof \DateTimeInterface) {
            return ExcelDate::PHPToExcel($value);
        }

        try {
            return ExcelDate::PHPToExcel(new \DateTimeImmutable((string) $value));
        } catch (\Throwable) {
            return $value;
        }
    }
}
