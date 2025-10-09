<?php

namespace App\Exports;

use App\Models\Administrator;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AdministratorsExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping
{
    public array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        return Administrator::with([
            'user',
            'roles',
            'laboratoryConcierge',
        ])
            ->filter($this->filters)
            ->orderByUserName();
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function headings(): array
    {
        return [
            'Nombre',
            'Apellido paterno',
            'Apellido materno',
            'Correo electrónico',
            'Teléfono',
            'Roles',
            'Concierge de laboratorio',
        ];
    }

    public function map($administrator): array
    {
        return [
            $administrator->user?->name,
            $administrator->user?->paternal_lastname,
            $administrator->user?->maternal_lastname,
            $administrator->user?->email,
            $administrator->user?->phone?->formatNational(),
            $administrator->roles->pluck('name')->join(', ') ?: 'Sin roles',
            $administrator->laboratoryConcierge ? 'Activo' : 'Inactivo',
        ];
    }
}
