@php
    /** @var \Modules\Inventory\Models\Putaway $putaway */
    /** @var string $sourceLabel */
    $items = collect($putaway->items ?? []);
    $locationName = optional($putaway->location)->location_name ?? '-';
    $isStrictBin = optional($putaway->location)->enforcesStrictBinSku() ?? false;
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $inboundNumber = optional($putaway->inbound)->transaction_number
        ?? ($putaway->sources ?? collect())->pluck('transaction_number')->filter()->implode(', ')
        ?: '-';
    $receivedDate = optional($putaway->inbound)->once_received_at
        ?? optional($putaway->inbound)->expected_date
        ?? $putaway->created_at;
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
        <td class="label">No Putaway</td>
        <td class="value">{{ $putaway->putaway_no }}</td>
        <td class="label">No. Penerimaan</td>
        <td class="value">{{ $inboundNumber }}</td>
    </tr>
    <tr>
        <td class="label">Tgl. Penerimaan</td>
        <td class="value">{{ $receivedDate ? \Carbon\Carbon::parse($receivedDate)->format('d M Y') : '-' }}</td>
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
            <th class="col-qty">Qty</th>
            <th class="col-date">Tgl. Penerimaan</th>
            <th class="col-source">Sumber</th>
            <th class="col-rak">{{ $isStrictBin ? 'Rak Tetap' : 'Rekomendasi Rak' }}</th>
            <th class="col-rak">Kode Rak</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $i => $item)
            @php
                $sku = optional($item->product)->sku ?? '-';
                $productName = optional(optional($item->product)->product)->name ?? optional($item->product)->name ?? '-';
                $sourceRef = $sourceLabel ?: '-';
                $placedBins = collect($item->placements ?? [])->map(fn($p) => [
                    'code' => optional($p->bin)->bin_final_code ?? '-',
                    'qty' => (int) $p->qty,
                ])->all();
                $recommendedBins = $item->recommended_bins ?? [];
                $receivedAt = $receivedDate ? \Carbon\Carbon::parse($receivedDate)->format('d M Y') : '-';
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
                            <div class="rec-line">{{ $rec['code'] }}</div>
                        @endforeach
                    @else
                        -
                    @endif
                </td>
                <td class="rak-bin" style="font-size: 9px;">
                    @if(count($placedBins) > 0)
                        @foreach($placedBins as $placed)
                            <div class="rec-line">{{ $placed['code'] }}</div>
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
