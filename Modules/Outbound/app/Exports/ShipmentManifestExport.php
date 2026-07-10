<?php

namespace Modules\Outbound\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Outbound\Models\Shipment;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ShipmentManifestExport implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    private const LAST_COL = 'G';

    private const TABLE_HEADERS = [
        'No',
        'No Pesanan',
        'Paket',
        'Quantity',
        'Berat (kg)',
        'No Resi',
        'Status Channel',
    ];

    private int $metaFirstRow = 0;
    private int $metaLastRow = 0;
    private int $tableHeaderRow = 0;
    private int $tableFirstDataRow = 0;
    private int $tableLastDataRow = 0;
    private int $totalRow = 0;
    private int $signatureLabelRow = 0;
    private int $signatureNameRow = 0;

    public function __construct(private readonly Shipment $shipment) {}

    public function title(): string
    {
        return 'Manifest';
    }

    public function array(): array
    {
        $orders = collect($this->shipment->orders ?? []);
        $totalPaket = $orders->count();
        $cancelled = $orders->filter(
            fn ($so) => str_contains(strtolower(optional($so->order)->status ?? ''), 'cancel')
        )->count();
        $courierName = $this->shipment->courier_name ?? $this->shipment->courier_code ?? '-';
        $shipmentNo = $this->shipment->shipment_no ?? '';
        $createdBy = $this->shipment->created_by ?? '';
        $shipmentDate = $this->shipment->shipment_date
            ? Carbon::parse($this->shipment->shipment_date)->translatedFormat('d M Y H:i')
            : '—';
        $totalWeightGram = $orders->sum(
            fn ($so) => (int) (optional($so->order)->order_weight_gram ?? 0)
        );
        $totalWeightKg = number_format($totalWeightGram / 1000, 2, ',', '.');

        $rows = [];
        $blank = ['', '', '', '', '', '', ''];

        $rows[] = ['Laporan Bukti Pengiriman', '', '', '', '', '', ''];
        $rows[] = $blank;

        $this->metaFirstRow = count($rows) + 1;
        $meta = [
            ['No Pengiriman', $shipmentNo],
            ['Nama Penanggung Jawab', $createdBy],
            ['Total Paket', $totalPaket . ' Paket'],
            ['Total Paket Cancel', $cancelled ? (string) $cancelled : '-'],
            ['Kurir', $courierName],
            ['Tanggal Pengiriman', $shipmentDate],
        ];

        $hasDriver = $this->shipment->driver_call_status && $this->shipment->driver_call_status !== 'NONE';
        if ($hasDriver) {
            $meta[] = ['Nama Driver', $this->shipment->driver_name ?? '—'];
            $meta[] = ['No. HP Driver', $this->shipment->driver_phone ?? '—'];
            $meta[] = ['Plat Kendaraan', $this->shipment->driver_vehicle_plate ?? '—'];
            $meta[] = ['Kode Booking', $this->shipment->driver_booking_code ?? '—'];
        }
        foreach ($meta as [$label, $value]) {
            $rows[] = [$label, '', $value, '', '', '', ''];
        }
        $this->metaLastRow = count($rows);
        $rows[] = $blank;

        $this->tableHeaderRow = count($rows) + 1;
        $rows[] = self::TABLE_HEADERS;

        $this->tableFirstDataRow = count($rows) + 1;
        if ($orders->isEmpty()) {
            $rows[] = ['', 'Tidak ada pesanan.', '', '', '', '', ''];
        } else {
            $i = 1;
            foreach ($orders as $so) {
                $order = $so->order;
                $wg = (int) (optional($order)->order_weight_gram ?? 0);
                $weightKg = $wg ? number_format($wg / 1000, 2, ',', '.') : '0';
                $resi = $so->tracking_number ?? optional($order)->tracking_number ?? '';
                $rows[] = [
                    $i,
                    optional($order)->salesorder_no ?? '-',
                    '',
                    (int) (optional($order)->total_qty ?? 1),
                    $weightKg,
                    $resi,
                    optional($order)->status ?? '',
                ];
                $i++;
            }
        }
        $this->tableLastDataRow = count($rows);

        $rows[] = ['', '', '', '', 'Total Berat', $totalWeightKg . ' kg', ''];
        $this->totalRow = count($rows);

        $rows[] = $blank;
        $rows[] = ['Tanda Tangan Penanggung Jawab', '', '', '', 'Tanda Tangan Pengirim', '', ''];
        $this->signatureLabelRow = count($rows);
        $rows[] = $blank;
        $rows[] = [$createdBy, '', '', '', $courierName, '', ''];
        $this->signatureNameRow = count($rows);

        $rows[] = $blank;
        $rows[] = ['Tgl. Cetak: ' . now()->format('d M Y H:i'), '', '', '', '', '', ''];

        return $rows;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10,
            'B' => 26,
            'C' => 12,
            'D' => 12,
            'E' => 16,
            'F' => 24,
            'G' => 18,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $last = self::LAST_COL;

                $sheet->mergeCells("A1:{$last}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getRowDimension(1)->setRowHeight(24);

                for ($r = $this->metaFirstRow; $r <= $this->metaLastRow; $r++) {
                    $sheet->mergeCells("A{$r}:B{$r}");
                    $sheet->mergeCells("C{$r}:{$last}{$r}");
                    $sheet->getStyle("A{$r}")->getFont()->setBold(true);
                }

                $hr = $this->tableHeaderRow;
                $headerRange = "A{$hr}:{$last}{$hr}";
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F1F5F9');
                $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $tableRange = "A{$hr}:{$last}{$this->tableLastDataRow}";
                $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                if ($this->tableLastDataRow >= $this->tableFirstDataRow) {
                    $sheet->getStyle("A{$this->tableFirstDataRow}:A{$this->tableLastDataRow}")
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("C{$this->tableFirstDataRow}:E{$this->tableLastDataRow}")
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                $sheet->getStyle("E{$this->totalRow}:F{$this->totalRow}")->getFont()->setBold(true);

                $slr = $this->signatureLabelRow;
                $sheet->mergeCells("A{$slr}:C{$slr}");
                $sheet->mergeCells("E{$slr}:{$last}{$slr}");
                $sheet->getStyle("A{$slr}:{$last}{$slr}")->getFont()->setBold(true);
                $snr = $this->signatureNameRow;
                $sheet->mergeCells("A{$snr}:C{$snr}");
                $sheet->mergeCells("E{$snr}:{$last}{$snr}");
            },
        ];
    }
}
