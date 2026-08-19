@php
    /** @var \Modules\Inbound\Models\Inbound $inbound */
    $companyName = config('app.company_name', 'PT ULTRA TEKNOLOGI INDONESIA');
    $printedAt = now()->format('d M Y H:i');
    // Hanya cetak item yang sudah diterima (received_qty > 0). Item yang belum diterima (0) otomatis disembunyikan.
    $items = collect($inbound->items ?? [])->filter(function ($item) {
        return (int) ($item->received_qty ?? 0) > 0;
    })->values();
    $typeLabel = [
        'PURCHASE_ORDER' => 'Pesanan Pembelian',
        'TRANSIT_IN'     => 'Transfer Masuk',
        'SALES_RETURN'   => 'Retur',
        'CONSIGNMENT'    => 'Konsinyasi',
    ][$inbound->type] ?? $inbound->type;
    // Tgl. Penerimaan = saat sesi penerimaan ditutup; fallback ke tanggal rencana.
    $receivedDate = $inbound->once_received_at
        ? $inbound->once_received_at->format('d M Y')
        : ($inbound->expected_date ? $inbound->expected_date->format('d M Y') : '-');
    // reference_number diisi dari po_number saat inbound dibuat dari PO; untuk
    // sumber non-PO (transfer/retur) tetap tampil sebagai "No. Referensi".
    $refLabel = $inbound->type === 'PURCHASE_ORDER' ? 'No. PO' : 'No. Referensi';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Penerimaan {{ $inbound->transaction_number }}</title>
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
        .header .title { font-size: 20px; font-weight: 700; margin-top: 2px; }
        .header .right { text-align: right; }
        .header .doc-no { font-size: 12px; font-weight: 700; margin-top: 4px; }
        .info-grid { width: 100%; border-collapse: collapse; margin: 10px 0 14px; }
        .info-grid td { padding: 3px 0; font-size: 10px; vertical-align: top; }
        .info-grid .label { color: #555; width: 110px; }
        .info-grid .value { font-weight: 700; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 2px; }
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
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .center { text-align: center; }
        .num { text-align: right; }
        .footer {
            position: fixed;
            bottom: -8mm;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #555;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer .right { text-align: right; }
        .page-num:after { content: counter(page); }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="company">{{ $companyName }}</div>
                <div class="title">Penerimaan Barang</div>
            </td>
            <td class="right">
                <div class="doc-no">{{ $inbound->transaction_number }}</div>
            </td>
        </tr>
    </table>

    <table class="info-grid">
        <tr>
            <td class="label">{{ $refLabel }}</td>
            <td class="value">{{ $inbound->reference_number ?? '-' }}</td>
            <td class="label">No. Penerimaan</td>
            <td class="value">{{ $inbound->transaction_number }}</td>
        </tr>
        <tr>
            <td class="label">Tgl. Penerimaan</td>
            <td class="value">{{ $receivedDate }}</td>
            <td class="label">Status</td>
            <td class="value">{{ $inbound->status }}</td>
        </tr>
        <tr>
            <td class="label">Sumber</td>
            <td class="value">{{ $typeLabel }}</td>
            <td class="label">Lokasi</td>
            <td class="value">{{ optional($inbound->location ?? null)->location_name ?? '-' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:24px">No</th>
                <th>SKU</th>
                <th>Nama Produk</th>
                <th style="width:70px">Qty Diharapkan</th>
                <th style="width:64px">Qty Diterima</th>
                <th style="width:64px">Qty Sisa</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $i => $item)
                @php
                    $variant = $item->variant ?? null;
                    $sku = optional($variant)->sku ?? '-';
                    $name = optional(optional($variant)->product)->name ?? '-';
                    $expected = (int) $item->expected_qty;
                    $received = (int) ($item->received_qty ?? 0);
                    // Qty Sisa = Qty Diharapkan − Qty Diterima (cek barang kurang/belum
                    // terkirim). Negatif berarti diterima melebihi yang diharapkan.
                    $remaining = $expected - $received;
                @endphp
                <tr>
                    <td class="center mono">{{ $i + 1 }}</td>
                    <td class="mono">{{ $sku }}</td>
                    <td>{{ $name }}</td>
                    <td class="num mono">{{ $expected }}</td>
                    <td class="num mono">{{ $received }}</td>
                    <td class="num mono">{{ $remaining }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="center" style="padding: 18px;">Tidak ada item.</td>
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
