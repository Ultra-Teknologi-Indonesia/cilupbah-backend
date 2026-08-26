@php
    $shipping = $order->shipping ?? (object)[];

    $recipientName = $shipping->full_name ?? $order->customer_name ?? '-';
    $recipientPhone = $shipping->phone ?? null;
    $recipientAddr = trim(implode(', ', array_filter([
        $shipping->address ?? null,
        $shipping->city ?? null,
        $shipping->province ?? null,
        $shipping->post_code ?? null,
    ]))) ?: '-';

    $txDate = $order->transaction_date
        ? \Carbon\Carbon::parse($order->transaction_date)->locale('id')->translatedFormat('d F Y')
        : '-';

    $dueDate = $order->due_date
        ? \Carbon\Carbon::parse($order->due_date)->locale('id')->translatedFormat('d F Y')
        : $txDate;

    $printDate = now()->locale('id')->translatedFormat('d F Y, H:i');

    $invoice = $order->invoices->first() ?? null;
    $invoiceNo = $invoice?->invoice_number ?? $order->invoice_no ?? ('INV-' . $order->salesorder_no);
    $shopName = $order->channelShop?->shop_name ?? $order->shop_name ?? 'i-CASE OFFICIAL';

    $subTotal      = (float) ($order->sub_total ?: $order->items->sum('amount'));
    $totalDisc     = (float) ($order->total_disc ?: 0);
    $totalTax      = (float) ($order->total_tax ?: 0);
    $shippingCost  = (float) ($order->shipping_cost ?: 0);
    $insuranceCost = (float) ($order->insurance_cost ?: 0);
    $grandTotal    = (float) ($order->grand_total ?: ($subTotal - $totalDisc + $totalTax + $shippingCost + $insuranceCost));
    $totalQty      = (int) $order->items->sum('qty_in_base');

    $sellerVoucher   = (float) ($order->seller_voucher ?? 0);
    $platformVoucher = (float) ($order->platform_voucher ?? 0);
    $diskonLainnya   = ($platformVoucher + $sellerVoucher) > 0
        ? $platformVoucher + max($sellerVoucher - $totalDisc, 0)
        : 0.0;
    $potonganBiaya = (float) ($order->commission_fee ?? 0) + (float) ($order->affiliate_commission ?? 0);
    $biayaLainnya  = (float) ($order->service_fee ?? 0)
        + (float) ($order->transaction_fee ?? 0)
        + (float) ($order->order_processing_fee ?? 0);
    $diskonOngkir  = (float) ($order->platform_shipping_rebate ?? 0);

    // Seller note juga menyimpan audit webhook untuk kebutuhan internal.
    // Audit teknis tidak dicetak pada invoice pelanggan.
    $rawNote = $order->buyer_message ?: ($order->seller_note ?: null);
    $noteLines = preg_split('/\R/u', trim((string) $rawNote), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $noteLines = array_values(array_filter(
        $noteLines,
        static fn (string $line): bool => ! str_contains($line, 'Webhook channel=')
            && ! str_contains($line, 'Webhook event_key='),
    ));
    $note = trim(implode(PHP_EOL, $noteLines)) ?: null;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Faktur Penjualan {{ $invoiceNo }}</title>
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
            letter-spacing: 0.5pt;
        }
        .header-subtitle { font-size: 8pt; color: #666; margin-top: 1mm; }
        .company-name { font-size: 11pt; font-weight: bold; text-align: right; }
        .company-sub { font-size: 8pt; color: #555; text-align: right; }

        .info-section { width: 100%; margin-bottom: 5mm; }
        .info-section td { vertical-align: top; }
        .info-left { width: 52%; padding-right: 6mm; }
        .info-right { width: 48%; text-align: right; }
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
        .info-label { color: #888; display: inline-block; width: 27mm; font-size: 8pt; }
        .info-value { font-size: 8pt; }
        .info-value-mono {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8pt;
            font-weight: bold;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4mm;
        }
        .items-table thead { display: table-header-group; }
        .items-table tr { page-break-inside: avoid; }
        .items-table thead tr {
            border-top: 2pt solid #111;
            border-bottom: 2pt solid #111;
        }
        .items-table th {
            padding: 2mm 1mm;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.2pt;
        }
        .items-table td {
            padding: 1.5mm 1mm;
            font-size: 8pt;
            border-bottom: 0.5pt solid #ddd;
            vertical-align: top;
        }
        .items-table .text-center { text-align: center; }
        .items-table .text-right { text-align: right; }
        .items-table .text-left { text-align: left; }
        .items-table .mono { font-family: DejaVu Sans Mono, monospace; font-size: 7.2pt; }
        .items-table .desc { line-height: 1.3; word-wrap: break-word; }

        .summary-section {
            width: 100%;
            margin-top: 2mm;
            page-break-inside: avoid;
        }
        .summary-section > tbody > tr > td { vertical-align: top; }
        .note-col { width: 52%; padding-right: 8mm; }
        .summary-col { width: 48%; text-align: right; }
        .note-box { font-size: 8pt; }
        .note-title { font-weight: bold; margin-bottom: 1mm; }
        .note-content { color: #444; line-height: 1.35; white-space: pre-line; }

        .summary-table {
            display: inline-table;
            width: 78mm;
            border-collapse: collapse;
        }
        .summary-table td { padding: 0.8mm 0; font-size: 8.5pt; }
        .summary-table .label { text-align: left; color: #555; }
        .summary-table .value {
            text-align: right;
            font-family: DejaVu Sans Mono, monospace;
            white-space: nowrap;
        }
        .summary-table .discount { color: #b45309; }
        .summary-table .grand-total-row td {
            border-top: 2pt solid #111;
            padding-top: 2mm;
            font-size: 10pt;
            font-weight: bold;
        }
        .summary-table .grand-total-row .label { color: #111; }
        .summary-table .status-row td { padding-top: 1.5mm; font-size: 8pt; }
        .status-paid { color: #059669; font-weight: bold; }
        .status-unpaid { color: #d97706; font-weight: bold; }

        .footer {
            border-top: 0.5pt solid #ddd;
            margin-top: 8mm;
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
                <div class="header-title">FAKTUR PENJUALAN</div>
                <div class="header-subtitle">{{ $shopName }}</div>
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
            @if($recipientPhone)
                <div class="recipient-detail">{{ $recipientPhone }}</div>
            @endif
            @if($recipientAddr !== '-')
                <div class="recipient-detail" style="margin-top:1mm;">{{ $recipientAddr }}</div>
            @endif
        </td>
        <td class="info-right">
            <div class="info-row">
                <span class="info-label">No. Faktur</span>
                <span class="info-value-mono">{{ $invoiceNo }}</span>
            </div>
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
            <div class="info-row">
                <span class="info-label">Term</span>
                <span class="info-value">{{ $order->is_cod ? 'COD' : 'TUNAI' }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Jatuh Tempo</span>
                <span class="info-value">{{ $dueDate }}</span>
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
            <th class="text-center" style="width:7mm;">NO</th>
            <th class="text-left" style="width:28mm;">SKU</th>
            <th class="text-left">KETERANGAN</th>
            <th class="text-center" style="width:9mm;">QTY</th>
            <th class="text-center" style="width:10mm;">UNIT</th>
            <th class="text-right" style="width:23mm;">HARGA</th>
            <th class="text-center" style="width:12mm;">DISK%</th>
            <th class="text-center" style="width:12mm;">PAJAK%</th>
            <th class="text-right" style="width:24mm;">JUMLAH</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->items as $i => $item)
            @php
                $itemDesc = $item->description ?: ($item->product?->product?->name ?? '-');
                $unitPrice = (float) $item->price;
                $qty = (int) $item->qty_in_base;
                $discAmount = (float) ($item->disc_amount ?? 0);
                $lineBase = max(($unitPrice * $qty) - $discAmount, 0);
                $taxAmount = (float) ($item->tax_amount ?? 0);
                $discPct = (float) ($item->disc ?? 0);
                $taxPct = $lineBase > 0 && $taxAmount > 0 ? ($taxAmount / $lineBase) * 100 : 0;
                if ($discPct === 0.0 && $discAmount > 0 && $unitPrice > 0 && $qty > 0) {
                    $discPct = ($discAmount / ($unitPrice * $qty)) * 100;
                }
                $amount = (float) ($item->amount ?? ($lineBase + $taxAmount));
            @endphp
            <tr>
                <td class="text-center">{{ $i + 1 }}</td>
                <td class="mono">{{ $item->sku ?: '-' }}</td>
                <td class="desc">{{ $itemDesc }}</td>
                <td class="text-center">{{ $qty }}</td>
                <td class="text-center">Buah</td>
                <td class="text-right mono">{{ number_format($unitPrice, 0, ',', '.') }}</td>
                <td class="text-center">{{ $discPct > 0 ? number_format($discPct, 0) . '%' : '-' }}</td>
                <td class="text-center">{{ $taxPct > 0 ? number_format($taxPct, 0) . '%' : '-' }}</td>
                <td class="text-right mono" style="font-weight:500;">{{ number_format($amount, 0, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="summary-section">
    <tr>
        <td class="note-col">
            <div class="note-box">
                <div class="note-title">Catatan</div>
                <div class="note-content">{{ $note ?: '-' }}</div>
            </div>
        </td>
        <td class="summary-col">
            <table class="summary-table">
                <tr>
                    <td class="label">Total Qty</td>
                    <td class="value">{{ $totalQty }}</td>
                </tr>
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
                @if($diskonLainnya > 0)
                    <tr class="discount">
                        <td class="label discount">Diskon Lainnya</td>
                        <td class="value discount">-{{ number_format($diskonLainnya, 0, ',', '.') }}</td>
                    </tr>
                @endif
                @if($potonganBiaya > 0)
                    <tr class="discount">
                        <td class="label discount">Potongan Biaya</td>
                        <td class="value discount">-{{ number_format($potonganBiaya, 0, ',', '.') }}</td>
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
                @if($diskonOngkir > 0)
                    <tr class="discount">
                        <td class="label discount">Diskon Ongkos Kirim</td>
                        <td class="value discount">-{{ number_format($diskonOngkir, 0, ',', '.') }}</td>
                    </tr>
                @endif
                @if($biayaLainnya > 0)
                    <tr class="discount">
                        <td class="label discount">Biaya Lainnya</td>
                        <td class="value discount">-{{ number_format($biayaLainnya, 0, ',', '.') }}</td>
                    </tr>
                @endif
                @if($insuranceCost > 0)
                    <tr>
                        <td class="label">Asuransi</td>
                        <td class="value">{{ number_format($insuranceCost, 0, ',', '.') }}</td>
                    </tr>
                @endif
                <tr class="grand-total-row">
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
        </td>
    </tr>
</table>

<div class="footer">
    Dokumen ini dicetak secara otomatis oleh sistem Cilupbah pada {{ $printDate }}
</div>

</body>
</html>
