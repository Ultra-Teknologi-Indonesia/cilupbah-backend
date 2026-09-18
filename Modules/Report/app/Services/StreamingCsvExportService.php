<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use RuntimeException;

final class StreamingCsvExportService
{
    public function write(object $export, string $targetPath): int
    {
        $handle = fopen($targetPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Tidak dapat membuka berkas CSV untuk ditulis.');
        }

        $delimiter = ',';
        $enclosure = '"';
        $escape = '\\';
        $lineEnding = "\r\n";
        $useBom = false;

        if ($export instanceof WithCustomCsvSettings) {
            $settings = $export->getCsvSettings();
            $delimiter = $settings['delimiter'] ?? $delimiter;
            $enclosure = $settings['enclosure'] ?? $enclosure;
            $escape = $settings['escape_character'] ?? $escape;
            $lineEnding = $settings['line_ending'] ?? $lineEnding;
            $useBom = $settings['use_bom'] ?? $useBom;
        }

        try {
            if ($useBom) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            if ($export instanceof WithHeadings) {
                $this->writeRow($handle, $export->headings(), $delimiter, $enclosure, $escape, $lineEnding);
            }

            $written = 0;

            if ($export instanceof FromQuery) {
                $chunkSize = method_exists($export, 'chunkSize') ? $export->chunkSize() : 500;
                $query = $export->query();

                if (method_exists($query, 'cursor')) {
                    foreach ($query->cursor() as $record) {
                        $row = $export instanceof WithMapping ? $export->map($record) : (array) $record;
                        $this->writeRow($handle, $row, $delimiter, $enclosure, $escape, $lineEnding);
                        $written++;
                    }
                } else {
                    $query->chunk($chunkSize, function ($chunk) use ($export, $handle, $delimiter, $enclosure, $escape, $lineEnding, &$written) {
                        foreach ($chunk as $record) {
                            $row = $export instanceof WithMapping ? $export->map($record) : (array) $record;
                            $this->writeRow($handle, $row, $delimiter, $enclosure, $escape, $lineEnding);
                            $written++;
                        }
                    });
                }
            } elseif ($export instanceof FromCollection) {
                foreach ($export->collection() as $record) {
                    $row = $export instanceof WithMapping ? $export->map($record) : (array) $record;
                    $this->writeRow($handle, $row, $delimiter, $enclosure, $escape, $lineEnding);
                    $written++;
                }
            } elseif ($export instanceof FromArray) {
                foreach ($export->array() as $record) {
                    $row = $export instanceof WithMapping ? $export->map($record) : (array) $record;
                    $this->writeRow($handle, $row, $delimiter, $enclosure, $escape, $lineEnding);
                    $written++;
                }
            } else {
                throw new RuntimeException('Export tidak mendukung interface FromQuery, FromCollection, atau FromArray untuk CSV.');
            }

            if (! fflush($handle)) {
                throw new RuntimeException('Tidak dapat menyelesaikan penulisan berkas CSV.');
            }

            return $written;
        } finally {
            fclose($handle);
        }
    }

    private function writeRow($handle, array $row, string $delimiter, string $enclosure, string $escape, string $lineEnding): void
    {
        $formatted = array_map(function (mixed $val): string {
            if ($val === null) {
                return '';
            }
            if (is_bool($val)) {
                return $val ? '1' : '0';
            }
            if ($val instanceof \DateTimeInterface) {
                return $val->format('Y-m-d H:i:s');
            }
            if (is_scalar($val) || $val instanceof \Stringable) {
                return (string) $val;
            }
            return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }, array_values($row));

        if (fputcsv($handle, $formatted, $delimiter, $enclosure, $escape, $lineEnding) === false) {
            throw new RuntimeException('Tidak dapat menulis baris ke berkas CSV.');
        }
    }
}
