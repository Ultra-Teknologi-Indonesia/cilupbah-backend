@php
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->timezone('Asia/Jakarta')->format('d M Y H:i');

    // Laporan Penempatan memakai susunan kolom Summary yang lebih pendek, punya
    // baris Total di dalam tabel Detail, dan tidak punya Grand Total.
    $summaryColumns = $summaryColumns ?? [
        ['key' => 'total_transaksi', 'label' => 'Total Transaksi', 'align' => 'right'],
        ['key' => 'total_quantity', 'label' => 'Total Quantity', 'align' => 'right'],
        ['key' => 'durasi', 'label' => 'Durasi', 'align' => 'center'],
        ['key' => 'durasi_per_pesanan', 'label' => 'Durasi Per Pesanan', 'align' => 'center'],
    ];
    $detailTotals = $detailTotals ?? false;
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

        .group-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 6px; }
        .sub-label { font-size: 9px; font-weight: 700; border-bottom: 1px solid #222; display: inline-block; padding-bottom: 2px; margin: 10px 0 4px; }
        .sub-name { font-size: 9px; margin-bottom: 5px; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.data th {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            padding: 5px 5px;
            border: 1px solid #222;
            text-align: center;
        }
        table.data td { padding: 4px 5px; border: 1px solid #222; }
        td.right, th.right { text-align: right; }
        td.center, th.center { text-align: center; }
        tr.total td { font-weight: 700; }
        tr.total td.label { text-align: center; }

        table.grand { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.grand td { padding: 5px; border: 1px solid #222; font-weight: 700; }
        table.grand td.label { text-align: center; }

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

@if (empty($groups))
    <div class="empty">Tidak ada data pada rentang tanggal ini.</div>
@endif

@if ($detail)
    @foreach ($groups as $group)
        <div class="group-label">Lokasi Gudang &nbsp; {{ $group['lokasi'] }}</div>

        @foreach ($group['sub'] as $sub)
            @if ($secondaryLabel)
                <div class="sub-label">{{ $secondaryLabel }}</div>
                <div class="sub-name">{{ $sub['nama'] !== '' ? $sub['nama'] : '-' }}</div>
            @endif

            <table class="data">
                <thead>
                    <tr>
                        @foreach ($columns as $col)
                            <th class="{{ $col['align'] ?? '' }}">{{ $col['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sub['rows'] as $row)
                        <tr>
                            @foreach ($columns as $col)
                                <td class="{{ $col['align'] ?? '' }}">{{ $row[$col['key']] ?? '-' }}</td>
                            @endforeach
                        </tr>
                    @endforeach

                    @if ($detailTotals && ! empty($sub['total']))
                        <tr class="total">
                            <td class="label">Total</td>
                            @foreach (array_slice($columns, 1) as $col)
                                <td class="{{ $col['align'] ?? '' }}">{{ $sub['total'][$col['key']] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        @endforeach
    @endforeach
@else
    @foreach ($groups as $group)
        <div class="group-label">{{ $summaryGroupLabel }} &nbsp; {{ $group['nama'] }}</div>

        <table class="data">
            <thead>
                <tr>
                    <th style="text-align: left;">{{ $summaryFirstLabel }}</th>
                    @foreach ($summaryColumns as $col)
                        <th class="{{ $col['align'] ?? '' }}">{{ $col['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($group['rows'] as $row)
                    <tr>
                        <td>{{ $row['nama'] }}</td>
                        @foreach ($summaryColumns as $col)
                            <td class="{{ $col['align'] ?? '' }}">{{ $row[$col['key']] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
                <tr class="total">
                    <td class="label">Total</td>
                    @foreach ($summaryColumns as $col)
                        <td class="{{ $col['align'] ?? '' }}">{{ $group['total'][$col['key']] ?? '' }}</td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    @endforeach

    @if ($grandTotal)
        <table class="grand">
            <tr>
                <td class="label">Grand Total</td>
                @foreach ($summaryColumns as $col)
                    <td style="text-align: {{ ($col['align'] ?? 'left') === 'right' ? 'right' : 'center' }};">
                        {{ $grandTotal[$col['key']] ?? '' }}
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
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
