<?php

namespace Modules\Report\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Report\Support\SectionedReport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SectionedReportExport implements FromArray, WithEvents, WithTitle, ShouldAutoSize
{
    private const NUMBER_FORMAT = '#,##0';
    private const GROUP_FILL = 'E8E8E8';
    private const HEAD_FILL = 'F4F4F4';

    public function __construct(
        private readonly SectionedReport $report,
        private readonly string $sheetTitle = 'Laporan',
    ) {}

    public function array(): array
    {
        $cols = $this->report->columnCount();

        return array_map(
            fn (array $row) => array_pad($row['cells'], $cols, null),
            $this->report->rows,
        );
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = Coordinate::stringFromColumnIndex($this->report->columnCount());

                foreach ($this->report->rows as $i => $row) {
                    $r = $i + 1;
                    $range = "A{$r}:{$lastCol}{$r}";

                    match ($row['type']) {
                        SectionedReport::TITLE => $this->banner($sheet, $range, "A{$r}", bold: true, size: 15),
                        SectionedReport::PERIODE => $this->banner($sheet, $range, "A{$r}", bold: false, size: 10),
                        SectionedReport::EMPTY => $this->italicMerged($sheet, $range, "A{$r}"),
                        SectionedReport::GROUP => $this->groupLabel($sheet, $range, "A{$r}"),
                        SectionedReport::SUBGROUP => $this->subgroupLabel($sheet, $range, "A{$r}"),
                        SectionedReport::HEAD => $this->headRow($sheet, $range),
                        SectionedReport::DATA => $this->dataRow($sheet, $range, $row['cells'], $r),
                        SectionedReport::SUBTOTAL => $this->emphasisRow($sheet, $range, $row['cells'], $r, fill: null),
                        SectionedReport::GRAND => $this->emphasisRow($sheet, $range, $row['cells'], $r, fill: self::GROUP_FILL),
                        default => null,
                    };
                }
            },
        ];
    }

    private function banner(Worksheet $sheet, string $range, string $anchor, bool $bold, int $size): void
    {
        $sheet->mergeCells($range);
        $sheet->getStyle($anchor)->getFont()->setBold($bold)->setSize($size);
        $sheet->getStyle($anchor)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function italicMerged(Worksheet $sheet, string $range, string $anchor): void
    {
        $sheet->mergeCells($range);
        $sheet->getStyle($anchor)->getFont()->setItalic(true);
    }

    private function groupLabel(Worksheet $sheet, string $range, string $anchor): void
    {
        $sheet->mergeCells($range);
        $sheet->getStyle($anchor)->getFont()->setBold(true);
        $sheet->getStyle($anchor)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GROUP_FILL);
    }

    private function subgroupLabel(Worksheet $sheet, string $range, string $anchor): void
    {
        $sheet->mergeCells($range);
        $sheet->getStyle($anchor)->getFont()->setBold(true)->setItalic(true);
    }

    private function headRow(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEAD_FILL);
        $this->bordered($sheet, $range);
    }

    private function dataRow(Worksheet $sheet, string $range, array $cells, int $r): void
    {
        $this->bordered($sheet, $range);
        $this->formatNumeric($sheet, $cells, $r);
    }

    private function emphasisRow(Worksheet $sheet, string $range, array $cells, int $r, ?string $fill): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        if ($fill !== null) {
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }
        $this->bordered($sheet, $range);
        $this->formatNumeric($sheet, $cells, $r);
    }

    private function bordered(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function formatNumeric(Worksheet $sheet, array $cells, int $r): void
    {
        foreach ($cells as $idx => $val) {
            if (! is_int($val) && ! is_float($val)) {
                continue;
            }
            $cell = Coordinate::stringFromColumnIndex($idx + 1) . $r;
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        }
    }
}
