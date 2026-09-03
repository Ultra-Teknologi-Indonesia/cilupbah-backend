@php
    /** @var \Modules\Inventory\Models\Putaway $putaway */
    /** @var string $sourceLabel */
    $items = collect($putaway->items ?? []);
    $locationName = optional($putaway->location)->location_name ?? '-';
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $inboundNumber = optional($putaway->inbound)->transaction_number
        ?? ($putaway->sources ?? collect())->pluck('transaction_number')->filter()->implode(', ')
        ?: '-';
    $putawayDate = $putaway->completed_at ?? $putaway->created_at;
@endphp
<table class="header">
    <tr>
        <td>
            <div class="company">{{ $companyName }}</div>
            <div class="title">Laporan Putaway</div>
        </td>
        <td class="right">
            <div class="doc-no">{{ $putaway->putaway_no }}</div>
        </td>
    </tr>
</table>

<table class="info-grid">
    <tr>
        <td class="label">No. Putaway</td>
        <td class="value">{{ $putaway->putaway_no }}</td>
        <td class="label">No. Penerimaan</td>
        <td class="value">{{ $inboundNumber }}</td>
    </tr>
    <tr>
        <td class="label">Tgl. Putaway</td>
        <td class="value">{{ $putawayDate ? \Carbon\Carbon::parse($putawayDate)->format('d M Y') : '-' }}</td>
        <td class="label">Status</td>
        <td class="value">{{ $putaway->status ?? '-' }}</td>
    </tr>
    <tr>
        <td class="label">Sumber</td>
        <td class="value">{{ $sourceLabel ?: '-' }}</td>
        <td class="label">Lokasi</td>
        <td class="value">{{ $locationName }}</td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th class="col-no">No</th>
            <th class="col-sku">SKU</th>
            <th>Nama Produk</th>
            <th class="col-qty-assigned">Qty<br>Ditetapkan</th>
            <th class="col-qty-placed">Qty<br>Ditempatkan</th>
            <th class="col-qty-remaining">Qty<br>Sisa</th>
            <th class="col-rak">Kode Rak</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $i => $item)
            @php
                $sku = optional($item->product)->sku ?? '-';
                $productName = optional(optional($item->product)->product)->name ?? optional($item->product)->name ?? '-';
                $placedBins = collect($item->placements ?? [])->map(fn($p) => [
                    'code' => optional($p->bin)->bin_final_code ?? optional($p->bin)->bin_code ?? '-',
                    'qty' => (int) $p->qty,
                ])->all();
                if (count($placedBins) === 0 && $item->destinationBin) {
                    $placedBins[] = [
                        'code' => $item->destinationBin->bin_final_code ?? $item->destinationBin->bin_code ?? '-',
                        'qty' => (int) $item->putaway_qty,
                    ];
                }
                $assignedQty = (int) $item->qty;
                $placedQty = (int) $item->putaway_qty;
                $remainingQty = max(0, $assignedQty - $placedQty);
            @endphp
            <tr>
                <td class="center mono">{{ $i + 1 }}</td>
                <td class="barang-sku">{{ $sku }}</td>
                <td>{{ $productName }}</td>
                <td class="num mono">{{ $assignedQty }}</td>
                <td class="num mono">{{ $placedQty }}</td>
                <td class="num mono">{{ $remainingQty }}</td>
                <td class="rak-bin" style="font-size: 9px;">
                    @if(count($placedBins) > 0)
                        @foreach($placedBins as $placed)
                            <div class="rak-line">{{ $placed['code'] }} ({{ $placed['qty'] }})</div>
                        @endforeach
                    @else
                        -
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="center" style="padding: 18px;">Tidak ada item.</td>
            </tr>
        @endforelse
    </tbody>
</table>
