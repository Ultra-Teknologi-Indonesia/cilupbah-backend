@php
    /** @var object $return */
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->format('d M Y H:i');
    $items = collect($return->items ?? []);
    $money = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Retur Pembelian {{ $return->return_number }}</title>
    <style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            line-height: 1.35;
        }
        .header { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .header td { vertical-align: top; }
        .header .company {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .header .title { font-size: 20px; font-weight: 700; margin-top: 2px; }
        .header .right { text-align: right; }
        .header .doc-no { font-size: 12px; font-weight: 700; margin-top: 4px; }
        .info-grid { width: 100%; border-collapse: collapse; margin: 10px 0 14px; }
        .info-grid td { padding: 3px 0; font-size: 10px; vertical-align: top; }
        .info-grid .label { color: #555; width: 110px; }
        .info-grid .value { font-weight: 700; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 2px; }
        table.items th, table.items td {
            border: 1px solid #555;
            padding: 5px 6px;
            vertical-align: top;
            font-size: 9.5px;
        }
        table.items th {
            background: #efefef;
            text-align: center;
            font-weight: 700;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.items tfoot td {
            font-weight: 700;
            text-align: right;
        }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .center { text-align: center; }
        .num { text-align: right; }
        .footer {
            position: fixed;
            bottom: -8mm;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #555;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer .right { text-align: right; }
        .page-num:after { content: counter(page); }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="company">{{ $companyName }}</div>
                <div class="title">Retur Pembelian</div>
            </td>
            <td class="right">
                <div class="doc-no">{{ $return->return_number }}</div>
            </td>
        </tr>
    </table>

    <table class="info-grid">
        <tr>
            <td class="label">Pemasok</td>
            <td class="value">{{ optional($return->supplier ?? null)->name ?? '-' }}</td>
            <td class="label">Status</td>
            <td class="value">{{ $return->status }}</td>
        </tr>
        <tr>
            <td class="label">Lokasi</td>
            <td class="value">{{ optional($return->location ?? null)->location_name ?? '-' }}</td>
            <td class="label">Tgl. Retur</td>
            <td class="value">{{ optional($return->return_date ?? null) ? \Carbon\Carbon::parse($return->return_date)->format('d M Y') : '-' }}</td>
        </tr>
        @if(!empty($return->reason))
        <tr>
            <td class="label">Alasan</td>
            <td class="value" colspan="3">{{ $return->reason }}</td>
        </tr>
        @endif
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:24px">No</th>
                <th>SKU</th>
                <th>Nama Produk</th>
                <th style="width:50px">Qty</th>
                <th style="width:80px">Harga Satuan</th>
                <th style="width:80px">Subtotal</th>
                <th style="width:70px">Kondisi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $i => $item)
                @php
                    $product = $item->product ?? null;
                    $sku = $product['sku'] ?? '-';
                    $name = $product['name'] ?? '-';
                @endphp
                <tr>
                    <td class="center mono">{{ $i + 1 }}</td>
                    <td class="mono">{{ $sku }}</td>
                    <td>{{ $name }}</td>
                    <td class="num mono">{{ (int) $item->qty }}</td>
                    <td class="num mono">{{ $money($item->unit_price) }}</td>
                    <td class="num mono">{{ $money($item->subtotal) }}</td>
                    <td class="center">{{ $item->condition }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="center" style="padding: 18px;">Tidak ada item.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5"></td>
                <td class="num">Total</td>
                <td class="num mono">{{ $money($return->total_amount) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <table>
            <tr>
                <td>Tgl. Cetak: {{ $printedAt }}</td>
                <td class="right">Hal: <span class="page-num"></span></td>
            </tr>
        </table>
    </div>
</body>
</html>
