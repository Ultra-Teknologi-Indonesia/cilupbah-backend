@php
    /** @var \Illuminate\Support\Collection $groups */
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->format('d M Y H:i');
    $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y H.i.s') : '-';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Picklist</title>
    <style>
        @page { margin: 16mm 12mm 18mm 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }

        .header { text-align: center; margin-bottom: 16px; }
        .header .company { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #444; }
        .header .title { font-size: 20px; font-weight: 700; margin-top: 3px; }

        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.meta td { padding: 2px 0; vertical-align: top; }
        table.meta td.label { width: 120px; font-weight: 700; }
        table.meta td.sep { width: 10px; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 6px 6px;
            border: 1px solid #222;
            background: #f4f4f4;
        }
        table.items td {
            padding: 6px 6px;
            border: 1px solid #222;
            vertical-align: bottom;
        }
        table.items td.order { vertical-align: middle; font-size: 10px; }
        table.items td.photo { text-align: center; vertical-align: middle; width: 62px; }
        table.items td.photo img { width: 52px; height: 52px; }
        table.items td.product { vertical-align: middle; }
        table.items td.qty { text-align: right; width: 62px; }
        table.items td.loc { padding: 0; width: 120px; }
        table.items td.loc .line { padding: 5px 6px; }
        table.items td.loc .line + .line { border-top: 1px solid #222; }

        th.qty-h, th.loc-h { text-align: right; }
        th.loc-h { text-align: right; }

        .footer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #555;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer td { padding: 0; }
        .footer .right { text-align: right; }
        .page-num:after { content: counter(page); }
    </style>
</head>
<body>

<div class="header">
    <div class="company">{{ $companyName }}</div>
    <div class="title">Detail Picklist</div>
</div>

<table class="meta">
    <tr>
        <td class="label">Picklist</td><td class="sep">:</td>
        <td>{{ $picklist->picklist_no }}</td>
    </tr>
    <tr>
        <td class="label">Tanggal Transaksi</td><td class="sep">:</td>
        <td>{{ $fmt($picklist->created_at) }}</td>
    </tr>
    <tr>
        <td class="label">Tanggal Selesai</td><td class="sep">:</td>
        <td>{{ $fmt($picklist->completed_at) }}</td>
    </tr>
    <tr>
        <td class="label">Picker</td><td class="sep">:</td>
        <td>{{ $picklist->picker?->name ?? '-' }}</td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Pesanan</th>
            <th>Foto</th>
            <th>Produk</th>
            <th class="qty-h">Qty Pesan</th>
            <th class="loc-h">Lokasi / Rak</th>
            <th class="qty-h">Qty Ambil</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($groups as $group)
            @foreach ($group['rows'] as $i => $row)
                <tr>
                    @if ($i === 0)
                        <td class="order" rowspan="{{ count($group['rows']) }}">{{ $group['order_no'] }}</td>
                    @endif
                    <td class="photo">
                        @if (! empty($row['image_url']))
                            <img src="{{ $row['image_url'] }}" alt="">
                        @endif
                    </td>
                    <td class="product">{{ $row['sku'] }}@if ($row['product_name']) - {{ $row['product_name'] }}@endif</td>
                    <td class="qty">{{ $row['qty_ordered'] }}</td>
                    <td class="loc">
                        <div class="line">{{ $row['location_name'] ?? '-' }}</div>
                        <div class="line">{{ $row['bin_code'] ?? '-' }}</div>
                    </td>
                    <td class="qty">{{ $row['qty_picked'] }}</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
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
