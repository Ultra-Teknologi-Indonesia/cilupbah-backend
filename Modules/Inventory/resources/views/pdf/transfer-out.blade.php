@php
    /** @var object $transfer */
    $printedAt = now()->format('d M Y H:i');
    $items = collect($transfer->items ?? []);
    $sourceName = optional($transfer->sourceLocation ?? $transfer->source_location ?? null)->location_name ?? '-';
    $destName = optional($transfer->destinationLocation ?? $transfer->destination_location ?? null)->location_name ?? '-';
    $tanggal = optional($transfer->created_at)->format('d M Y') ?? '-';
    $totalQty = (int) $items->sum('qty');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Jalan {{ $transfer->transfer_number }}</title>
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
        .title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            margin: 4px 0 18px;
        }
        .info-grid { width: 100%; border-collapse: collapse; margin: 0 0 12px; }
        .info-grid td { padding: 2px 0; font-size: 10px; vertical-align: top; }
        .info-grid .label { color: #333; width: 70px; font-weight: 700; }
        .info-grid .sep { width: 10px; }
        .info-grid .value { }
        .info-grid .r-label { text-align: right; width: 90px; font-weight: 700; }
        .info-grid .r-value { text-align: right; width: 130px; }
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
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .center { text-align: center; }
        .num { text-align: right; }
        .total-row td {
            border: none;
            padding-top: 6px;
            font-weight: 700;
            font-size: 10px;
        }
        .catatan { margin-top: 14px; font-size: 10px; }
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
    <div class="title">Surat Jalan</div>

    <table class="info-grid">
        <tr>
            <td class="label">DARI</td>
            <td class="sep">:</td>
            <td class="value">{{ $sourceName }}</td>
            <td class="r-label">No. Transfer</td>
            <td class="r-value mono">{{ $transfer->transfer_number }}</td>
        </tr>
        <tr>
            <td class="label">TUJUAN</td>
            <td class="sep">:</td>
            <td class="value">{{ $destName }}</td>
            <td class="r-label">Tanggal</td>
            <td class="r-value">{{ $tanggal }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:24px">No</th>
                <th style="width:90px">Rak</th>
                <th style="width:90px">Lokasi</th>
                <th style="width:110px">SKU</th>
                <th>Nama Barang</th>
                <th style="width:44px">Qty</th>
                <th style="width:44px">Unit</th>
                <th style="width:110px">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $i => $item)
                @php
                    $variant = $item->product ?? null;
                    $sku = optional($variant)->sku ?? '-';
                    $name = optional(optional($variant)->product)->name ?? '-';
                    $rak = optional($item->sourceBin ?? null)->bin_final_code ?? '-';
                    $ket = $item->item_notes ?? '';
                @endphp
                <tr>
                    <td class="center mono">{{ $i + 1 }}</td>
                    <td class="mono">{{ $rak }}</td>
                    <td>{{ $destName }}</td>
                    <td class="mono">{{ $sku }}</td>
                    <td>{{ $name }}</td>
                    <td class="num mono">{{ (int) $item->qty }}</td>
                    <td class="center">Buah</td>
                    <td>{{ $ket }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="center" style="padding: 18px;">Tidak ada item.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table style="width:100%; border-collapse:collapse;">
        <tr class="total-row">
            <td class="num">Total Qty</td>
            <td class="num mono" style="width:44px">{{ $totalQty }}</td>
            <td style="width:110px"></td>
        </tr>
    </table>

    <div class="catatan">Catatan : {{ $transfer->notes ?? '' }}</div>

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
