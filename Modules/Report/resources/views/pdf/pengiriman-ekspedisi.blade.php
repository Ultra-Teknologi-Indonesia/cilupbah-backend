@php
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->timezone('Asia/Jakarta')->format('d M Y H:i');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 14mm 10mm 16mm 10mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            line-height: 1.35;
        }

        .header { text-align: center; margin-bottom: 12px; }
        .header .company { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #444; }
        .header .title { font-size: 18px; font-weight: 700; margin-top: 3px; }
        .header .periode { font-size: 9px; font-weight: 700; margin-top: 3px; }

        .rule { border-top: 1px solid #222; margin: 8px 0 10px; }

        .ekspedisi { font-size: 9px; font-weight: 700; margin: 12px 0 5px; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.data th {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            padding: 5px 5px;
            border: 1px solid #222;
            text-align: left;
        }
        table.data th.right { text-align: right; }
        table.data td { padding: 4px 5px; border: 1px solid #222; }
        td.right { text-align: right; }
        tr.total td { font-weight: 700; }
        tr.total td.label { text-align: center; }

        table.grand { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.grand td { padding: 5px; border: 1px solid #222; font-weight: 700; }
        table.grand td.label { text-align: center; }
        table.grand td.right { text-align: right; }

        .footer {
            position: fixed;
            bottom: -9mm;
            left: 0;
            right: 0;
            font-size: 8px;
            color: #555;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer td { padding: 0; border: none; }
        .footer .right { text-align: right; }
        .page-num:after { content: counter(page); }

        .empty { font-size: 9px; color: #666; padding: 12px 0; }
    </style>
</head>
<body>

<div class="header">
    <div class="company">{{ $companyName }}</div>
    <div class="title">{{ $title }}</div>
    <div class="periode">{{ $periode }}</div>
</div>

<div class="rule"></div>

@if ($detail)
    @if (empty($groups))
        <div class="empty">Tidak ada pengiriman pada rentang tanggal ini.</div>
    @endif

    @foreach ($groups as $group)
        <div class="ekspedisi">Nama Ekspedisi &nbsp; {{ $group['ekspedisi'] }}</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Tanggal SHP</th>
                    <th>Kode Pengiriman</th>
                    <th>No Pesanan</th>
                    <th>No Resi</th>
                    <th class="right">Quantity</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group['rows'] as $row)
                    <tr>
                        <td>{{ $row['tanggal'] }}</td>
                        <td>{{ $row['kode_pengiriman'] }}</td>
                        <td>{{ $row['no_pesanan'] }}</td>
                        <td>{{ $row['no_resi'] }}</td>
                        <td class="right">{{ $row['qty'] }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td class="label" colspan="4">Total</td>
                    <td class="right">{{ $group['total']['total_quantity'] }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach
@else
    <table class="data">
        <thead>
            <tr>
                <th>Nama Ekspedisi</th>
                <th class="right">Total Pesanan</th>
                <th class="right">Total Quantity</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($summary as $row)
                <tr>
                    <td>{{ $row['ekspedisi'] }}</td>
                    <td class="right">{{ $row['total_pesanan'] }}</td>
                    <td class="right">{{ $row['total_quantity'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Tidak ada pengiriman pada rentang tanggal ini.</td></tr>
            @endforelse
            <tr class="total">
                <td class="label">Total</td>
                <td class="right">{{ $grandTotal['total_pesanan'] }}</td>
                <td class="right">{{ $grandTotal['total_quantity'] }}</td>
            </tr>
        </tbody>
    </table>

    <table class="grand">
        <tr>
            <td class="label">Grand Total</td>
            <td class="right">{{ $grandTotal['total_pesanan'] }}</td>
            <td class="right">{{ $grandTotal['total_quantity'] }}</td>
        </tr>
    </table>
@endif

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
