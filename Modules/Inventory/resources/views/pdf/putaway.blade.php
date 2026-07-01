@php
    /** @var \Modules\Inventory\Models\Putaway $putaway */
    /** @var string|null $qrDataUri */
    /** @var string $sourceLabel */
    $items = collect($putaway->items ?? []);
    $locationName = optional($putaway->location)->location_name ?? '-';
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->format('d M Y H:i');
    $assigneeName = optional($putaway->assignee)->name ?? '-';
    $creatorName = optional($putaway->creator)->name ?? '-';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Putaway {{ $putaway->putaway_no }}</title>
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
        .header .title {
            font-size: 20px;
            font-weight: 700;
            margin-top: 2px;
        }
        .header .right { text-align: right; }
        .header .putaway-no {
            font-size: 12px;
            font-weight: 700;
            margin-top: 4px;
        }
        .header .qr img { width: 78px; height: 78px; }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 10px 0;
        }
        .meta-table td {
            padding: 3px 0;
            font-size: 10px;
            vertical-align: top;
        }
        .meta-table .label {
            color: #555;
            width: 140px;
        }
        .meta-table .value {
            font-weight: 700;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2px;
        }
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
        .mono, .num {
            font-family: DejaVu Sans Mono, monospace;
            font-variant-numeric: tabular-nums;
        }
        .center { text-align: center; }
        .num { text-align: right; }
        .col-no { width: 28px; }
        .col-qty { width: 50px; }
        .col-date { width: 80px; }
        .col-source { width: 80px; }
        .col-rak { width: 110px; }
        .rec-line { margin-bottom: 1px; }
        .rec-qty { font-weight: 400; font-size: 8.5px; color: #333; }
        .barang-sku {
            font-family: DejaVu Sans Mono, monospace;
            font-weight: 700;
            font-size: 10px;
        }
        .barang-name {
            margin-top: 2px;
            font-size: 9.5px;
        }
        .rak-bin {
            font-family: DejaVu Sans Mono, monospace;
            font-weight: 700;
            font-size: 10px;
        }
        .footer {
            position: fixed;
            bottom: -8mm;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #555;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer td { padding: 0 2px; }
        .footer .right { text-align: right; }
        .page-num:after { content: counter(page); }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="company">{{ $companyName }}</div>
                <div class="title">Laporan Putaway</div>
            </td>
            <td class="right qr">
                @if($qrDataUri)
                    <img src="{{ $qrDataUri }}" alt="QR">
                @endif
                <div class="putaway-no">{{ $putaway->putaway_no }}</div>
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td class="label">No Putaway</td>
            <td class="value">: {{ $putaway->putaway_no }}</td>
        </tr>
        <tr>
            <td class="label">Gudang</td>
            <td class="value">: {{ $locationName }}</td>
        </tr>
        <tr>
            <td class="label">Ditugaskan Kepada</td>
            <td class="value">: {{ $assigneeName }}</td>
        </tr>
        <tr>
            <td class="label">Dibuat Oleh</td>
            <td class="value">: {{ $creatorName }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="col-no">No</th>
                <th>SKU</th>
                <th>Nama Barang</th>
                <th class="col-qty">Qty</th>
                <th class="col-date">Tgl. Penerimaan</th>
                <th class="col-source">Sumber</th>
                <th class="col-rak">Rekomendasi Rak</th>
                <th class="col-rak">Kode Rak</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $i => $item)
                @php
                    $sku = optional($item->product)->sku ?? '-';
                    $productName = optional(optional($item->product)->product)->name ?? optional($item->product)->name ?? '-';
                    $sourceRef = $sourceLabel ?? '-';
                    $placedBins = collect($item->placements ?? [])->map(fn($p) => [
                        'code' => optional($p->bin)->bin_final_code ?? '-',
                        'qty' => (int) $p->qty,
                    ])->all();
                    $recommendedBins = $item->recommended_bins ?? [];
                    $receivedAt = $putaway->created_at ? \Carbon\Carbon::parse($putaway->created_at)->format('d M Y') : '-';
                @endphp
                <tr>
                    <td class="center mono">{{ $i + 1 }}</td>
                    <td class="barang-sku">{{ $sku }}</td>
                    <td>{{ $productName }}</td>
                    <td class="num mono">{{ (int) $item->qty }}</td>
                    <td class="center">{{ $receivedAt }}</td>
                    <td class="center">{{ $sourceRef }}</td>
                    <td class="rak-bin" style="font-size: 9px;">
                        @if(count($recommendedBins) > 0)
                            @foreach($recommendedBins as $rec)
                                <div class="rec-line">{{ $rec['code'] }} <span class="rec-qty">({{ $rec['qty'] }})</span></div>
                            @endforeach
                        @else
                            -
                        @endif
                    </td>
                    <td class="rak-bin" style="font-size: 9px;">
                        @if(count($placedBins) > 0)
                            @foreach($placedBins as $placed)
                                <div class="rec-line">{{ $placed['code'] }} <span class="rec-qty">({{ $placed['qty'] }})</span></div>
                            @endforeach
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="center" style="padding: 18px;">Tidak ada item.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <table>
            <tr>
                <td>Dicetak tanggal: {{ $printedAt }} &nbsp;|&nbsp; Dicetak oleh: {{ $printedBy ?? '-' }}</td>
                <td class="right">Hal: <span class="page-num"></span></td>
            </tr>
        </table>
    </div>
</body>
</html>
