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
        ? \Carbon\Carbon::parse($order->transaction_date)->locale('id')->translatedFormat('d M Y')
        : now()->locale('id')->translatedFormat('d M Y');

    $dueDate = $order->due_date
        ? \Carbon\Carbon::parse($order->due_date)->locale('id')->translatedFormat('d M Y')
        : $txDate;

    $printDate = now()->locale('id')->translatedFormat('d M Y H:i');

    $invoice = $order->invoices->first() ?? null;
    $invoiceNo = $invoice?->invoice_number ?? $order->invoice_no ?? ('INV-' . $order->salesorder_no);
    $shopName = $order->channelShop?->shop_name ?? $order->shop_name ?? 'i-CASE OFFICIAL';

    $subTotal   = (float) ($order->sub_total ?: $order->items->sum('amount'));
    $totalDisc  = (float) ($order->total_disc ?: 0);
    $totalTax   = (float) ($order->total_tax ?: 0);
    $shippingCost = (float) ($order->shipping_cost ?: 0);
    $insuranceCost = (float) ($order->insurance_cost ?: 0);
    $grandTotal = (float) ($order->grand_total ?: ($subTotal - $totalDisc + $shippingCost + $insuranceCost));
    $totalQty   = (int) $order->items->sum('qty_in_base');

    $note = $order->buyer_message ?: ($order->seller_note ?: null);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Faktur Penjualan {{ $invoiceNo }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 12mm 12mm 10mm 12mm; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: 'Helvetica Neue', Helvetica, Arial, DejaVu Sans, sans-serif;
            color: #111;
            font-size: 8.5pt;
            line-height: 1.35;
        }

        .header-table {
            width: 100%;
            margin-bottom: 6mm;
        }
        .header-table td { vertical-align: top; }
        .logo-shop {
            font-size: 15pt;
            font-weight: 900;
            letter-spacing: -0.5pt;
            color: #111;
            text-transform: uppercase;
            margin-bottom: 2mm;
        }
        .company-name {
            font-size: 9pt;
            font-weight: bold;
            color: #111;
        }
        .company-sub {
            font-size: 8pt;
            color: #333;
            margin-top: 0.5mm;
        }

        .doc-title {
            font-size: 19pt;
            font-weight: bold;
            text-align: right;
            letter-spacing: -0.3pt;
        }

        .info-table {
            width: 100%;
            margin-bottom: 5mm;
        }
        .info-table td { vertical-align: top; }
        .info-left {
            width: 52%;
            padding-right: 4mm;
        }
        .info-right {
            width: 48%;
        }

        .recipient-label {
            font-size: 9pt;
            font-weight: bold;
            color: #111;
        }
        .recipient-name {
            font-size: 9pt;
            font-weight: bold;
            color: #111;
            display: inline;
        }
        .recipient-address {
            font-size: 8pt;
            color: #333;
            margin-top: 1mm;
            line-height: 1.35;
            word-wrap: break-word;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-table td {
            padding: 0.8mm 0;
            font-size: 8pt;
        }
        .meta-label {
            width: 28mm;
            color: #222;
            text-align: right;
            padding-right: 4mm;
        }
        .meta-value {
            font-weight: 500;
            text-align: right;
            font-family: DejaVu Sans Mono, monospace;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4mm;
        }
        .items-table thead tr {
            border-top: 1.5pt solid #111;
            border-bottom: 1.5pt solid #111;
        }
        .items-table th {
            padding: 1.8mm 1mm;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .items-table td {
            padding: 1.8mm 1mm;
            font-size: 8pt;
            border-bottom: 0.5pt solid #e5e5e5;
            vertical-align: top;
        }
        .items-table .text-center { text-align: center; }
        .items-table .text-right { text-align: right; }
        .items-table .text-left { text-align: left; }
        .items-table .mono { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; }
        .items-table .desc { line-height: 1.3; }

        .summary-section {
            width: 100%;
            margin-top: 2mm;
        }
        .summary-section td { vertical-align: top; }
        .note-col {
            width: 50%;
            padding-right: 4mm;
        }
        .summary-col {
            width: 50%;
            text-align: right;
        }

        .note-box {
            font-size: 8pt;
        }
        .note-title {
            font-weight: bold;
            margin-bottom: 1mm;
        }
        .note-content {
            color: #333;
            line-height: 1.35;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-table td {
            padding: 0.7mm 0;
            font-size: 8pt;
        }
        .summary-table .label {
            text-align: right;
            padding-right: 4mm;
            color: #222;
        }
        .summary-table .value {
            text-align: right;
            font-family: DejaVu Sans Mono, monospace;
            width: 32mm;
        }
        .summary-table .grand-total-row td {
            font-weight: bold;
            font-size: 8.5pt;
            padding-top: 1mm;
            border-top: 1pt solid #222;
        }

        .footer {
            margin-top: 8mm;
            font-size: 7.5pt;
            color: #333;
        }
    </style>
</head>
<body>

<table class="header-table">
    <tr>
        <td style="width: 55%;">
            <div class="logo-shop">{{ $shopName }}</div>
            <div class="company-name">PT ULTRA TEKNOLOGI INDONESIA</div>
            <div class="company-sub">NEO SOHO PODOMORO CITY</div>
        </td>
        <td style="width: 45%;">
            <div class="doc-title">Faktur Penjualan</div>
        </td>
    </tr>
</table>

<table class="info-table">
    <tr>
        <td class="info-left">
            <div class="recipient-label">Kepada: <span class="recipient-name">{{ $recipientName }}</span></div>
            <div class="recipient-address">
                {{ $recipientAddr }}
            </div>
        </td>
        <td class="info-right">
            <table class="meta-table">
                <tr>
                    <td class="meta-label">No. Faktur</td>
                    <td class="meta-value">{{ $invoiceNo }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Tanggal</td>
                    <td class="meta-value">{{ $txDate }}</td>
                </tr>
                <tr>
                    <td class="meta-label">No. Ref.</td>
                    <td class="meta-value">{{ $order->channel_order_no ?? $order->salesorder_no }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Term</td>
                    <td class="meta-value">{{ $order->is_cod ? 'COD' : 'TUNAI' }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Jatuh Tempo</td>
                    <td class="meta-value">{{ $dueDate }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="items-table">
    <thead>
        <tr>
            <th class="text-center" style="width:6mm;">NO</th>
            <th class="text-left">KETERANGAN</th>
            <th class="text-center" style="width:10mm;">QTY</th>
            <th class="text-center" style="width:12mm;">UNIT</th>
            <th class="text-right" style="width:24mm;">HARGA</th>
            <th class="text-center" style="width:14mm;">DISK%</th>
            <th class="text-center" style="width:14mm;">PAJAK%</th>
            <th class="text-right" style="width:24mm;">JUMLAH</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->items as $i => $item)
            @php
                $itemDesc = $item->description ?: ($item->product?->product?->name ?? $item->sku);
                $unitPrice = (float) $item->price;
                $discPct = (float) ($item->disc ?? 0);
                $discAmt = (float) ($item->disc_amount ?? 0);
                if ($discPct == 0 && $discAmt > 0 && $unitPrice > 0) {
                    $discPct = ($discAmt / ($unitPrice * (float)$item->qty_in_base)) * 100;
                }
                $amount = (float) $item->amount ?: (($unitPrice * (float)$item->qty_in_base) - $discAmt);
            @endphp
            <tr>
                <td class="text-center">{{ $i + 1 }}</td>
                <td class="desc">
                    <div style="font-weight:500;">{{ $itemDesc }}</div>
                    @if($item->sku && $item->sku !== $itemDesc)
                        <div class="mono" style="color:#555;font-size:7pt;margin-top:0.3mm;">{{ $item->sku }}</div>
                    @endif
                </td>
                <td class="text-center">{{ (int) $item->qty_in_base }}</td>
                <td class="text-center">Buah</td>
                <td class="text-right mono">{{ number_format($unitPrice, 2, ',', '.') }}</td>
                <td class="text-center mono">{{ $discPct > 0 ? number_format($discPct, 2, ',', '.') : '0,00' }}</td>
                <td class="text-center mono">0,00</td>
                <td class="text-right mono" style="font-weight:600;">{{ number_format($amount, 2, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="summary-section">
    <tr>
        <td class="note-col">
            <div class="note-box">
                <div class="note-title">Catatan :</div>
                <div class="note-content">
                    {{ $note ?? '—' }}
                </div>
            </div>
        </td>
        <td class="summary-col">
            <table class="summary-table">
                <tr>
                    <td class="label">Total Qty</td>
                    <td class="value" style="font-weight:600;">{{ $totalQty }}</td>
                </tr>
                <tr>
                    <td class="label">Sub Total</td>
                    <td class="value">{{ number_format($subTotal, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="label">Diskon</td>
                    <td class="value">{{ number_format($totalDisc, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="label">Diskon Lainnya</td>
                    <td class="value">0,00</td>
                </tr>
                <tr>
                    <td class="label">Potongan Biaya</td>
                    <td class="value">0,00</td>
                </tr>
                <tr>
                    <td class="label">Pajak</td>
                    <td class="value">{{ number_format($totalTax, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="label">Ongkos Kirim</td>
                    <td class="value">{{ number_format($shippingCost, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="label">Diskon Ongkos Kirim</td>
                    <td class="value">0,00</td>
                </tr>
                <tr>
                    <td class="label">Biaya Lainnya</td>
                    <td class="value">0,00</td>
                </tr>
                <tr>
                    <td class="label">Asuransi</td>
                    <td class="value">{{ number_format($insuranceCost, 2, ',', '.') }}</td>
                </tr>
                <tr class="grand-total-row">
                    <td class="label">Grand Total</td>
                    <td class="value">Rp {{ number_format($grandTotal, 2, ',', '.') }}</td>
                </tr>
                @if($order->is_cod)
                    <tr>
                        <td class="label" style="font-weight:bold;color:#b91c1c;">Metode Bayar</td>
                        <td class="value" style="font-weight:bold;color:#b91c1c;">COD</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div class="footer">
    Dicetak tanggal : {{ $printDate }}
</div>

</body>
</html>
