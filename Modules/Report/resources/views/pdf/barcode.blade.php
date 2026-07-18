@php
    /** @var array $cells */
    /** @var string $mode */
    /** @var string $paper */
    $paper = $paper ?? 'a4_multi';
    $showPrice = $mode === 'default' || $mode === 'online';
    $showStore = $mode === 'online';
    $fmtPrice = fn ($p) => $p !== null ? 'Rp' . number_format($p, 0, ',', '.') : '-';
    $perPage = in_array($paper, ['thermal_50x40', 'thermal_80x40', 'thermal_40x30', 'thermal_30x20', 'a4_single'], true);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Barcode Barang</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #000;
        }
        .empty { text-align: center; padding: 40px; color: #666; font-size: 11px; }

        @if($perPage)
        .page {
            width: 100%;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        .label { width: 100%; }
        table.label-row { width: 100%; border-collapse: collapse; }
        table.label-row td { vertical-align: middle; padding: 0; }
        td.qr-cell img { display: block; }
        td.text-cell { padding-left: 3mm; }
        .store {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 1mm;
            overflow: hidden;
            white-space: nowrap;
        }
        .sku {
            font-weight: 700;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }
        .name { line-height: 1.15; margin-top: 1mm; overflow: hidden; }
        .price { font-weight: 700; margin-top: 1mm; }
        @endif

        @if($paper === 'thermal_50x40')
        @page { margin: 2mm; }
        body { font-size: 7pt; }
        table.label-row td { height: 36mm; }
        td.qr-cell { width: 24mm; }
        td.qr-cell img { width: 23mm; height: 23mm; }
        td.text-cell { padding-left: 2mm; }
        .store { font-size: 6pt; }
        .sku { font-size: 9pt; }
        .name { font-size: 6pt; max-height: 9mm; }
        .price { font-size: 8pt; }
        @endif

        @if($paper === 'thermal_80x40')
        @page { margin: 2mm; }
        body { font-size: 8pt; }
        table.label-row td { height: 36mm; }
        td.qr-cell { width: 30mm; }
        td.qr-cell img { width: 29mm; height: 29mm; }
        .store { font-size: 7pt; }
        .sku { font-size: 11pt; }
        .name { font-size: 7pt; max-height: 10mm; }
        .price { font-size: 9pt; }
        @endif

        @if($paper === 'thermal_40x30')
        @page { margin: 1.5mm; }
        body { font-size: 6pt; }
        table.label-row td { height: 27mm; }
        td.qr-cell { width: 18mm; }
        td.qr-cell img { width: 17mm; height: 17mm; }
        td.text-cell { padding-left: 1.5mm; }
        .store { font-size: 5pt; }
        .sku { font-size: 7.5pt; }
        .name { font-size: 5pt; max-height: 7mm; }
        .price { font-size: 7pt; }
        @endif

        @if($paper === 'thermal_30x20')
        @page { margin: 1mm; }
        body { font-size: 5pt; }
        table.label-row td { height: 18mm; }
        td.qr-cell { width: 12mm; }
        td.qr-cell img { width: 11.5mm; height: 11.5mm; }
        td.text-cell { padding-left: 1.2mm; }
        .store { font-size: 4pt; margin-bottom: 0.3mm; }
        .sku { font-size: 6pt; }
        .name { font-size: 4pt; max-height: 5mm; margin-top: 0.3mm; }
        .price { font-size: 5.5pt; margin-top: 0.3mm; }
        @endif

        @if($paper === 'a4_single')
        @page { margin: 20mm; }
        body { font-size: 12pt; }
        td.qr-cell { width: 90mm; }
        td.qr-cell img { width: 85mm; height: 85mm; }
        .store { font-size: 12pt; }
        .sku { font-size: 20pt; letter-spacing: 1pt; }
        .name { font-size: 11pt; }
        .price { font-size: 16pt; }
        td.text-cell { padding-left: 12mm; }
        @endif

        @if(!$perPage)
        @page { margin: 8mm; }
        table.grid { width: 100%; border-collapse: separate; border-spacing: 6px; table-layout: fixed; }
        table.grid > tbody > tr > td {
            width: 50%;
            vertical-align: top;
            padding: 0;
        }
        .label {
            border: 1px solid #000;
            background: #fff;
            padding: 6px 8px;
            height: 78px;
        }
        table.label-row { width: 100%; border-collapse: collapse; }
        table.label-row td { vertical-align: middle; padding: 0; }
        td.qr-cell { width: 72px; }
        td.qr-cell img { width: 66px; height: 66px; display: block; }
        td.text-cell { padding-left: 8px; }
        .store {
            font-size: 7px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.3px; margin-bottom: 2px;
            overflow: hidden; white-space: nowrap;
        }
        .sku { font-size: 11px; font-weight: 700; line-height: 1.15; word-break: break-all; }
        .name { font-size: 8px; line-height: 1.15; margin-top: 4px; overflow: hidden; max-height: 20px; }
        .price { font-size: 9px; font-weight: 700; margin-top: 4px; }
        @endif
    </style>
</head>
<body>
    @if(count($cells) === 0)
        <div class="empty">Tidak ada label untuk dicetak. Pastikan produk terpilih memiliki
            {{ $mode === 'online' ? 'listing toko yang sudah tersinkron.' : 'SKU aktif.' }}</div>
    @elseif($perPage)
        @foreach($cells as $cell)
            <div class="page">
                <div class="label">
                    <table class="label-row">
                        <tr>
                            <td class="qr-cell">
                                @if($cell['qr'])
                                    <img src="{{ $cell['qr'] }}" alt="QR">
                                @endif
                            </td>
                            <td class="text-cell">
                                @if($showStore)
                                    <div class="store">{{ $cell['store_name'] ?: '—' }}</div>
                                @endif
                                <div class="sku">{{ $cell['sku'] }}</div>
                                <div class="name">{{ $cell['name'] }}</div>
                                @if($showPrice)
                                    <div class="price">{{ $fmtPrice($cell['price']) }}</div>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        @endforeach
    @else
        <table class="grid">
            @foreach(array_chunk($cells, 2) as $rowCells)
                <tr>
                    @foreach($rowCells as $cell)
                        <td>
                            <div class="label">
                                <table class="label-row">
                                    <tr>
                                        <td class="qr-cell">
                                            @if($cell['qr'])
                                                <img src="{{ $cell['qr'] }}" alt="QR">
                                            @endif
                                        </td>
                                        <td class="text-cell">
                                            @if($showStore)
                                                <div class="store">{{ $cell['store_name'] ?: '—' }}</div>
                                            @endif
                                            <div class="sku">{{ $cell['sku'] }}</div>
                                            <div class="name">{{ $cell['name'] }}</div>
                                            @if($showPrice)
                                                <div class="price">{{ $fmtPrice($cell['price']) }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    @endforeach
                    @for($i = count($rowCells); $i < 2; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
