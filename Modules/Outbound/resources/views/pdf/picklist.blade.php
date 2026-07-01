@php
    /** @var \Modules\Outbound\Models\Picklist $picklist */
    /** @var string|null $qrDataUri */
    $rawItems = collect($picklist->items ?? []);
    $locationName = optional($picklist->location)->location_name ?? '-';
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->format('d M Y H:i');

    // Group by SKU: merge qty dan kumpulkan nomor pesanan
    $grouped = $rawItems->groupBy('sku')->map(function ($group) {
        $first = $group->first();
        $first->qty_ordered = $group->sum('qty_ordered');
        $first->order_numbers = $group
            ->map(fn ($it) => optional($it->order)->salesorder_no)
            ->filter()
            ->unique()
            ->values()
            ->all();
        return $first;
    })->values();
    $items = $grouped->sortBy(fn ($it) => $it->recommended_bin_code ?? 'zzz')->values();

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
        return '-';
    };

    $resolveImageUrl = function ($item) {
        $variant = $item->product ?? null;
        $variantMedia = collect(optional($variant)->media ?? [])
            ->sortByDesc(fn ($m) => (bool) ($m->is_primary ?? false))
            ->sortBy(fn ($m) => (int) ($m->sort_order ?? 0))
            ->first();
        if ($variantMedia && !empty($variantMedia->url)) {
            return $variantMedia->url;
        }
        $parent = optional($variant)->product;
        $parentMedia = collect(optional($parent)->media ?? [])
            ->sortByDesc(fn ($m) => (bool) ($m->is_primary ?? false))
            ->sortBy(fn ($m) => (int) ($m->sort_order ?? 0))
            ->first();
        return $parentMedia && !empty($parentMedia->url) ? $parentMedia->url : null;
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pengambilan Barang {{ $picklist->picklist_no }}</title>
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
        .header .picklist-no {
            font-size: 12px;
            font-weight: 700;
            margin-top: 4px;
        }
        .header .qr img { width: 78px; height: 78px; }
        .sub-header {
            width: 100%;
            text-align: right;
            font-size: 10px;
            margin: 4px 0 8px 0;
        }
        .sub-header .label { color: #555; }
        .sub-header .value { font-weight: 700; }
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
        .col-no { width: 24px; }
        .col-variant { width: 80px; }
        .col-foto { width: 58px; }
        .col-rak { width: 100px; }
        .col-qty { width: 44px; }
        .col-unit { width: 40px; }
        .col-ket { width: 120px; }
        .ket-order {
            font-size: 8.5px;
            line-height: 1.4;
            word-break: break-all;
        }
        .barang-sku {
            font-family: DejaVu Sans Mono, monospace;
            font-weight: 700;
            font-size: 10px;
        }
        .barang-name {
            margin-top: 2px;
            font-size: 9.5px;
        }
        .foto-wrap {
            text-align: center;
        }
        .foto-wrap img {
            max-width: 60px;
            max-height: 60px;
        }
        .foto-empty {
            display: inline-block;
            width: 56px;
            height: 56px;
            background: #f3f3f3;
            border: 1px dashed #bbb;
            color: #999;
            font-size: 8px;
            line-height: 56px;
            text-align: center;
        }
        .rak-cell {
            line-height: 1.45;
        }
        .rak-loc {
            font-size: 9.5px;
        }
        .rak-sep {
            border-top: 1px solid #999;
            margin: 3px 0;
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
                <div class="title">Pengambilan Barang</div>
            </td>
            <td class="right qr">
                @if($qrDataUri)
                    <img src="{{ $qrDataUri }}" alt="QR">
                @endif
                <div class="picklist-no">{{ $picklist->picklist_no }}</div>
            </td>
        </tr>
    </table>

    <div class="sub-header">
        <span class="label">LOKASI GUDANG :</span>
        <span class="value">{{ $locationName }}</span>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="col-no">No</th>
                <th>Barang</th>
                <th class="col-variant">Variant</th>
                <th class="col-foto">Foto</th>
                <th class="col-rak">Rek. Rak</th>
                <th class="col-qty">Qty</th>
                <th class="col-unit">Unit</th>
                <th class="col-ket">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $i => $item)
                @php
                    $sku = $item->sku ?? optional($item->product)->sku ?? '-';
                    $productName = optional(optional($item->product)->product)->name ?? '-';
                    $binCode = $item->recommended_bin_code;
                    $variantName = $resolveVariantName($item);
                    $imageUrl = $resolveImageUrl($item);
                @endphp
                <tr>
                    <td class="center mono">{{ $i + 1 }}</td>
                    <td>
                        <div class="barang-sku">{{ $sku }}</div>
                        <div class="barang-name">{{ $productName }}</div>
                    </td>
                    <td>{{ $variantName }}</td>
                    <td class="foto-wrap">
                        @if($imageUrl)
                            <img src="{{ $imageUrl }}" alt="">
                        @else
                            <span class="foto-empty">no img</span>
                        @endif
                    </td>
                    <td class="rak-cell">
                        <div class="rak-loc">{{ $locationName }}</div>
                        <div class="rak-sep"></div>
                        <div class="rak-bin">{{ $binCode ?? '-' }}</div>
                    </td>
                    <td class="num mono">{{ (int) $item->qty_ordered }}</td>
                    <td class="center">buah</td>
                    <td class="ket-order">
                        @foreach($item->order_numbers ?? [] as $orderNo)
                            {{ $orderNo }}@if(!$loop->last)<br>@endif
                        @endforeach
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
                <td>Tgl. Cetak: {{ $printedAt }}</td>
                <td class="right">Hal: <span class="page-num"></span></td>
            </tr>
        </table>
    </div>
</body>
</html>
