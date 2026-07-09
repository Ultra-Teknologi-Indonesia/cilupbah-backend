@php
    /** @var \Modules\Inventory\Models\Putaway $putaway */
    /** @var string|null $qrDataUri */
    /** @var string $sourceLabel */
    $items = collect($putaway->items ?? []);
    $locationName = optional($putaway->location)->location_name ?? '-';
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $assigneeName = optional($putaway->assignee)->name ?? '-';
    $creatorName = optional($putaway->creator)->name ?? '-';
@endphp
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
