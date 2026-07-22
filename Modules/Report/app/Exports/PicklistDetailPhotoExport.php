<?php

namespace Modules\Report\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

class PicklistDetailPhotoExport implements FromArray, WithColumnWidths, WithDrawings, WithEvents, WithTitle
{
    private const HEADERS = ['Pesanan', 'Foto', 'SKU', 'Produk', 'Qty Pesan', 'Lokasi', 'Rak', 'Qty Ambil'];
    private const LAST_COL = 'H';
    private const HEADER_ROW = 6;
    private const DATA_START = 7;
    private const PHOTO_HEIGHT_PX = 46;

    private ?array $flat = null;

    private array $cache = [];

    public function __construct(
        private readonly object $picklist,
        private readonly Collection $groups,
    ) {}

    public function array(): array
    {
        $matrix = [
            ['Detail Picklist'],
            ['Picklist: ' . ($this->picklist->picklist_no ?? '-')],
            ['Tanggal Transaksi: ' . ($this->picklist->created_at ? Carbon::parse($this->picklist->created_at)->format('d/m/Y H.i') : '-')],
            ['Picker: ' . ($this->picklist->picker?->name ?? '-')],
            [],
            self::HEADERS,
        ];

        foreach ($this->flatRows() as $fr) {
            $matrix[] = $fr['cells'];
        }

        return $matrix;
    }

    public function drawings(): array
    {
        $drawings = [];

        foreach ($this->flatRows() as $k => $fr) {
            if (empty($fr['image_url'])) {
                continue;
            }

            $bytes = $this->fetch($fr['image_url']);
            if ($bytes === null) {
                continue;
            }

            $resource = @imagecreatefromstring($bytes);
            if ($resource === false) {
                continue;
            }

            $drawing = new MemoryDrawing();
            $drawing->setName('foto');
            $drawing->setImageResource($resource);
            $drawing->setRenderingFunction(MemoryDrawing::RENDERING_DEFAULT);
            $drawing->setMimeType(MemoryDrawing::MIMETYPE_DEFAULT);
            $drawing->setHeight(self::PHOTO_HEIGHT_PX);
            $drawing->setOffsetX(6);
            $drawing->setOffsetY(3);
            $drawing->setCoordinates('B' . (self::DATA_START + $k));

            $drawings[] = $drawing;
        }

        return $drawings;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 16, 'B' => 10, 'C' => 18, 'D' => 34,
            'E' => 10, 'F' => 20, 'G' => 14, 'H' => 10,
        ];
    }

    public function title(): string
    {
        return 'Detail Picklist';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = self::LAST_COL;

                $sheet->mergeCells("A1:{$last}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                foreach ([2, 3, 4] as $metaRow) {
                    $sheet->mergeCells("A{$metaRow}:{$last}{$metaRow}");
                }

                $headerRange = 'A' . self::HEADER_ROW . ":{$last}" . self::HEADER_ROW;
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F4F4F4');
                $sheet->getStyle($headerRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $rowCount = count($this->flatRows());
                if ($rowCount === 0) {
                    return;
                }

                $lastRow = self::DATA_START + $rowCount - 1;
                $dataRange = 'A' . self::DATA_START . ":{$last}{$lastRow}";
                $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($dataRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle('E' . self::DATA_START . ":E{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle('H' . self::DATA_START . ":H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                for ($r = self::DATA_START; $r <= $lastRow; $r++) {
                    $sheet->getRowDimension($r)->setRowHeight(40);
                }
            },
        ];
    }

    private function flatRows(): array
    {
        if ($this->flat !== null) {
            return $this->flat;
        }

        $out = [];
        foreach ($this->groups as $group) {
            $orderNo = $group['order_no'] ?? '-';
            $first = true;

            foreach ($group['rows'] as $row) {
                $out[] = [
                    'image_url' => $row['image_url'] ?? null,
                    'cells' => [
                        $first ? $orderNo : '',
                        '',
                        $row['sku'] ?? '-',
                        $row['product_name'] ?? '',
                        (int) ($row['qty_ordered'] ?? 0),
                        $row['location_name'] ?? '-',
                        $row['bin_code'] ?? '-',
                        (int) ($row['qty_picked'] ?? 0),
                    ],
                ];
                $first = false;
            }
        }

        return $this->flat = $out;
    }

    private function fetch(string $url): ?string
    {
        if (array_key_exists($url, $this->cache)) {
            return $this->cache[$url];
        }

        try {
            $response = Http::timeout(4)->retry(1, 200)->get($url);
            $body = $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::warning('Gagal unduh foto picklist untuk Excel', ['url' => $url, 'error' => $e->getMessage()]);
            $body = null;
        }

        return $this->cache[$url] = $body;
    }
}
