<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OdessaReconciliationArraySheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $rowActions = [],
    ) {}

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach ($this->headings as $index => $heading) {
            if (in_array($heading, ['Fecha de nacimiento', 'Fecha inicio membresía', 'Fecha de vencimiento'], true)) {
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = NumberFormat::FORMAT_DATE_DDMMYYYY;
            }
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));
        $lastRow = max(1, count($this->rows) + 1);

        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB('EEF2F7');
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        $this->paintStatusColumns($sheet);
        $this->paintActionRows($sheet, $lastColumn);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));
                $lastRow = max(1, count($this->rows) + 1);

                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");

                foreach ($this->headings as $index => $heading) {
                    if (str_contains(mb_strtolower($heading), 'evidencia')) {
                        $column = Coordinate::stringFromColumnIndex($index + 1);
                        $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth(60);
                    }
                }
            },
        ];
    }

    private function paintStatusColumns(Worksheet $sheet): void
    {
        $actionColumn = array_search('Acción ODESSA', $this->headings, true);
        $statusColumns = array_filter([
            $actionColumn === false ? null : $actionColumn,
            array_search('source_action_status', $this->headings, true),
            array_search('match_status', $this->headings, true),
            array_search('murguia_status', $this->headings, true),
            array_search('acción ejecutable', $this->headings, true),
            array_search('bloqueo', $this->headings, true),
        ], fn ($column) => $column !== false && $column !== null);

        foreach ($statusColumns as $columnIndex) {
            foreach ($this->rows as $index => $row) {
                $value = (string) ($row[$columnIndex] ?? '');
                $rowNumber = $index + 2;
                $color = match (true) {
                    in_array($value, ['ALTA', 'PENDING_ACTIVATION', 'ACTIVATED', 'ALREADY_ACTIVE', 'FAMEDIC_Y_MURGUIA', 'Sí'], true) => 'E2F0D9',
                    in_array($value, ['BAJA', 'PENDING_DEACTIVATION', 'ALREADY_INACTIVE', 'FAMEDIC_NO_MURGUIA'], true) => 'FFF2CC',
                    str_contains($value, 'BLOCKED') || str_contains($value, 'Bloque') || str_contains($value, 'NO_MATCH') => 'FCE4D6',
                    str_contains($value, 'FAILED') || str_contains($value, 'ERROR') || str_contains($value, 'MURGUIA_NO_FAMEDIC') => 'F4CCCC',
                    str_contains($value, 'MATCH_PROBABLE') || str_contains($value, 'MATCH_AMBIGUOUS') => 'DDEBF7',
                    default => null,
                };

                if ($color) {
                    $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).$rowNumber;
                    $sheet->getStyle($coordinate)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setRGB($color);
                }
            }
        }
    }

    private function paintActionRows(Worksheet $sheet, string $lastColumn): void
    {
        foreach ($this->rowActions as $index => $action) {
            $color = match ($action) {
                'ALTA' => 'E2F0D9',
                'BAJA' => 'FFC000',
                default => null,
            };

            if (! $color) {
                continue;
            }

            $rowNumber = $index + 2;
            $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($color);
        }
    }
}
