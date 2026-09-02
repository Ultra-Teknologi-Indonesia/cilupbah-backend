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
    .header .doc-no {
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
    }
    .info-grid {
        width: 100%;
        border-collapse: collapse;
        margin: 10px 0 14px;
    }
    .info-grid td {
        padding: 3px 0;
        font-size: 10px;
        vertical-align: top;
    }
    .info-grid .label {
        color: #555;
        width: 110px;
    }
    .info-grid .value {
        font-weight: 700;
        padding-right: 18px;
    }
    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-top: 2px;
    }
    table.items thead { display: table-header-group; }
    table.items tr { page-break-inside: avoid; }
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
    .col-no { width: 24px; }
    .col-sku { width: 140px; }
    .col-qty { width: 40px; }
    .col-date { width: 72px; }
    .col-source { width: 72px; }
    .col-rak { width: 90px; }
    .rec-line { margin-bottom: 1px; }
    .barang-sku {
        font-family: DejaVu Sans Mono, monospace;
        font-weight: 700;
        font-size: 10px;
        word-break: break-all;
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
