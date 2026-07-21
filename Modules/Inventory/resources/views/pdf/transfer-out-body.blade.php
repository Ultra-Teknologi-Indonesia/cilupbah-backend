@php
    /** @var object $transfer */
    $printedAt = now()->format('d M Y H:i');
    $items = collect($transfer->items ?? []);
    $sourceName = optional($transfer->sourceLocation ?? $transfer->source_location ?? null)->location_name ?? '-';
    $destName = optional($transfer->destinationLocation ?? $transfer->destination_location ?? null)->location_name ?? '-';
    $tanggal = optional($transfer->created_at)->format('d M Y') ?? '-';
    $totalQty = (int) $items->sum('qty');
@endphp
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
            <th style="width:18px">No</th>
            <th style="width:52px">Rak</th>
            <th style="width:40px">Lokasi</th>
            <th style="width:110px">SKU</th>
            <th>Nama Barang</th>
            <th style="width:24px">Qty</th>
            <th style="width:28px">Unit</th>
            <th style="width:46px">Keterangan</th>
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
        <td class="num mono" style="width:24px">{{ $totalQty }}</td>
        <td style="width:46px"></td>
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
