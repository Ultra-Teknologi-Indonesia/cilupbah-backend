@php
    $shipping = $order->shipping ?? (object)[];

    $recipientName = $shipping->full_name ?? $order->customer_name ?? '-';
    $recipientPhone = $shipping->phone ?? '-';
    $recipientAddr = trim(implode(', ', array_filter([
        $shipping->address ?? null,
        $shipping->city ?? null,
        $shipping->province ?? null,
        $shipping->post_code ?? null,
    ]))) ?: '-';

    $txDate = $order->transaction_date
        ? \Carbon\Carbon::parse($order->transaction_date)->locale('id')->translatedFormat('d F Y')
        : '-';

    $printDate = now()->locale('id')->translatedFormat('d F Y, H:i');

    $subTotal   = (float) $order->sub_total;
    $totalDisc  = (float) $order->total_disc;
    $totalTax   = (float) $order->total_tax;
    $shippingCost = (float) $order->shipping_cost;
    $insuranceCost = (float) $order->insurance_cost;
    $grandTotal = (float) $order->grand_total;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pesanan {{ $order->salesorder_no }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 12mm 10mm; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #111;
            font-size: 9pt;
            line-height: 1.4;
        }

        .header {
            border-bottom: 2pt solid #111;
            padding-bottom: 4mm;
            margin-bottom: 5mm;
        }
        .header-table { width: 100%; }
        .header-table td { vertical-align: top; }
        .header-title {
            font-size: 20pt;
            font-weight: bold;
            letter-spacing: 1pt;
        }
        .header-subtitle { font-size: 8pt; color: #666; margin-top: 1mm; }
        .company-name { font-size: 11pt; font-weight: bold; text-align: right; }
        .company-sub { font-size: 8pt; color: #555; text-align: right; }

        .info-section {
            width: 100%;
            margin-bottom: 5mm;
        }
        .info-section td { vertical-align: top; }
        .info-left { width: 50%; }
        .info-right { width: 50%; text-align: right; }

        .section-label {
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1pt;
            color: #888;
            margin-bottom: 1.5mm;
        }
        .recipient-name { font-size: 11pt; font-weight: bold; }
        .recipient-detail { font-size: 8pt; color: #444; margin-top: 0.5mm; }

        .info-row { margin-bottom: 1mm; }
        .info-label { color: #888; display: inline-block; width: 25mm; font-size: 8pt; }
        .info-value { font-size: 8pt; }
        .info-value-mono { font-family: DejaVu Sans Mono, monospace; font-size: 8pt; font-weight: bold; }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4mm;
        }
        .items-table thead tr {
            border-top: 2pt solid #111;
            border-bottom: 2pt solid #111;
        }
        .items-table th {
            padding: 2mm 1.5mm;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
        }
        .items-table td {
            padding: 1.5mm 1.5mm;
            font-size: 8pt;
            border-bottom: 0.5pt solid #ddd;
            vertical-align: top;
        }
        .items-table .text-center { text-align: center; }
        .items-table .text-right { text-align: right; }
        .items-table .text-left { text-align: left; }
        .items-table .mono { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; }
        .items-table .desc { max-width: 55mm; word-wrap: break-word; }

        .summary-wrapper { text-align: right; }
        .summary-table {
            display: inline-table;
            width: 65mm;
            border-collapse: collapse;
        }
        .summary-table td {
            padding: 1mm 0;
            font-size: 8.5pt;
        }
        .summary-table .label { text-align: left; color: #555; }
        .summary-table .value { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .summary-table .discount { color: #b45309; }
        .summary-table .total-row td {
            border-top: 2pt solid #111;
            padding-top: 2mm;
            font-size: 10pt;
            font-weight: bold;
        }
        .summary-table .total-row .label { color: #111; }
        .summary-table .status-row td {
            padding-top: 1.5mm;
            font-size: 8pt;
        }
        .status-paid { color: #059669; font-weight: bold; }
        .status-unpaid { color: #d97706; font-weight: bold; }

        .footer {
            border-top: 0.5pt solid #ddd;
            margin-top: 10mm;
            padding-top: 3mm;
            text-align: center;
            font-size: 7pt;
            color: #aaa;
        }
    </style>
</head>
<body>

<div class="header">
    <table class="header-table">
        <tr>
            <td>
                <div class="header-title">PESANAN</div>
                <div class="header-subtitle">Faktur Penjualan</div>
            </td>
            <td>
                <div class="company-name">PT ULTRA TEKNOLOGI INDONESIA</div>
                <div class="company-sub">Cilupbah</div>
            </td>
        </tr>
    </table>
</div>

<table class="info-section">
    <tr>
        <td class="info-left">
            <div class="section-label">Kepada</div>
            <div class="recipient-name">{{ $recipientName }}</div>
            @if($recipientPhone !== '-')
                <div class="recipient-detail">{{ $recipientPhone }}</div>
            @endif
            @if($recipientAddr !== '-')
                <div class="recipient-detail" style="margin-top:1mm;">{{ $recipientAddr }}</div>
            @endif
        </td>
        <td class="info-right">
            <div class="info-row">
                <span class="info-label">No. Pesanan</span>
                <span class="info-value-mono">{{ $order->salesorder_no }}</span>
            </div>
            @if($order->channel_order_no)
                <div class="info-row">
                    <span class="info-label">No. Referensi</span>
                    <span class="info-value" style="font-family:DejaVu Sans Mono,monospace;">{{ $order->channel_order_no }}</span>
                </div>
            @endif
            <div class="info-row">
                <span class="info-label">Tanggal</span>
                <span class="info-value">{{ $txDate }}</span>
            </div>
            @if($order->shipping_provider)
                <div class="info-row">
                    <span class="info-label">Pengiriman</span>
                    <span class="info-value">{{ $order->shipping_provider }}@if($order->tracking_number) ({{ $order->tracking_number }})@endif</span>
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="items-table">
    <thead>
        <tr>
            <th class="text-center" style="width:8mm;">NO</th>
            <th class="text-left">SKU</th>
            <th class="text-left">KETERANGAN</th>
            <th class="text-center" style="width:12mm;">QTY</th>
            <th class="text-right">HARGA</th>
            <th class="text-center" style="width:12mm;">DISK%</th>
            <th class="text-right">DISKON</th>
            <th class="text-right">JUMLAH</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->items as $i => $item)
            <tr>
                <td class="text-center">{{ $i + 1 }}</td>
                <td class="mono">{{ $item->sku }}</td>
                <td class="desc">{{ $item->description ?: '-' }}</td>
                <td class="text-center">{{ (int) $item->qty_in_base }}</td>
                <td class="text-right" style="font-family:DejaVu Sans Mono,monospace;">{{ number_format((float) $item->price, 0, ',', '.') }}</td>
                <td class="text-center">{{ (float) $item->disc > 0 ? number_format((float) $item->disc, 0) . '%' : '-' }}</td>
                <td class="text-right" style="font-family:DejaVu Sans Mono,monospace;">{{ (float) $item->disc_amount > 0 ? number_format((float) $item->disc_amount, 0, ',', '.') : '-' }}</td>
                <td class="text-right" style="font-weight:500;font-family:DejaVu Sans Mono,monospace;">{{ number_format((float) $item->amount, 0, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="summary-wrapper">
    <table class="summary-table">
        <tr>
            <td class="label">Sub Total</td>
            <td class="value">{{ number_format($subTotal, 0, ',', '.') }}</td>
        </tr>
        @if($totalDisc > 0)
            <tr class="discount">
                <td class="label discount">Diskon</td>
                <td class="value discount">-{{ number_format($totalDisc, 0, ',', '.') }}</td>
            </tr>
        @endif
        @if($totalTax > 0)
            <tr>
                <td class="label">Pajak</td>
                <td class="value">{{ number_format($totalTax, 0, ',', '.') }}</td>
            </tr>
        @endif
        @if($shippingCost > 0)
            <tr>
                <td class="label">Ongkos Kirim</td>
                <td class="value">{{ number_format($shippingCost, 0, ',', '.') }}</td>
            </tr>
        @endif
        @if($insuranceCost > 0)
            <tr>
                <td class="label">Asuransi</td>
                <td class="value">{{ number_format($insuranceCost, 0, ',', '.') }}</td>
            </tr>
        @endif
        <tr class="total-row">
            <td class="label">Grand Total</td>
            <td class="value">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
        </tr>
        <tr class="status-row">
            <td class="label">Status</td>
            <td class="value {{ $order->is_paid ? 'status-paid' : 'status-unpaid' }}">
                {{ $order->is_paid ? 'LUNAS' : 'BELUM DIBAYAR' }}
            </td>
        </tr>
    </table>
</div>

<div class="footer">
    Dokumen ini dicetak secara otomatis oleh sistem Cilupbah pada {{ $printDate }}
</div>

</body>
</html>
