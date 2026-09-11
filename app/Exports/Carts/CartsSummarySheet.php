<?php

namespace App\Exports\Carts;

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Exports\Carts\Concerns\BuildsCartExportQuery;
use App\Exports\Carts\Concerns\FormatsCartExportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat as SpreadsheetNumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CartsSummarySheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithStyles, WithTitle
{
    use BuildsCartExportQuery;
    use FormatsCartExportSheet;

    public function __construct(
        private array $filters = []
    ) {}

    public function array(): array
    {
        $base = $this->baseCartQuery();
        $abandoned = (clone $base)->displayStatusFilter('abandoned');

        return [
            ['Fecha generacion', Date::dateTimeToExcel(localizedDate(now()))],
            ['Periodo utilizado', $this->periodLabel()],
            ['Filtros activos', $this->filtersLabel()],
            ['Total carritos', (clone $base)->count()],
            ['Activos', (clone $base)->displayStatusFilter('active')->count()],
            ['Abandonados', (clone $base)->displayStatusFilter('abandoned')->count()],
            ['Comprados', (clone $base)->where('status', MonitoringCartStatus::Completed)->count()],
            ['Atencion requerida', (clone $base)->operationalBucket('attention')->count()],
            ['Pagos rechazados', (clone $base)->relatedPaymentAttemptStatus('declined')->count()],
            ['Errores tecnicos', (clone $base)->relatedPaymentAttemptStatus('error')->count()],
            ['Citas pendientes', (clone $base)->appointmentPendingConfirmation()->count()],
            ['Citas confirmadas sin pago', (clone $base)->appointmentConfirmedPendingPayment()->count()],
            ['Solicitaron llamada', (clone $base)->contactFilter('callback_requested')->count()],
            ['Monto total carritos', (float) (clone $base)->sum('total')],
            ['Monto abandonado', (float) $abandoned->sum('total')],
        ];
    }

    public function headings(): array
    {
        return [
            'Metrica',
            'Valor',
        ];
    }

    public function title(): string
    {
        return 'Resumen';
    }

    public function columnFormats(): array
    {
        return [];
    }

    private function periodLabel(): string
    {
        $prefix = ($this->filters['_using_default_period'] ?? false) ? 'Ultimos 7 dias: ' : '';

        return $prefix.($this->filters['start_date'] ?? 'sin inicio').' a '.($this->filters['end_date'] ?? 'sin fin');
    }

    private function filtersLabel(): string
    {
        $filters = collect($this->filters)
            ->reject(fn ($value, string $key) => str_starts_with($key, '_'))
            ->map(fn ($value, string $key) => $this->filterLabel($key, (string) $value))
            ->values();

        return $filters->isEmpty() ? 'Sin filtros' : $filters->implode('; ');
    }

    private function filterLabel(string $key, string $value): string
    {
        $label = match ($key) {
            'search' => 'Busqueda',
            'type' => 'Tipo carrito',
            'display_status' => 'Estado',
            'operational_filter' => 'Filtro operativo',
            'operational_bucket' => 'Grupo operativo',
            'payment_status' => 'Estado pago',
            'checkout_stage' => 'Etapa checkout',
            'appointment_filter' => 'Filtro cita',
            'contact_filter' => 'Filtro contacto',
            'customer_segment' => 'Tipo cliente',
            'brand' => 'Marca',
            'amount_range' => 'Rango monto',
            'inactivity_range' => 'Rango inactividad',
            'start_date' => 'Fecha inicio',
            'end_date' => 'Fecha fin',
            default => str($key)->replace('_', ' ')->title()->toString(),
        };

        return $label.'='.$this->filterValueLabel($key, $value);
    }

    private function filterValueLabel(string $key, string $value): string
    {
        return match ($key) {
            'type' => match ($value) {
                MonitoringCartType::Lab->value => 'Laboratorio',
                MonitoringCartType::Pharmacy->value => 'Farmacia',
                default => $value,
            },
            'display_status' => match ($value) {
                'active' => 'Activo',
                'abandoned' => 'Abandonado',
                'completed' => 'Comprado',
                default => $value,
            },
            'operational_filter' => match ($value) {
                'appointment_pending' => 'Cita pendiente de confirmacion',
                'appointment_confirmed_pending_payment' => 'Cita confirmada sin pago',
                'callback_requested' => 'Solicitud de llamada',
                default => $value,
            },
            'operational_bucket' => match ($value) {
                'no_progress' => 'Sin avance',
                'attention' => 'Requiere atencion',
                'payment' => 'Pago',
                'appointment' => 'Cita',
                'contact' => 'Contacto',
                default => $value,
            },
            'payment_status' => match ($value) {
                'pending' => 'Pendiente',
                'approved' => 'Aprobado',
                'declined' => 'Rechazado',
                'error' => 'Error tecnico',
                default => $value,
            },
            'checkout_stage' => match ($value) {
                'no_progress' => 'Sin avance',
                'patient' => 'Paciente',
                'address' => 'Direccion',
                'appointment' => 'Cita',
                'payment' => 'Pago',
                'confirmation' => 'Confirmacion',
                'completed' => 'Compra completada',
                default => $value,
            },
            'appointment_filter' => match ($value) {
                'none' => 'Sin cita',
                'pending' => 'Pendiente',
                'confirmed' => 'Confirmada',
                'confirmed_without_payment' => 'Confirmada sin pago',
                default => $value,
            },
            'contact_filter' => match ($value) {
                'callback_requested' => 'Solicitud de llamada',
                'phone_call_intent' => 'Intento llamar',
                default => $value,
            },
            'customer_segment' => match ($value) {
                'new' => 'Cliente nuevo',
                'existing' => 'Cliente existente',
                'recurrent' => 'Cliente recurrente',
                default => $value,
            },
            'brand' => LaboratoryBrand::tryFrom($value)?->label() ?? ($value === 'unknown' ? 'Sin marca' : $value),
            'amount_range' => match ($value) {
                'lt_1000' => 'Menor a 1000',
                '1000_2000' => '1000 a 2000',
                '2000_5000' => '2000 a 5000',
                'gt_5000' => 'Mayor a 5000',
                default => $value,
            },
            'inactivity_range' => match ($value) {
                'lt_1h' => 'Menos de 1 h',
                '1_3h' => '1 a 3 h',
                '3_24h' => '3 a 24 h',
                '1_3d' => '1 a 3 d',
                'gt_3d' => 'Mas de 3 d',
                default => $value,
            },
            default => $value,
        };
    }

    protected function afterCartExportSheet(Worksheet $sheet): void
    {
        $sheet->getStyle('B2')->getNumberFormat()->setFormatCode(SpreadsheetNumberFormat::FORMAT_DATE_XLSX22);
        $sheet->getStyle('B15:B16')->getNumberFormat()->setFormatCode(SpreadsheetNumberFormat::FORMAT_CURRENCY_USD);
    }
}
