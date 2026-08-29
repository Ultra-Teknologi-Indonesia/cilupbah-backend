@php
    /** @var \Modules\Purchase\Models\PurchaseOrder $po */
    /** @var array $company */
    $printedAt = now()->timezone('Asia/Jakarta')->format('d M Y H:i');
    $items = collect($po->items ?? []);
    $supplierName = optional($po->contact ?? null)->name ?? '—';
    $addressParts = array_filter([
        $company['address'] ?? null,
        $company['city'] ?? null,
        $company['postal_code'] ?? null,
    ]);
    $companyAddress = implode(', ', $addressParts);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Barang Masuk Pembelian {{ $po->po_number }}</title>
    <style>
        @page { margin: 18mm 16mm 20mm 16mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            line-height: 1.45;
        }

        .top { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .top td { vertical-align: top; }
        .top .logo-cell { width: 100px; }
        .top .logo-cell img { max-width: 90px; max-height: 70px; }
        .top .title-cell { text-align: right; }
        .top .title-cell .title {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
        }

        .info { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .info td { vertical-align: top; padding: 0; }
        .info .left {
            width: 60%;
            padding-right: 16px;
        }
        .info .right {
            width: 40%;
            text-align: right;
        }
        .info .company-name {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.3px;
            margin-bottom: 3px;
        }
        .info .company-address {
            color: #333;
            margin-bottom: 10px;
        }
        .info .kepada {
            margin-top: 4px;
        }
        .info .kepada .label {
            font-weight: 700;
            margin-right: 6px;
        }

        .meta-table {
            border-collapse: collapse;
            margin-left: auto;
            font-size: 10px;
        }
        .meta-table td { padding: 2px 0; vertical-align: top; }
        .meta-table .label {
            font-weight: 700;
            padding-right: 24px;
            text-align: left;
        }
        .meta-table .value {
            text-align: left;
            min-width: 100px;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.items th, table.items td {
            border: 1px solid #444;
            padding: 7px 8px;
            vertical-align: middle;
            font-size: 10px;
        }
        table.items thead th {
            background: #f2f2f2;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9.5px;
            letter-spacing: 0.4px;
        }
        table.items .th-no, table.items .td-no {
            text-align: center;
            width: 36px;
        }
        table.items .th-sku { text-align: left; width: 150px; }
        table.items .td-sku { font-family: DejaVu Sans Mono, monospace; }
        table.items .th-nama { text-align: left; }
        table.items .th-qty, table.items .td-qty {
            text-align: right;
            width: 90px;
            font-family: DejaVu Sans Mono, monospace;
        }

        .catatan { margin-top: 20px; }
        .catatan .label { font-weight: 700; margin-bottom: 6px; }
        .catatan .body {
            min-height: 40px;
            color: #333;
            white-space: pre-line;
        }

        .footer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #666;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer .right { text-align: right; }
        .page-num:after { content: counter(page); }
    </style>
</head>
<body>
    <table class="top">
        <tr>
            <td class="logo-cell">
                @if (!empty($company['logo_url']))
                    <img src="{{ $company['logo_url'] }}" alt="Logo">
                @endif
            </td>
            <td class="title-cell">
                <div class="title">Barang Masuk Pembelian</div>
            </td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td class="left">
                <div class="company-name">{{ $company['name'] ?? '—' }}</div>
                @if (!empty($companyAddress))
                    <div class="company-address">{{ $companyAddress }}</div>
                @endif
                <div class="kepada">
                    <span class="label">Kepada:</span>
                    <span>{{ $supplierName }}</span>
                </div>
            </td>
            <td class="right">
                <table class="meta-table">
                    <tr>
                        <td class="label">No PO</td>
                        <td class="value">{{ $po->po_number }}</td>
                    </tr>
                    <tr>
                        <td class="label">Tanggal</td>
                        <td class="value">{{ optional($po->order_date)->format('d M Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Lokasi</td>
                        <td class="value">{{ optional($po->location ?? null)->location_name ?? '—' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="th-no">No</th>
                <th class="th-sku">SKU</th>
                <th class="th-nama">Nama Barang</th>
                <th class="th-qty">Qty Pesan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $i => $item)
                @php
                    $variant = $item->variant ?? null;
                    $sku = optional($variant)->sku ?? '—';
                    $name = optional(optional($variant)->product)->name ?? optional($variant)->name ?? '—';
                @endphp
                <tr>
                    <td class="td-no">{{ $i + 1 }}</td>
                    <td class="td-sku">{{ $sku }}</td>
                    <td>{{ $name }}</td>
                    <td class="td-qty">{{ number_format((int) $item->qty, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align:center; padding:22px;">Tidak ada item.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="catatan">
        <div class="label">Catatan :</div>
        <div class="body">{{ $po->notes ?? '' }}</div>
    </div>

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
