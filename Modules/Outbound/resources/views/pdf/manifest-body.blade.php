@php
    /** @var \Modules\Outbound\Models\Shipment $shipment */
    /** @var string|null $qrDataUri */
    $orders = collect($shipment->orders ?? []);
    $totalPaket = $orders->count();
    $cancelled = $orders->filter(fn ($so) => str_contains(strtolower(optional($so->order)->status ?? ''), 'cancel'))->count();
    $courierName = $shipment->courier_name ?? $shipment->courier_code ?? '-';
    $shipmentNo = $shipment->shipment_no ?? '';
    $createdBy = $shipment->created_by ?? '';
    $shipmentDate = $shipment->shipment_date
        ? \Carbon\Carbon::parse($shipment->shipment_date)->translatedFormat('d M Y H:i')
        : '—';
    $totalWeightGram = $orders->sum(fn ($so) => (int) (optional($so->order)->order_weight_gram ?? 0));
    $totalWeightKg = number_format($totalWeightGram / 1000, 2, ',', '.');
@endphp
<div class="title">Laporan Bukti Pengiriman</div>

<table class="header-table">
    <tr>
        <td class="header-left">
            @if($qrDataUri)
                <img class="qr-img" src="{{ $qrDataUri }}" alt="QR">
            @endif
            <div class="info-row">
                <span class="info-label">No Pengiriman</span>
                <span class="info-sep">:</span>
                <span>{{ $shipmentNo }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Nama Penanggung Jawab</span>
                <span class="info-sep">:</span>
                <span>{{ $createdBy }}</span>
            </div>
        </td>
        <td class="header-right">
            <div class="info-row">
                <span class="info-label">Total Paket</span>
                <span class="info-sep">:</span>
                <span>{{ $totalPaket }} Paket</span>
            </div>
            <div class="info-row">
                <span class="info-label">Total Paket Cancel</span>
                <span class="info-sep">:</span>
                <span>{{ $cancelled ?: '' }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Kurir</span>
                <span class="info-sep">:</span>
                <span>{{ $courierName }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Tanggal Pengiriman</span>
                <span class="info-sep">:</span>
                <span>{{ $shipmentDate }}</span>
            </div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th class="col-no">No</th>
            <th>No Pesanan</th>
            <th class="center">Paket</th>
            <th class="center">Quantity</th>
            <th class="center">Berat</th>
            <th>No Resi</th>
            <th>Status Channel</th>
        </tr>
    </thead>
    <tbody>
        @forelse($orders as $i => $so)
            @php
                $order = $so->order;
                $wg = (int) ($order->order_weight_gram ?? 0);
                $weightKg = $wg ? number_format($wg / 1000, 2, ',', '.') : '0';
                $resi = $so->tracking_number ?? $order->tracking_number ?? '';
            @endphp
            <tr>
                <td class="col-no">{{ $i + 1 }}</td>
                <td>{{ $order->salesorder_no ?? '-' }}</td>
                <td class="center"></td>
                <td class="center">{{ (int) ($order->total_qty ?? 1) }}</td>
                <td class="center">{{ $weightKg }}</td>
                <td>{{ $resi }}</td>
                <td>{{ $order->status ?? '' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="center" style="padding: 18px;">Tidak ada pesanan.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="summary-row">
    <span class="info-label">Total Berat</span>
    <span class="info-sep">:</span>
    <span>{{ $totalWeightKg }} kg</span>
</div>

<table class="signature">
    <tr>
        <td>
            <div class="sig-title">Tanda Tangan Penanggung Jawab</div>
            <div class="sig-line"></div>
            <div class="sig-name">{{ $createdBy }}</div>
        </td>
        <td>
            <div class="sig-title">Tanda Tangan Pengirim</div>
            <div class="sig-line"></div>
            <div class="sig-name">{{ $courierName }}</div>
        </td>
    </tr>
</table>

<div class="footer">
    <table>
        <tr>
            <td>Tgl. Cetak: {{ now()->format('d M Y H:i') }}</td>
            <td class="right">Hal: <span class="page-num"></span></td>
        </tr>
    </table>
</div>
