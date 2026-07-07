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
            color: #111;
            margin: 0;
            padding: 0;
        }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid td {
            width: 33.33%;
            vertical-align: top;
            padding: 4px;
        }
        .label {
            border: 1px solid #e2e2e2;
            border-radius: 4px;
            padding: 6px 4px;
            text-align: center;
            height: 128px;
            box-sizing: border-box;
        }
        .label .store {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            height: 9px;
            overflow: hidden;
            white-space: nowrap;
        }
        .label .name {
            font-size: 7px;
            line-height: 1.15;
            height: 24px;
            overflow: hidden;
            margin-bottom: 2px;
        }
        .label .qr { margin: 1px auto; }
        .label .qr img { width: 62px; height: 62px; }
        .label .sku {
            font-size: 8.5px;
            font-weight: 700;
            margin-top: 2px;
            word-break: break-all;
        }
        .label .price {
            font-size: 8.5px;
            font-weight: 700;
            margin-top: 1px;
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
            @foreach(array_chunk($cells, 3) as $rowCells)
                <tr>
                    @foreach($rowCells as $cell)
                        <td>
                            <div class="label">
                                @if($showStore)
                                    <div class="store">{{ $cell['store_name'] ?: '—' }}</div>
                                @else
                                    <div class="name">{{ $cell['name'] }}</div>
                                @endif
                                @if($cell['qr'])
                                    <div class="qr"><img src="{{ $cell['qr'] }}" alt="QR"></div>
                                @endif
                                <div class="sku">{{ $cell['sku'] }}</div>
                                @if($showPrice)
                                    <div class="price">{{ $fmtPrice($cell['price']) }}</div>
                                @endif
                            </div>
                        </td>
                    @endforeach
                    @for($i = count($rowCells); $i < 3; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
