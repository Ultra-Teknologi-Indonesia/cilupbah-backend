@php
    /** @var \Modules\Inventory\Models\StockAdjustment $adjustment */
    $items = collect($adjustment->items ?? []);
    $trxDate = $adjustment->transaction_date
        ? \Carbon\Carbon::parse($adjustment->transaction_date)->locale('id')->translatedFormat('d M Y')
        : '-';
    $notesHeader = trim((string) ($adjustment->notes ?? ''));
    $printedAt = now()->locale('id')->translatedFormat('d M Y H:i');
@endphp

<table class="header">
    <tr>
        <td class="brand">
            <div class="brand-box">cilupbah</div>
        </td>
        <td class="title">LAPORAN PENYESUAIAN</td>
    </tr>
</table>

<table class="meta-table">
    <tr>
        <td class="label">No. Penyesuaian</td>
        <td class="value">: <strong>{{ $adjustment->adjustment_no }}</strong></td>
        <td class="label">Keterangan</td>
        <td class="value">: {{ $notesHeader !== '' ? $notesHeader : '-' }}</td>
    </tr>
    <tr>
        <td class="label">Tanggal Penyesuaian</td>
        <td class="value">: {{ $trxDate }}</td>
        <td></td>
        <td></td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th class="col-nama">Nama Barang</th>
            <th class="col-rak">Kode Rak</th>
            <th class="col-qty">QTY(+/-)</th>
            <th class="col-ket">Keterangan</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $item)
            @php
                $productName = optional(optional($item->product)->product)->name
                    ?? optional($item->product)->name
                    ?? optional($item->product)->sku
                    ?? '-';
                $binCode = optional($item->bin)->bin_final_code
                    ?? optional($item->bin)->bin_code
                    ?? '';
                $qty = $adjustment->is_beginning_balance
                    ? (int) ($item->actual_qty ?? 0)
                    : (int) ($item->difference_qty ?? 0);
                $qtyLabel = $qty > 0 ? '+' . $qty : (string) $qty;
                $rowNote = trim((string) ($item->notes ?? ''));
            @endphp
            <tr>
                <td>{{ $productName }}</td>
                <td class="center mono">{{ $binCode }}</td>
                <td class="center mono">{{ $qtyLabel }}</td>
                <td>{{ $rowNote }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="center empty-row">Tidak ada item.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="signature">
    <tr>
        <td class="sig-left">
            <div class="sig-label">Penanggung Jawab</div>
            <div class="sig-space">&nbsp;</div>
            <div class="sig-line">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</div>
        </td>
        <td class="sig-right">Dicetak tanggal :&nbsp;&nbsp;{{ $printedAt }}</td>
    </tr>
</table>
