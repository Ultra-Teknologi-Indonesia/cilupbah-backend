@php
    /** @var \Modules\Purchase\Models\PurchaseOrder $po */
    /** @var array $company */
    $printedAt = now()->format('d M Y H:i');
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
        @page { margin: 14mm 14mm 16mm 14mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }
        .header { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .header td { vertical-align: top; }
        .header .logo { width: 90px; }
        .header .logo img { max-width: 80px; max-height: 80px; }
        .header .title {
            text-align: right;
            font-size: 22px;
            font-weight: 700;
        }
        .company-block { margin-bottom: 18px; }
        .company-block table { width: 100%; border-collapse: collapse; }
        .company-block td { vertical-align: top; padding: 1px 0; }
        .company-block .name { font-weight: 700; text-transform: uppercase; font-size: 11px; }
        .company-block .kepada-label {
            display: inline-block;
            font-weight: 700;
            margin-right: 6px;
            margin-left: 12px;
        }
        .info-right { text-align: right; font-size: 10px; }
        .info-right table { border-collapse: collapse; margin-left: auto; }
        .info-right td { padding: 1px 0; }
        .info-right .label { font-weight: 700; padding-right: 18px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th, table.items td {
            border: 1px solid #555;
            padding: 6px 8px;
            vertical-align: top;
            font-size: 10px;
        }
        table.items th {
            background: #f2f2f2;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: 0.3px;
        }
        table.items th.center, table.items td.center { text-align: center; }
        table.items th.num, table.items td.num { text-align: right; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .notes { margin-top: 16px; }
        .notes .label { font-weight: 700; margin-bottom: 4px; }
        .notes .body { min-height: 24px; }
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
            <td class="logo">
                @if (!empty($company['logo_url']))
                    <img src="{{ $company['logo_url'] }}" alt="Logo">
                @endif
            </td>
            <td class="title">Barang Masuk Pembelian</td>
        </tr>
    </table>

    <table class="company-block">
        <tr>
            <td>
                <span class="name">{{ $company['name'] ?? '—' }}</span>
                <span class="kepada-label">Kepada:</span>
                <span>{{ $supplierName }}</span>
                <div>{{ $companyAddress }}</div>
            </td>
            <td class="info-right">
                <table>
                    <tr>
                        <td class="label">No PO</td>
                        <td>{{ $po->po_number }}</td>
                    </tr>
                    <tr>
                        <td class="label">Tanggal</td>
                        <td>{{ optional($po->order_date)->format('d M Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Lokasi</td>
                        <td>{{ optional($po->location ?? null)->location_name ?? '—' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="center" style="width:32px">NO</th>
                <th style="width:140px">SKU</th>
                <th>NAMA BARANG</th>
                <th class="num" style="width:90px">QTY PESAN</th>
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
                    <td class="center mono">{{ $i + 1 }}</td>
                    <td class="mono">{{ $sku }}</td>
                    <td>{{ $name }}</td>
                    <td class="num mono">{{ number_format((int) $item->qty, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="center" style="padding: 18px;">Tidak ada item.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="notes">
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
