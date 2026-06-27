<?php

namespace App\Exports;

use App\Enums\MonitoringCartType;
use App\Models\Cart;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CartsExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithColumnFormatting, WithHeadings, WithMapping
{
    public array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $start = ! empty($this->filters['start_date'])
            ? Carbon::parse($this->filters['start_date'], 'America/Monterrey')->startOfDay()->utc()
            : null;
        $end = ! empty($this->filters['end_date'])
            ? Carbon::parse($this->filters['end_date'], 'America/Monterrey')->endOfDay()->utc()
            : null;

        return Cart::query()
            ->with([
                'items',
                'user.customer.laboratoryCartItems.laboratoryTest',
                'user.customer.laboratoryAppointments',
            ])
            ->withCount('items')
            ->adminMonitoringFilter($this->filters, $start, $end)
            ->orderByDesc('updated_at');
    }

    public function chunkSize(): int
    {
        return 50;
    }

    public function headings(): array
    {
        return [
            'Nombre',
            'Apellidos',
            'Correo',
            'Marcas de laboratorio',
            'Tipo',
            'Número de ítems',
            'Total',
            'Estatus',
            'Estatus cita',
            'Fecha última actividad',
        ];
    }

    public function map($cart): array
    {
        /** @var Cart $cart */
        $user = $cart->user;
        $lastNames = trim(collect([$user?->paternal_lastname, $user?->maternal_lastname])->filter()->implode(' '));
        $labBrands = $cart->type === MonitoringCartType::Lab
            ? collect($cart->labBrands())->pluck('label')->implode(', ')
            : '';

        return [
            $user?->name,
            $lastNames !== '' ? $lastNames : null,
            $user?->email,
            $labBrands !== '' ? $labBrands : null,
            $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
            $cart->items_count ?? $cart->items->count(),
            (float) $cart->total,
            $cart->displayStatusLabel(),
            $cart->appointmentExportStatus(),
            $cart->updated_at ? Date::dateTimeToExcel(localizedDate($cart->updated_at)) : null,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_CURRENCY_USD,
            'J' => NumberFormat::FORMAT_DATE_XLSX22,
        ];
    }
}
