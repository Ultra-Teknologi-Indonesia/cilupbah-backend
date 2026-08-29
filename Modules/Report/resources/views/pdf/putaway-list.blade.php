@php
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->timezone('Asia/Jakarta')->format('d M Y H:i');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Daftar Penempatan Barang</title>
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

        .header { text-align: center; margin-bottom: 10px; }
        .header .company { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #444; }
        .header .title { font-size: 19px; font-weight: 700; margin-top: 3px; }
        .header .tanggal { font-size: 9px; font-weight: 700; margin-top: 4px; }
        .header .lokasi { font-size: 10px; font-weight: 700; margin-top: 8px; }

        table.meta { width: 100%; border-collapse: collapse; margin: 12px 0 6px; }
        table.meta td { padding: 2px 0; vertical-align: top; }
        table.meta td.label { width: 90px; font-weight: 700; }
        table.meta td.sep { width: 10px; }

        table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.items th {
            font-size: 8px;
            font-weight: 700;
            padding: 5px 4px;
            border: 1px solid #222;
            text-align: left;
        }
        table.items td { padding: 4px; border: 1px solid #222; vertical-align: top; }
        table.items td.no { text-align: center; width: 26px; }
        table.items td.qty, table.items th.qty { text-align: right; width: 46px; }
        table.items th.qty { text-align: right; }

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
    <div class="title">Daftar Penempatan Barang</div>
    <div class="tanggal">Tanggal: &nbsp; {{ $tanggal }}</div>
    <div class="lokasi">{{ $lokasi }}</div>
</div>

@if (empty($documents))
    <div class="empty">Tidak ada penempatan pada tanggal dan lokasi ini.</div>
@endif

@foreach ($documents as $doc)
    <table class="meta">
        <tr>
            <td class="label">No. Putaway</td><td class="sep">:</td>
            <td>{{ $doc['putaway_no'] }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal</td><td class="sep">:</td>
            <td>{{ $doc['tanggal'] }}</td>
        </tr>
        <tr>
            <td class="label">Runner</td><td class="sep">:</td>
            <td>{{ $doc['runner'] }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 26px; text-align: center;">No</th>
                <th style="width: 110px;">SKU</th>
                <th>Deskripsi</th>
                <th style="width: 70px;">Serial No</th>
                <th style="width: 60px;">Batch No</th>
                <th style="width: 92px;">Sumber</th>
                <th style="width: 84px;">Kode Rak</th>
                <th class="qty">Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['rows'] as $row)
                <tr>
                    <td class="no">{{ $row['no'] }}</td>
                    <td>{{ $row['sku'] }}</td>
                    <td>{{ $row['deskripsi'] }}</td>
                    <td>{{ $row['serial_no'] }}</td>
                    <td>{{ $row['batch_no'] }}</td>
                    <td>{{ $row['sumber'] }}</td>
                    <td>{{ $row['kode_rak'] }}</td>
                    <td class="qty">{{ $row['qty'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach

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
