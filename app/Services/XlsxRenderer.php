<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Facades\Excel as MaatwebsiteExcel;
use Modules\Report\Exports\SectionedReportExport;
use Modules\Report\Support\SectionedReport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class XlsxRenderer
{
    private const DEFAULT_BATCH_SIZE = 1000;
    private const NUMBER_FORMAT = '#,##0';
    private const DECIMAL_FORMAT = '#,##0.00';
    private const GROUP_FILL = 0xE8E8E8;
    private const HEAD_FILL = 0xF4F4F4;

    public function isXlsWriterAvailable(): bool
    {
        return extension_loaded('xlswriter');
    }

    public function save(object $export, string $targetFilePath): int
    {
        if (! $this->isXlsWriterAvailable()) {
            return $this->saveWithMaatwebsite($export, $targetFilePath);
        }

        $targetDir = dirname($targetFilePath);
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $tempDir = sys_get_temp_dir();
        $tempFilename = 'xlswriter_' . uniqid('', true) . '.xlsx';

        $config = ['path' => $tempDir];
        $excel = new \Vtiful\Kernel\Excel($config);

        $rowCount = 0;

        if ($export instanceof WithMultipleSheets || method_exists($export, 'sheets')) {
            $rowCount = $this->renderMultipleSheets($excel, $tempFilename, $export);
        } elseif ($this->isSectionedReport($export)) {
            $rowCount = $this->renderSectionedReport($excel, $tempFilename, $export);
        } else {
            $rowCount = $this->renderSingleSheet($excel, $tempFilename, $export);
        }

        $generatedPath = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tempFilename;
        if (! file_exists($generatedPath)) {
            throw new \RuntimeException("Berkas Excel tidak berhasil dibuat di: {$generatedPath}");
        }

        if (! rename($generatedPath, $targetFilePath)) {
            copy($generatedPath, $targetFilePath);
            @unlink($generatedPath);
        }

        return $rowCount;
    }

    public function store(object $export, string $path, string $diskName = 'local'): bool
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'xlsx-store-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Tidak dapat membuat berkas sementara.');
        }

        try {
            $this->save($export, $temporaryPath);

            $stream = fopen($temporaryPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Tidak dapat membaca berkas excel sementara.');
            }

            try {
                return Storage::disk($diskName)->put($path, $stream);
            } finally {
                fclose($stream);
            }
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function download(object $export, string $fileName, array $headers = []): BinaryFileResponse
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'xlsx-dl-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Tidak dapat membuat berkas sementara.');
        }

        $this->save($export, $temporaryPath);

        $defaultHeaders = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
        ];

        return response()->download(
            $temporaryPath,
            $fileName,
            array_merge($defaultHeaders, $headers)
        )->deleteFileAfterSend(true);
    }

    public function raw(object $export): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'xlsx-raw-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Tidak dapat membuat berkas sementara.');
        }

        try {
            $this->save($export, $temporaryPath);
            $content = file_get_contents($temporaryPath);
            if ($content === false) {
                throw new \RuntimeException('Gagal membaca konten berkas excel.');
            }
            return $content;
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function renderSingleSheet(\Vtiful\Kernel\Excel $excel, string $filename, object $export): int
    {
        $sheetTitle = $this->getSheetTitle($export, 'Sheet1');
        $file = $excel->constMemory($filename, $sheetTitle);

        return $this->writeSheetContent($file, $export);
    }

    private function renderMultipleSheets(\Vtiful\Kernel\Excel $excel, string $filename, object $export): int
    {

        $sheets = method_exists($export, 'sheets') ? $export->sheets() : [];
        if (empty($sheets)) {
            $file = $excel->fileName($filename, 'Sheet1');
            $file->output();
            return 0;
        }

        $firstSheet = $sheets[0];
        $firstTitle = $this->getSheetTitle($firstSheet, 'Sheet1');
        $file = $excel->fileName($filename, $firstTitle);

        $totalRows = 0;

        foreach ($sheets as $index => $sheet) {
            $title = $this->getSheetTitle($sheet, "Sheet" . ($index + 1));
            if ($index > 0) {
                $file->addSheet($title);
            }
            $totalRows += $this->writeSheetContent($file, $sheet);
        }

        $file->output();
        return $totalRows;
    }

    private function writeSheetContent(\Vtiful\Kernel\Excel $file, object $sheet): int
    {
        $handle = $file->getHandle();

        $file->freezePanes(1, 0);

        $columnFormats = [];
        if ($sheet instanceof WithColumnFormatting || method_exists($sheet, 'columnFormats')) {
            $columnFormats = (array) $sheet->columnFormats();
        }

        if ($sheet instanceof WithColumnWidths || method_exists($sheet, 'columnWidths')) {
            $widths = (array) $sheet->columnWidths();
            foreach ($widths as $col => $width) {
                $colStr = (string) $col;
                $range = "{$colStr}:{$colStr}";
                $formatResource = null;
                if (isset($columnFormats[$col])) {
                    $formatResource = (new \Vtiful\Kernel\Format($handle))
                        ->number((string) $columnFormats[$col])
                        ->toResource();
                }
                if ($formatResource !== null) {
                    $file->setColumn($range, (float) $width, $formatResource);
                } else {
                    $file->setColumn($range, (float) $width);
                }
            }
        } elseif (! empty($columnFormats)) {
            foreach ($columnFormats as $col => $numFmt) {
                $colStr = (string) $col;
                $range = "{$colStr}:{$colStr}";
                $formatResource = (new \Vtiful\Kernel\Format($handle))
                    ->number((string) $numFmt)
                    ->toResource();
                $file->setColumn($range, 18, $formatResource);
            }
        } elseif ($sheet instanceof ShouldAutoSize) {
            $file->autoSize();
        }

        if ($sheet instanceof WithHeadings || method_exists($sheet, 'headings')) {
            $headings = (array) $sheet->headings();
            if (! empty($headings)) {
                $headerFormat = (new \Vtiful\Kernel\Format($handle))
                    ->bold()
                    ->background(\Vtiful\Kernel\Format::COLOR_SILVER)
                    ->border(\Vtiful\Kernel\Format::BORDER_THIN)
                    ->toResource();

                $file->header($headings, $headerFormat);
            }
        }

        $rowCount = 0;
        $batch = [];

        if ($sheet instanceof FromQuery || method_exists($sheet, 'query')) {
            $query = $sheet->query();
            if ($query instanceof EloquentBuilder || $query instanceof QueryBuilder || $query instanceof Relation) {
                foreach ($query->cursor() as $row) {
                    $mapped = $this->mapRow($sheet, $row);
                    $batch[] = $this->sanitizeRow($mapped);
                    $rowCount++;

                    if (count($batch) >= self::DEFAULT_BATCH_SIZE) {
                        $file->data($batch);
                        $batch = [];
                    }
                }
            }
        } elseif ($sheet instanceof FromCollection || method_exists($sheet, 'collection')) {
            $collection = $sheet->collection();
            if ($collection instanceof Collection || is_array($collection)) {
                foreach ($collection as $row) {
                    $mapped = $this->mapRow($sheet, $row);
                    $batch[] = $this->sanitizeRow($mapped);
                    $rowCount++;

                    if (count($batch) >= self::DEFAULT_BATCH_SIZE) {
                        $file->data($batch);
                        $batch = [];
                    }
                }
            }
        } elseif ($sheet instanceof FromArray || method_exists($sheet, 'array')) {
            $rows = (array) $sheet->array();
            foreach ($rows as $row) {
                $mapped = $this->mapRow($sheet, $row);
                $batch[] = $this->sanitizeRow($mapped);
                $rowCount++;

                if (count($batch) >= self::DEFAULT_BATCH_SIZE) {
                    $file->data($batch);
                    $batch = [];
                }
            }
        }

        if (! empty($batch)) {
            $file->data($batch);
        }

        try {
            $file->output();
        } catch (Throwable) {

        }

        return $rowCount;
    }

    private function renderSectionedReport(\Vtiful\Kernel\Excel $excel, string $filename, object $export): int
    {
        $report = $this->extractSectionedReport($export);
        if (! $report) {
            throw new \InvalidArgumentException('Objek export bukan SectionedReport yang valid.');
        }

        $sheetTitle = $this->getSheetTitle($export, 'Laporan');
        $file = $excel->fileName($filename, $sheetTitle);
        $handle = $file->getHandle();

        $colCount = max(1, $report->columnCount());
        $lastColLetter = $this->columnLetter($colCount);

        $titleFmt = (new \Vtiful\Kernel\Format($handle))
            ->bold()
            ->fontSize(15)
            ->align(\Vtiful\Kernel\Format::FORMAT_ALIGN_CENTER)
            ->toResource();

        $periodeFmt = (new \Vtiful\Kernel\Format($handle))
            ->fontSize(10)
            ->align(\Vtiful\Kernel\Format::FORMAT_ALIGN_CENTER)
            ->toResource();

        $emptyFmt = (new \Vtiful\Kernel\Format($handle))
            ->italic()
            ->toResource();

        $groupFmt = (new \Vtiful\Kernel\Format($handle))
            ->bold()
            ->background(self::GROUP_FILL)
            ->toResource();

        $subgroupFmt = (new \Vtiful\Kernel\Format($handle))
            ->bold()
            ->italic()
            ->toResource();

        $headFmt = (new \Vtiful\Kernel\Format($handle))
            ->bold()
            ->background(self::HEAD_FILL)
            ->border(\Vtiful\Kernel\Format::BORDER_THIN)
            ->toResource();

        $dataFmt = (new \Vtiful\Kernel\Format($handle))
            ->border(\Vtiful\Kernel\Format::BORDER_THIN)
            ->toResource();

        $dataNumFmt = (new \Vtiful\Kernel\Format($handle))
            ->number(self::NUMBER_FORMAT)
            ->border(\Vtiful\Kernel\Format::BORDER_THIN)
            ->align(\Vtiful\Kernel\Format::FORMAT_ALIGN_RIGHT)
            ->toResource();

        $subtotalFmt = (new \Vtiful\Kernel\Format($handle))
            ->bold()
            ->border(\Vtiful\Kernel\Format::BORDER_THIN)
            ->toResource();

        $subtotalNumFmt = (new \Vtiful\Kernel\Format($handle))
            ->bold()
            ->number(self::NUMBER_FORMAT)
            ->border(\Vtiful\Kernel\Format::BORDER_THIN)
            ->align(\Vtiful\Kernel\Format::FORMAT_ALIGN_RIGHT)
            ->toResource();

        $grandFmt = (new \Vtiful\Kernel\Format($handle))
            ->bold()
            ->background(self::GROUP_FILL)
            ->border(\Vtiful\Kernel\Format::BORDER_THIN)
            ->toResource();

        $grandNumFmt = (new \Vtiful\Kernel\Format($handle))
            ->bold()
            ->background(self::GROUP_FILL)
            ->number(self::NUMBER_FORMAT)
            ->border(\Vtiful\Kernel\Format::BORDER_THIN)
            ->align(\Vtiful\Kernel\Format::FORMAT_ALIGN_RIGHT)
            ->toResource();

        $currentRow = 0;
        $rowCount = 0;

        foreach ($report->rows as $row) {
            $type = $row['type'] ?? SectionedReport::DATA;
            $cells = (array) ($row['cells'] ?? []);
            $r = $currentRow + 1; 

            switch ($type) {
                case SectionedReport::TITLE:
                    $this->writeBannerRow($file, $currentRow, $colCount, (string) ($cells[0] ?? ''), $titleFmt);
                    $currentRow++;
                    break;

                case SectionedReport::PERIODE:
                    $this->writeBannerRow($file, $currentRow, $colCount, (string) ($cells[0] ?? ''), $periodeFmt);
                    $currentRow++;
                    break;

                case SectionedReport::SPACER:
                    $currentRow++;
                    break;

                case SectionedReport::EMPTY:
                    $this->writeBannerRow($file, $currentRow, $colCount, (string) ($cells[0] ?? ''), $emptyFmt);
                    $currentRow++;
                    break;

                case SectionedReport::GROUP:
                    $this->writeBannerRow($file, $currentRow, $colCount, (string) ($cells[0] ?? ''), $groupFmt);
                    $currentRow++;
                    break;

                case SectionedReport::SUBGROUP:
                    $this->writeBannerRow($file, $currentRow, $colCount, (string) ($cells[0] ?? ''), $subgroupFmt);
                    $currentRow++;
                    break;

                case SectionedReport::HEAD:
                    for ($c = 0; $c < $colCount; $c++) {
                        $val = (string) ($cells[$c] ?? '');
                        $file->insertText($currentRow, $c, $val, '', $headFmt);
                    }
                    $currentRow++;
                    break;

                case SectionedReport::DATA:
                    for ($c = 0; $c < $colCount; $c++) {
                        $val = $cells[$c] ?? '';
                        if (is_int($val) || is_float($val)) {
                            $file->insertText($currentRow, $c, (string) $val, self::NUMBER_FORMAT, $dataNumFmt);
                        } else {
                            $file->insertText($currentRow, $c, (string) $val, '', $dataFmt);
                        }
                    }
                    $currentRow++;
                    $rowCount++;
                    break;

                case SectionedReport::SUBTOTAL:
                    for ($c = 0; $c < $colCount; $c++) {
                        $val = $cells[$c] ?? '';
                        if (is_int($val) || is_float($val)) {
                            $file->insertText($currentRow, $c, (string) $val, self::NUMBER_FORMAT, $subtotalNumFmt);
                        } else {
                            $file->insertText($currentRow, $c, (string) $val, '', $subtotalFmt);
                        }
                    }
                    $currentRow++;
                    break;

                case SectionedReport::GRAND:
                    for ($c = 0; $c < $colCount; $c++) {
                        $val = $cells[$c] ?? '';
                        if (is_int($val) || is_float($val)) {
                            $file->insertText($currentRow, $c, (string) $val, self::NUMBER_FORMAT, $grandNumFmt);
                        } else {
                            $file->insertText($currentRow, $c, (string) $val, '', $grandFmt);
                        }
                    }
                    $currentRow++;
                    break;

                default:
                    $currentRow++;
                    break;
            }
        }

        for ($c = 0; $c < $colCount; $c++) {
            $letter = $this->columnLetter($c + 1);
            $file->setColumn("{$letter}:{$letter}", 22);
        }

        $file->output();
        return $rowCount;
    }

    private function writeBannerRow(\Vtiful\Kernel\Excel $file, int $currentRow, int $colCount, string $val, $format): void
    {
        if ($colCount > 1) {
            $r = $currentRow + 1;
            $lastColLetter = $this->columnLetter($colCount);
            $range = "A{$r}:{$lastColLetter}{$r}";
            $file->mergeCells($range, $val, $format);
        } else {
            $file->insertText($currentRow, 0, $val, '', $format);
        }
    }

    private function mapRow(object $sheet, mixed $row): array
    {
        if ($sheet instanceof WithMapping || method_exists($sheet, 'map')) {
            return (array) $sheet->map($row);
        }

        if (is_array($row)) {
            return array_values($row);
        }

        if ($row instanceof Arrayable) {
            return array_values($row->toArray());
        }

        if (is_object($row)) {
            return array_values((array) $row);
        }

        return [(string) $row];
    }

    private function sanitizeRow(array $row): array
    {
        $sanitized = [];
        foreach ($row as $val) {
            if ($val instanceof DateTimeInterface) {
                $sanitized[] = $val->format('Y-m-d H:i:s');
            } elseif (is_bool($val)) {
                $sanitized[] = $val ? 'Ya' : 'Tidak';
            } elseif (is_array($val) || is_object($val)) {
                $sanitized[] = json_encode($val, JSON_UNESCAPED_UNICODE);
            } elseif ($val === null) {
                $sanitized[] = '';
            } else {
                $sanitized[] = $val;
            }
        }

        return $sanitized;
    }

    private function isSectionedReport(object $export): bool
    {
        if ($export instanceof SectionedReportExport || $export instanceof SectionedReport) {
            return true;
        }

        return $this->extractSectionedReport($export) !== null;
    }

    private function extractSectionedReport(object $export): ?SectionedReport
    {
        if ($export instanceof SectionedReport) {
            return $export;
        }

        if (method_exists($export, 'getReport')) {
            $report = $export->getReport();
            if ($report instanceof SectionedReport) {
                return $report;
            }
        }

        try {
            $ref = new \ReflectionClass($export);
            if ($ref->hasProperty('report')) {
                $prop = $ref->getProperty('report');
                $prop->setAccessible(true);
                $val = $prop->getValue($export);
                if ($val instanceof SectionedReport) {
                    return $val;
                }
            }
        } catch (Throwable) {

        }

        return null;
    }

    private function getSheetTitle(?object $sheet, string $default = 'Sheet1'): string
    {
        if (! $sheet) {
            return $default;
        }

        if ($sheet instanceof WithTitle || method_exists($sheet, 'title')) {
            $title = (string) $sheet->title();

            $cleanTitle = preg_replace('/[\\\\\/\?\*\:\[\]]/', '', $title) ?? $default;
            return mb_substr(trim($cleanTitle), 0, 31) ?: $default;
        }

        return $default;
    }

    private function columnLetter(int $colIndex): string
    {
        $letter = '';
        while ($colIndex > 0) {
            $modulo = ($colIndex - 1) % 26;
            $letter = chr(65 + $modulo) . $letter;
            $colIndex = (int) (($colIndex - $modulo) / 26);
        }

        return $letter ?: 'A';
    }

    private function saveWithMaatwebsite(object $export, string $targetFilePath): int
    {
        Log::warning('xlswriter C-extension is not installed. Falling back to Maatwebsite\Excel (PhpSpreadsheet).');
        MaatwebsiteExcel::store($export, basename($targetFilePath), 'local');
        $storedPath = Storage::disk('local')->path(basename($targetFilePath));
        if (file_exists($storedPath) && $storedPath !== $targetFilePath) {
            rename($storedPath, $targetFilePath);
        }

        return 0;
    }
}
