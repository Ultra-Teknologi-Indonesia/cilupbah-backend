<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Jalan Transfer Internal {{ $transfer->transfer_number }}</title>
    @include('inventory::pdf._transfer-out-styles')
</head>
<body>
    <div class="transfer-page">
        @php
            $items = collect($transfer->items ?? []);
            $locName = optional($transfer->location)->location_name ?? '-';
            $tanggal = optional($transfer->transfer_date)->format('d M Y')
                ?? optional($transfer->created_at)->format('d M Y') ?? '-';
            $printedAt = now()->format('d M Y H:i');
            $totalQty = (int) $items->sum('qty');
        @endphp
        <div class="title">Surat Jalan Transfer Internal</div>

        <table class="info-grid">
            <tr>
                <td class="label">LOKASI</td>
                <td class="sep">:</td>
                <td class="value">{{ $locName }}</td>
                <td class="r-label">No. Transfer</td>
                <td class="r-value mono">{{ $transfer->transfer_number }}</td>
            </tr>
            <tr>
                <td class="label">DIBUAT OLEH</td>
                <td class="sep">:</td>
                <td class="value">{{ $transfer->created_by ?? '-' }}</td>
                <td class="r-label">Tanggal</td>
                <td class="r-value">{{ $tanggal }}</td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:22px">No</th>
                    <th style="width:70px">Rak Asal</th>
                    <th style="width:92px">SKU</th>
                    <th>Nama Barang</th>
                    <th style="width:34px">Qty</th>
                    <th style="width:32px">Unit</th>
                    <th style="width:72px">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $i => $item)
                    @php
                        $variant = $item->product ?? null;
                        $sku = optional($variant)->sku ?? '-';
                        $name = optional(optional($variant)->product)->name ?? '-';
                        $rak = optional($item->sourceBin ?? null)->bin_final_code ?? '-';
                        $ket = $item->notes ?? '';
                    @endphp
                    <tr>
                        <td class="center mono">{{ $i + 1 }}</td>
                        <td class="mono">{{ $rak }}</td>
                        <td class="mono">{{ $sku }}</td>
                        <td>{{ $name }}</td>
                        <td class="num mono">{{ (int) $item->qty }}</td>
                        <td class="center">Buah</td>
                        <td>{{ $ket }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="center" style="padding: 18px;">Tidak ada item.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <table style="width:100%; border-collapse:collapse;">
            <tr class="total-row">
                <td class="num">Total Qty</td>
                <td class="num mono" style="width:34px">{{ $totalQty }}</td>
                <td style="width:72px"></td>
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
    </div>
</body>
</html>
