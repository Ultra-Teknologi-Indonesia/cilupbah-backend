@php
    /**
     * Self-Design AWB label (untuk Shopee dummy J&T, SPX Instant, dll).
     *
     * @var array  $data       Raw shipping document data dari Shopee.
     * @var string $orderSn
     * @var string $tracking
     * @var string $courier
     * @var string $barcodeUri data: URI (SVG QR sebagai fallback barcode)
     */
    $recipient = $data['recipient_address'] ?? $data['recipient'] ?? [];
    $sender    = $data['sender_address']    ?? $data['sender']    ?? [];
    $items     = $data['item_list']         ?? $data['items']     ?? [];
    $routing   = $data['routing_code']      ?? ($data['routing_info']['routing_code'] ?? '-');
    $shipBy    = $data['ship_by_date_str']  ?? null;

    $recipientName = $recipient['name'] ?? $recipient['recipient_name'] ?? '-';
    $recipientPhone = $recipient['phone'] ?? $recipient['recipient_phone'] ?? '-';
    $recipientAddr = trim(implode(', ', array_filter([
        $recipient['full_address'] ?? $recipient['address'] ?? null,
        $recipient['town']      ?? null,
        $recipient['district']  ?? null,
        $recipient['city']      ?? null,
        $recipient['state']     ?? null,
        $recipient['zipcode']   ?? $recipient['post_code'] ?? null,
    ]))) ?: '-';

    $senderName = $sender['name'] ?? $sender['sender_name'] ?? '-';
    $senderPhone = $sender['phone'] ?? $sender['sender_phone'] ?? '-';
    $senderAddr = trim(implode(', ', array_filter([
        $sender['full_address'] ?? $sender['address'] ?? null,
        $sender['town']      ?? null,
        $sender['district']  ?? null,
        $sender['city']      ?? null,
        $sender['state']     ?? null,
        $sender['zipcode']   ?? $sender['post_code'] ?? null,
    ]))) ?: '-';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>AWB {{ $tracking }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 4mm; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #000;
            font-size: 9pt;
            line-height: 1.25;
        }
        .label {
            width: 100mm;
            border: 1.5pt solid #000;
        }
        .row {
            border-bottom: 1pt solid #000;
            padding: 2mm 3mm;
        }
        .row:last-child { border-bottom: none; }
        .header {
            display: table;
            width: 100%;
        }
        .header .courier {
            display: table-cell;
            vertical-align: middle;
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header .badge {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
            font-size: 7pt;
            color: #555;
        }
        .tracking {
            text-align: center;
            padding: 3mm;
        }
        .tracking .qr {
            width: 30mm;
            height: 30mm;
            margin: 0 auto 1mm;
        }
        .tracking .qr img { width: 100%; height: 100%; }
        .tracking .num {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.5pt;
        }
        .tracking .routing {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 18pt;
            font-weight: bold;
            margin-top: 1mm;
        }
        .section-title {
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
            color: #555;
            margin-bottom: 1mm;
        }
        .addr-name { font-weight: bold; font-size: 10pt; }
        .addr-phone { font-size: 8pt; color: #333; }
        .addr-text { font-size: 8pt; margin-top: 1mm; }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }
        .items-table th, .items-table td {
            border-bottom: 0.5pt dotted #999;
            padding: 1mm 0;
            text-align: left;
            vertical-align: top;
        }
        .items-table th {
            font-size: 7pt;
            text-transform: uppercase;
            color: #555;
        }
        .items-table td.qty {
            text-align: right;
            width: 12mm;
            font-family: DejaVu Sans Mono, monospace;
        }
        .meta {
            display: table;
            width: 100%;
            font-size: 7pt;
        }
        .meta .l, .meta .r {
            display: table-cell;
            width: 50%;
        }
        .meta .r { text-align: right; color: #555; }
        .footnote {
            font-size: 6pt;
            color: #777;
            text-align: center;
            padding: 1mm;
        }
    </style>
</head>
<body>
<div class="label">
    <div class="row header">
        <div class="courier">{{ $courier ?: 'Shipping Label' }}</div>
        <div class="badge">SELF-DESIGN AWB</div>
    </div>

    <div class="row tracking">
        @if (!empty($barcodeUri))
            <div class="qr"><img src="{{ $barcodeUri }}" alt="Tracking"></div>
        @endif
        <div class="num">{{ $tracking ?: '-' }}</div>
        @if ($routing && $routing !== '-')
            <div class="routing">{{ $routing }}</div>
        @endif
    </div>

    <div class="row">
        <div class="section-title">Penerima</div>
        <div class="addr-name">{{ $recipientName }}</div>
        <div class="addr-phone">{{ $recipientPhone }}</div>
        <div class="addr-text">{{ $recipientAddr }}</div>
    </div>

    <div class="row">
        <div class="section-title">Pengirim</div>
        <div class="addr-name">{{ $senderName }}</div>
        <div class="addr-phone">{{ $senderPhone }}</div>
        <div class="addr-text">{{ $senderAddr }}</div>
    </div>

    @if (!empty($items))
        <div class="row">
            <div class="section-title">Barang</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th class="qty">Qty</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($items as $it)
                    <tr>
                        <td>{{ $it['item_name'] ?? $it['name'] ?? '-' }}@if(!empty($it['model_name'])) <span style="color:#666">({{ $it['model_name'] }})</span>@endif</td>
                        <td class="qty">{{ $it['model_quantity_purchased'] ?? $it['quantity'] ?? $it['qty'] ?? 1 }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="row meta">
        <div class="l">No. Pesanan: <strong>{{ $orderSn }}</strong></div>
        <div class="r">@if ($shipBy)Ship by: {{ $shipBy }}@endif</div>
    </div>

    <div class="footnote">
        Label custom — dibuat oleh sistem karena marketplace tidak menyediakan PDF label.
    </div>
</div>
</body>
</html>
