<?php

namespace App\Exports;

use App\Models\OdessaPreEnrollment;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OdessaPreEnrollmentsExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping
{
    public function __construct(
        private readonly array $filters = [],
    ) {}

    public function query()
    {
        return OdessaPreEnrollment::query()
            ->with(['linkedUser', 'linkedCustomer', 'linkedOdessaAccount'])
            ->filter($this->filters)
            ->latest();
    }

    public function headings(): array
    {
        return [
            'Empresa',
            'Empleado',
            'Apellido paterno',
            'Apellido materno',
            'Nombre',
            'Nacimiento',
            'Correo',
            'ID ODESSA',
            'Acción',
            'noCredito reservado',
            'Estado precarga',
            'Estado Murguía',
            'Cuenta FAMEDIC',
            'Estado vínculo',
            'Otro correo FAMEDIC',
            'Observaciones',
        ];
    }

    public function map($preEnrollment): array
    {
        return [
            $this->safeText($preEnrollment->company_external_identifier),
            $this->safeText($preEnrollment->employee_identifier),
            $this->safeText($preEnrollment->paternal_last_name),
            $this->safeText($preEnrollment->maternal_last_name),
            $this->safeText($preEnrollment->first_name),
            $preEnrollment->birth_date ? Date::dateTimeToExcel($preEnrollment->birth_date) : null,
            $this->safeText($preEnrollment->source_email),
            $this->safeText($preEnrollment->odessa_identifier),
            $this->safeText($preEnrollment->source_action),
            $preEnrollment->medical_attention_identifier ? 'Sí' : 'No',
            $this->safeText($preEnrollment->status),
            $this->safeText($preEnrollment->murguia_status),
            $preEnrollment->linkedUser || $preEnrollment->linkedCustomer ? 'Detectada' : null,
            $this->safeText($preEnrollment->link_status),
            ($preEnrollment->metadata_json['other_famedic_email'] ?? null) ? 'Detectado' : null,
            $this->safeText(implode('; ', array_filter([
                $preEnrollment->blocked_reason,
                implode('; ', $preEnrollment->data_quality_flags ?? []),
            ]))),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    private function safeText(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';
        if (preg_match('/^\s*[=+\-@]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }
}
