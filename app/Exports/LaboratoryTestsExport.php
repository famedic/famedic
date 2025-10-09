<?php

namespace App\Exports;

use App\Models\LaboratoryTest;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class LaboratoryTestsExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping
{
    public function __construct(
        private array $filters = []
    ) {}

    public function query()
    {
        return LaboratoryTest::with([
            'laboratoryTestCategory',
        ])
            ->filter($this->filters)
            ->orderBy('name');
    }

    public function headings(): array
    {
        return [
            'Código GDA',
            'Nombre',
            'Otro nombre',
            'Marca',
            'Categoría',
            'Precio público',
            'Precio Famedic',
            'Requiere cita',
            'Indicaciones',
        ];
    }

    public function map($laboratoryTest): array
    {
        return [
            $laboratoryTest->gda_id,
            $laboratoryTest->name,
            $laboratoryTest->other_name,
            $laboratoryTest->brand->value,
            $laboratoryTest->laboratoryTestCategory->name,
            numberCents($laboratoryTest->public_price_cents),
            numberCents($laboratoryTest->famedic_price_cents),
            $laboratoryTest->requires_appointment ? 'Sí' : 'No',
            $laboratoryTest->indications,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_CURRENCY_USD,  // Precio público
            'G' => NumberFormat::FORMAT_CURRENCY_USD,  // Precio Famedic
        ];
    }
}
