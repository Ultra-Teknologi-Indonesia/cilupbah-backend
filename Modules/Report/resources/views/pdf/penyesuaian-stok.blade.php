@php
    /** @var \Illuminate\Support\Collection $groups */
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->format('d M Y');
    $periodLabel = \Carbon\Carbon::parse($start)->format('d M Y') . ' - ' . \Carbon\Carbon::parse($end)->format('d M Y');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Daftar Penyesuaian Stok</title>
    <style>
        @page { margin: 16mm 12mm 16mm 12mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            line-height: 1.35;
        }
        .header { text-align: center; margin-bottom: 12px; }
        .header .company { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .header .title { font-size: 20px; font-weight: 700; margin-top: 4px; }
        .header .period { font-size: 10px; margin-top: 4px; color: #333; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th {
            text-align: left;
            font-weight: 700;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #000;
            padding: 4px 6px;
        }
        table.items td {
            font-size: 9.5px;
            padding: 3px 6px;
            vertical-align: top;
        }
        .col-sku { width: 13%; }
        .col-nama { width: 27%; }
        .col-unit { width: 7%; }
        .col-tanggal { width: 10%; }
        .col-sumber { width: 14%; }
        .col-note { width: 19%; }
        .col-qty { width: 10%; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        tr.total-row td { border-top: 1px solid #000; font-weight: 700; padding-top: 4px; }
        .footer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #333;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer .right { text-align: right; }
        .page-num:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $companyName }}</div>
        <div class="title">Daftar Penyesuaian Stok</div>
        <div class="period">{{ $periodLabel }}</div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="col-sku">SKU</th>
                <th class="col-nama">NAMA</th>
                <th class="col-unit">UNIT</th>
                <th class="col-tanggal">TANGGAL</th>
                <th class="col-sumber">SUMBER</th>
                <th class="col-note">NOTE</th>
                <th class="col-qty">QTY</th>
            </tr>
        </thead>
        <tbody>
            @forelse($groups as $group)
                @foreach($group['rows'] as $i => $row)
                    <tr>
                        @if($i === 0)
                            <td class="col-sku" rowspan="{{ count($group['rows']) }}">{{ $group['sku'] }}</td>
                            <td class="col-nama" rowspan="{{ count($group['rows']) }}">{{ $group['name'] }}</td>
                            <td class="col-unit" rowspan="{{ count($group['rows']) }}">{{ $group['unit'] }}</td>
                        @endif
                        <td class="col-tanggal">{{ \Carbon\Carbon::parse($row['date'])->format('d M Y') }}</td>
                        <td class="col-sumber">{{ $row['source'] }}</td>
                        <td class="col-note">{{ $row['note'] !== null && $row['note'] !== '' ? $row['note'] : '-' }}</td>
                        <td class="col-qty num">{{ number_format($row['qty'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="6" class="num">TOTAL</td>
                    <td class="num">{{ number_format($group['total'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 24px; color: #666;">
                        Tidak ada data penyesuaian pada periode dan filter ini.
                    </td>
                </tr>
            @endforelse
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
