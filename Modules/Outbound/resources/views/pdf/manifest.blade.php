@php
    /** @var \Modules\Outbound\Models\Shipment $shipment */
    /** @var string|null $qrDataUri */
    $orders = collect($shipment->orders ?? []);
    $totalPaket = $orders->count();
    $cancelled = $orders->filter(fn ($so) => str_contains(strtolower(optional($so->order)->status ?? ''), 'cancel'))->count();
    $courierName = $shipment->courier_name ?? $shipment->courier_code ?? '-';
    $shipmentNo = $shipment->shipment_no ?? '';
    $createdBy = $shipment->created_by ?? '';
    $shipmentDate = $shipment->shipment_date
        ? \Carbon\Carbon::parse($shipment->shipment_date)->translatedFormat('d M Y H:i')
        : '—';
    $totalWeightGram = $orders->sum(fn ($so) => (int) (optional($so->order)->order_weight_gram ?? 0));
    $totalWeightKg = number_format($totalWeightGram / 1000, 2, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Manifest {{ $shipmentNo }}</title>
    <style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }
        .title {
            font-size: 20px;
            font-weight: 700;
            text-align: right;
            margin: 0 0 14px;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .header-table td {
            vertical-align: top;
            padding: 0;
        }
        .header-left { width: 50%; }
        .header-right { width: 50%; }
        .qr-img { width: 100px; height: 100px; margin-bottom: 6px; }
        .info-row {
            margin-bottom: 2px;
            font-size: 11px;
        }
        .info-label {
            display: inline-block;
            width: 170px;
            font-weight: 600;
        }
        .info-sep {
            display: inline-block;
            width: 10px;
        }
        .items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 10px;
        }
        .items th, .items td {
            border: 1px solid #000;
            padding: 5px 7px;
            vertical-align: top;
        }
        .items th {
            background: #f3f4f6;
            font-weight: 600;
            text-align: left;
            font-size: 10px;
        }
        .center { text-align: center; }
        .col-no { width: 30px; text-align: center; }
        .summary-row {
            margin-top: 10px;
            font-size: 11px;
        }
        .summary-row .info-label { font-weight: 700; }
        .signature {
            width: 100%;
            border-collapse: collapse;
            margin-top: 40px;
        }
        .signature td {
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 0 20px;
        }
        .signature .sig-title {
            font-weight: 600;
            font-size: 11px;
            margin-bottom: 70px;
        }
        .signature .sig-line {
            border-top: 1px solid #000;
            display: inline-block;
            width: 180px;
            margin-top: 60px;
        }
        .signature .sig-name {
            font-size: 10px;
            margin-top: 4px;
        }
        .footer {
            position: fixed;
            bottom: -8mm;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #555;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer td { padding: 0 2px; }
        .footer .right { text-align: right; }
        .page-num:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="title">Laporan Bukti Pengiriman</div>

    <table class="header-table">
        <tr>
            <td class="header-left">
                @if($qrDataUri)
                    <img class="qr-img" src="{{ $qrDataUri }}" alt="QR">
                @endif
                <div class="info-row">
                    <span class="info-label">No Pengiriman</span>
                    <span class="info-sep">:</span>
                    <span>{{ $shipmentNo }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Nama Penanggung Jawab</span>
                    <span class="info-sep">:</span>
                    <span>{{ $createdBy }}</span>
                </div>
            </td>
            <td class="header-right">
                <div class="info-row">
                    <span class="info-label">Total Paket</span>
                    <span class="info-sep">:</span>
                    <span>{{ $totalPaket }} Paket</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Paket Cancel</span>
                    <span class="info-sep">:</span>
                    <span>{{ $cancelled ?: '' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Kurir</span>
                    <span class="info-sep">:</span>
                    <span>{{ $courierName }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Tanggal Pengiriman</span>
                    <span class="info-sep">:</span>
                    <span>{{ $shipmentDate }}</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="col-no">No</th>
                <th>No Pesanan</th>
                <th class="center">Paket</th>
                <th class="center">Quantity</th>
                <th class="center">Berat</th>
                <th>No Resi</th>
                <th>Status Channel</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $i => $so)
                @php
                    $order = $so->order;
                    $wg = (int) ($order->order_weight_gram ?? 0);
                    $weightKg = $wg ? number_format($wg / 1000, 2, ',', '.') : '0';
                    $resi = $so->tracking_number ?? $order->tracking_number ?? '';
                @endphp
                <tr>
                    <td class="col-no">{{ $i + 1 }}</td>
                    <td>{{ $order->salesorder_no ?? '-' }}</td>
                    <td class="center"></td>
                    <td class="center">{{ (int) ($order->total_qty ?? 1) }}</td>
                    <td class="center">{{ $weightKg }}</td>
                    <td>{{ $resi }}</td>
                    <td>{{ $order->status ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="center" style="padding: 18px;">Tidak ada pesanan.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary-row">
        <span class="info-label">Total Berat</span>
        <span class="info-sep">:</span>
        <span>{{ $totalWeightKg }} kg</span>
    </div>

    <table class="signature">
        <tr>
            <td>
                <div class="sig-title">Tanda Tangan Penanggung Jawab</div>
                <div class="sig-line"></div>
                <div class="sig-name">{{ $createdBy }}</div>
            </td>
            <td>
                <div class="sig-title">Tanda Tangan Pengirim</div>
                <div class="sig-line"></div>
                <div class="sig-name">{{ $courierName }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        <table>
            <tr>
                <td>Tgl. Cetak: {{ now()->format('d M Y H:i') }}</td>
                <td class="right">Hal: <span class="page-num"></span></td>
            </tr>
        </table>
    </div>
</body>
</html>
