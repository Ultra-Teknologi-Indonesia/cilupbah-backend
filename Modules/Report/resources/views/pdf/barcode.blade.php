@php
    /** @var array $cells */
    /** @var string $mode */
    $showPrice = $mode === 'default' || $mode === 'online';
    $showStore = $mode === 'online';
    $fmtPrice = fn ($p) => $p !== null ? 'Rp' . number_format($p, 0, ',', '.') : '-';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Barcode Barang</title>
    <style>
        @page { margin: 8mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #000;
            margin: 0;
            padding: 0;
        }
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
            box-sizing: border-box;
        }
        table.label-row { width: 100%; border-collapse: collapse; }
        table.label-row > tr > td, table.label-row td { vertical-align: middle; padding: 0; }
        td.qr-cell { width: 72px; }
        td.qr-cell img { width: 66px; height: 66px; display: block; }
        td.text-cell { padding-left: 8px; }
        .store {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
            overflow: hidden;
            white-space: nowrap;
        }
        .sku {
            font-size: 11px;
            font-weight: 700;
            line-height: 1.15;
            word-break: break-all;
        }
        .name {
            font-size: 8px;
            line-height: 1.15;
            margin-top: 4px;
            overflow: hidden;
            max-height: 20px;
        }
        .price {
            font-size: 9px;
            font-weight: 700;
            margin-top: 4px;
        }
        .empty { text-align: center; padding: 40px; color: #666; font-size: 11px; }
    </style>
</head>
<body>
    @if(count($cells) === 0)
        <div class="empty">Tidak ada label untuk dicetak. Pastikan produk terpilih memiliki
            {{ $mode === 'online' ? 'listing toko yang sudah tersinkron.' : 'SKU aktif.' }}</div>
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
