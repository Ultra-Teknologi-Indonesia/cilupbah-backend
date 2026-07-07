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

        .header { text-align: center; margin-bottom: 14px; }
        .header .company { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #444; }
        .header .title { font-size: 20px; font-weight: 700; margin-top: 3px; }
        .header .period { font-size: 10px; margin-top: 3px; color: #666; }

        /* Column header strip, shared by every group */
        table.head-strip { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        table.head-strip th {
            text-align: left;
            font-weight: 700;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #555;
            padding: 5px 8px;
            border-bottom: 1.5px solid #222;
        }
        .col-tanggal { width: 16%; }
        .col-sumber  { width: 22%; }
        .col-note    { width: 44%; }
        .col-qty     { width: 18%; }
        th.col-qty, td.col-qty { text-align: right; }

        /* One block per SKU */
        .group { margin-bottom: 12px; page-break-inside: avoid; }

        table.group-head { width: 100%; border-collapse: collapse; }
        table.group-head td {
            background: #f0f1f3;
            border-left: 3px solid #333;
            padding: 6px 8px;
            vertical-align: top;
        }
        .group-head .sku { font-size: 11px; font-weight: 700; letter-spacing: 0.2px; }
        .group-head .unit {
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #555;
            text-align: right;
            white-space: nowrap;
        }
        .group-head .name { font-size: 9.5px; color: #444; margin-top: 2px; }

        table.rows { width: 100%; border-collapse: collapse; }
        table.rows td {
            font-size: 9.5px;
            padding: 4px 8px;
            vertical-align: top;
            border-bottom: 1px solid #eee;
        }
        table.rows tr.zebra td { background: #fafafa; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .qty-neg { color: #c0392b; }
        .qty-pos { color: #1e7d34; }
        .muted { color: #888; }

        tr.total-row td {
            border-top: 1.5px solid #222;
            border-bottom: none;
            font-weight: 700;
            padding-top: 6px;
            padding-bottom: 2px;
        }
        tr.total-row .label { text-transform: uppercase; letter-spacing: 0.5px; font-size: 9px; color: #333; }

        .empty { text-align: center; padding: 32px; color: #888; font-size: 11px; }

        .footer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 8.5px;
            color: #777;
            border-top: 1px solid #ddd;
            padding-top: 3px;
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
        <div class="title">Daftar Penyesuaian Stok</div>
        <div class="period">{{ $periodLabel }}</div>
    </div>

    <table class="head-strip">
        <tr>
            <th class="col-tanggal">Tanggal</th>
            <th class="col-sumber">Sumber</th>
            <th class="col-note">Catatan</th>
            <th class="col-qty">Qty</th>
        </tr>
    </table>

    @forelse($groups as $group)
        <div class="group">
            <table class="group-head">
                <tr>
                    <td>
                        <table style="width:100%; border-collapse:collapse;">
                            <tr>
                                <td style="background:transparent; border:none; padding:0;">
                                    <span class="sku">{{ $group['sku'] }}</span>
                                </td>
                                <td style="background:transparent; border:none; padding:0;" class="unit">
                                    {{ $group['unit'] }}
                                </td>
                            </tr>
                        </table>
                        <div class="name">{{ $group['name'] }}</div>
                    </td>
                </tr>
            </table>

            <table class="rows">
                @foreach($group['rows'] as $i => $row)
                    <tr class="{{ $i % 2 === 1 ? 'zebra' : '' }}">
                        <td class="col-tanggal">{{ \Carbon\Carbon::parse($row['date'])->format('d M Y') }}</td>
                        <td class="col-sumber">{{ $row['source'] }}</td>
                        <td class="col-note">{{ $row['note'] !== null && $row['note'] !== '' ? $row['note'] : '—' }}</td>
                        <td class="col-qty num {{ $row['qty'] < 0 ? 'qty-neg' : ($row['qty'] > 0 ? 'qty-pos' : 'muted') }}">
                            {{ $row['qty'] > 0 ? '+' : '' }}{{ number_format($row['qty'], 0, ',', '.') }}
                        </td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3" class="label num">Total Penyesuaian</td>
                    <td class="col-qty num {{ $group['total'] < 0 ? 'qty-neg' : ($group['total'] > 0 ? 'qty-pos' : '') }}">
                        {{ $group['total'] > 0 ? '+' : '' }}{{ number_format($group['total'], 0, ',', '.') }}
                    </td>
                </tr>
            </table>
        </div>
    @empty
        <div class="empty">Tidak ada data penyesuaian pada periode dan filter ini.</div>
    @endforelse

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
