@php
    /** @var \Modules\Outbound\Models\Picklist $picklist */
    /** @var string|null $qrDataUri */
    /** @var bool $includeImages */
    $rawItems = collect($picklist->items ?? []);
    $includeImages = $includeImages ?? true;
    $locationName = optional($picklist->location)->location_name ?? '-';
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->timezone('Asia/Jakarta')->format('d M Y H:i');

    // Group by SKU: merge qty dan kumpulkan nomor pesanan + qty per pesanan
    $grouped = $rawItems->groupBy('sku')->map(function ($group) {
        $first = $group->first();
        $first->qty_ordered = $group->sum('qty_ordered');
        $orderQty = [];
        foreach ($group as $it) {
            $no = optional($it->order)->salesorder_no;
            if (! $no) continue;
            $orderQty[$no] = ($orderQty[$no] ?? 0) + (int) $it->qty_ordered;
        }
        $first->order_lines = $orderQty;
        return $first;
    })->values();
    $items = $grouped->sortBy(fn ($it) => $it->recommended_bin_code ?? 'zzz')->values();

    $resolveVariantName = function ($item) {
        $variant = $item->product ?? null;
        $parentName = optional(optional($variant)->product)->name;
        $optionValues = collect(
            $variant && $variant->relationLoaded('options') ? $variant->options : []
        )
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
            <th class="col-ket">No. Pesanan</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $i => $item)
            @php
                $sku = $item->sku ?? optional($item->product)->sku ?? '-';
                $productName = optional(optional($item->product)->product)->name ?? '-';
                $binCode = $item->recommended_bin_code;
                $variantName = $resolveVariantName($item);
                $imageUrl = $includeImages
                    ? ($item->pdf_image_path ?? $resolveImageUrl($item))
                    : null;
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
                    @foreach($item->order_lines ?? [] as $orderNo => $qty)
                        {{ $orderNo }} ({{ $qty }})@if(!$loop->last)<br>@endif
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
