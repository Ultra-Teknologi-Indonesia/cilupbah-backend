@php
    /** @var \Modules\Outbound\Models\Picklist $picklist */
    $items = collect($picklist->items ?? []);
    // Sort by rekomendasi bin code agar picker bisa walk bin-by-bin
    $items = $items->sortBy(fn ($it) => $it->recommended_bin_code ?? 'zzz')->values();
    $totalQtyOrdered = $items->sum('qty_ordered');
    $totalItems = $items->count();
    $status = $picklist->status ?? 'DRAFT';
    $createdAt = $picklist->created_at ? \Illuminate\Support\Carbon::parse($picklist->created_at)->format('d M Y H:i') : '-';
    $locationName = optional($picklist->location)->location_name ?? '-';
    $locationCode = optional($picklist->location)->location_code ?? '';
    $pickerName = optional($picklist->picker)->name ?? '-';
    $pickerEmail = optional($picklist->picker)->email ?? '';

    $resolveVariantName = function ($item) {
        $variant = $item->product ?? null;
        $parentName = optional(optional($variant)->product)->name;
        $optionValues = collect(optional($variant)->options ?? [])
            ->map(fn ($o) => trim((string) ($o->value ?? '')))
            ->filter()
            ->values();
        if ($optionValues->isNotEmpty()) {
            return $optionValues->implode(' / ');
        }

        $desc = trim((string) ($item->orderItem->description ?? ''));
        if ($desc !== '' && $parentName) {
            $stripped = trim(str_replace($parentName, '', $desc), " -|/,");
            if ($stripped !== '' && $stripped !== $desc) {
                return $stripped;
            }
        }
        if ($desc !== '' && $desc !== $parentName) {
            return $desc;
        }

        return $item->sku ?? optional($variant)->sku ?? '-';
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Picklist {{ $picklist->picklist_no }}</title>
    <style>
        @page { margin: 16mm 12mm 16mm 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            line-height: 1.35;
        }
        .header {
            border-bottom: 2px solid #111;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .title {
            font-size: 11px;
            letter-spacing: 2px;
            color: #666;
            text-transform: uppercase;
        }
        .picklist-no {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 1px;
            margin-top: 2px;
        }
        .header .right { text-align: right; vertical-align: top; }
        .header .meta { color: #555; font-size: 9px; }
        .info {
            width: 100%;
            margin-bottom: 10px;
            border-collapse: collapse;
        }
        .info td {
            vertical-align: top;
            padding: 2px 4px;
            font-size: 10px;
        }
        .info .label {
            color: #666;
            width: 70px;
            white-space: nowrap;
        }
        .info .col-left { width: 60%; }
        .info .col-right { width: 40%; }
        .info .pill {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: 700;
            background: #eef2ff;
            color: #3730a3;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.items th, table.items td {
            border: 1px solid #999;
            padding: 4px 5px;
            vertical-align: top;
            font-size: 9.5px;
        }
        table.items th {
            background: #f1f5f9;
            text-align: left;
            font-weight: 700;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .mono, .num {
            font-family: DejaVu Sans Mono, monospace;
            font-variant-numeric: tabular-nums;
        }
        .num { text-align: right; }
        .center { text-align: center; }
        .check-box {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 1.5px solid #333;
            border-radius: 2px;
        }
        .bin-cell {
            font-weight: 700;
            background: #fafafa;
        }
        .footer {
            position: fixed;
            bottom: 4mm;
            left: 0;
            right: 0;
            width: 100%;
        }
        .sign-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 28px;
        }
        .sign-table td {
            text-align: center;
            vertical-align: top;
            padding: 4px 8px;
            width: 50%;
            font-size: 10px;
        }
        .sign-box {
            margin: 32px 18px 4px 18px;
            border-bottom: 1px solid #333;
            height: 4px;
        }
        .small { font-size: 8.5px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td>
                    <div class="title">Dokumen Picking</div>
                    <div class="picklist-no">{{ $picklist->picklist_no }}</div>
                </td>
                <td class="right">
                    <div class="meta">Dicetak: {{ now()->format('d M Y H:i') }}</div>
                    <div class="meta">Status: <span class="pill">{{ $status }}</span></div>
                </td>
            </tr>
        </table>
    </div>

    <table class="info">
        <tr>
            <td class="col-left">
                <table>
                    <tr><td class="label">Lokasi</td><td>: {{ $locationName }}@if($locationCode) <span class="small">({{ $locationCode }})</span>@endif</td></tr>
                    <tr><td class="label">Picker</td><td>: {{ $pickerName }}@if($pickerEmail) <span class="small">— {{ $pickerEmail }}</span>@endif</td></tr>
                    <tr><td class="label">Dibuat</td><td>: {{ $createdAt }}</td></tr>
                    @if($picklist->notes)
                        <tr><td class="label">Catatan</td><td>: {{ $picklist->notes }}</td></tr>
                    @endif
                </table>
            </td>
            <td class="col-right">
                <table>
                    <tr><td class="label">Total Item</td><td>: <span class="mono">{{ $totalItems }}</span></td></tr>
                    <tr><td class="label">Total Qty</td><td>: <span class="mono">{{ $totalQtyOrdered }}</span></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="small" style="margin: 6px 0 4px 0;">
        Rekomendasi bin di bawah adalah saran sistem berdasarkan stok terkini. Picker boleh ambil dari bin lain sesuai kondisi gudang.
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 24px;">No</th>
                <th style="width: 70px;">Rek. Bin</th>
                <th style="width: 90px;">SKU</th>
                <th>Nama Produk</th>
                <th style="width: 110px;">Varian</th>
                <th style="width: 95px;">Order No</th>
                <th style="width: 38px;" class="center">Qty</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $i => $item)
                @php
                    $sku = $item->sku ?? optional($item->product)->sku ?? '-';
                    $productName = optional(optional($item->product)->product)->name ?? '-';
                    $binCode = $item->recommended_bin_code ?? '-';
                    $orderNo = optional($item->order)->salesorder_no ?? '-';
                    $variantName = $resolveVariantName($item);
                @endphp
                <tr>
                    <td class="center mono">{{ $i + 1 }}</td>
                    <td class="bin-cell mono">{{ $binCode }}</td>
                    <td class="mono">{{ $sku }}</td>
                    <td>{{ $productName }}</td>
                    <td>{{ $variantName }}</td>
                    <td class="mono">{{ $orderNo }}</td>
                    <td class="num mono">{{ $item->qty_ordered }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="center" style="padding: 18px;">Tidak ada item.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
