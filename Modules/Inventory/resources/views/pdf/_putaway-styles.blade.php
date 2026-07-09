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
    .header .title {
        font-size: 20px;
        font-weight: 700;
        margin-top: 2px;
    }
    .header .right { text-align: right; }
    .header .putaway-no {
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
    }
    .header .qr img { width: 78px; height: 78px; }
    .meta-table {
        width: 100%;
        border-collapse: collapse;
        margin: 8px 0 10px 0;
    }
    .meta-table td {
        padding: 3px 0;
        font-size: 10px;
        vertical-align: top;
    }
    .meta-table .label {
        color: #555;
        width: 140px;
    }
    .meta-table .value {
        font-weight: 700;
    }
    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-top: 2px;
    }
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
    .mono, .num {
        font-family: DejaVu Sans Mono, monospace;
        font-variant-numeric: tabular-nums;
    }
    .center { text-align: center; }
    .num { text-align: right; }
    .col-no { width: 28px; }
    .col-qty { width: 50px; }
    .col-date { width: 80px; }
    .col-source { width: 80px; }
    .col-rak { width: 110px; }
    .rec-line { margin-bottom: 1px; }
    .rec-qty { font-weight: 400; font-size: 8.5px; color: #333; }
    .barang-sku {
        font-family: DejaVu Sans Mono, monospace;
        font-weight: 700;
        font-size: 10px;
    }
    .barang-name {
        margin-top: 2px;
        font-size: 9.5px;
    }
    .rak-bin {
        font-family: DejaVu Sans Mono, monospace;
        font-weight: 700;
        font-size: 10px;
    }
    .doc-break { page-break-after: always; }
    .footer {
        position: fixed;
        bottom: -8mm;
        left: 0;
        right: 0;
        font-size: 9px;
        color: #555;
    }
    .footer table { width: 100%; border-collapse: collapse; }
    .footer td { padding: 0 2px; }
    .footer .right { text-align: right; }
    .page-num:after { content: counter(page); }
</style>
