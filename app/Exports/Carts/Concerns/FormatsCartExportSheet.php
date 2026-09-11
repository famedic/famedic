<?php

namespace App\Exports\Carts\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait FormatsCartExportSheet
{
    public function styles(Worksheet $sheet): array
    {
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->headings())));
        $lastRow = max(1, $sheet->getHighestRow());

        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB('EEF2F7');
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->headings())));
                $lastRow = max(1, $sheet->getHighestRow());

                $sheet->freezePane($this->freezePaneCell());
                $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");

                foreach ($this->wrappedColumns() as $column => $width) {
                    $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
                }

                $this->afterCartExportSheet($sheet);
            },
        ];
    }

    protected function afterCartExportSheet(Worksheet $sheet): void
    {
        //
    }

    protected function freezePaneCell(): string
    {
        return 'A2';
    }

    /**
     * @return array<string, int>
     */
    protected function wrappedColumns(): array
    {
        return [];
    }
}
