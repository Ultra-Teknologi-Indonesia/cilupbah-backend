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
    .picklist-page + .picklist-page { page-break-before: always; }
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
    .header .picklist-no {
        font-size: 12px;
        font-weight: 700;
        margin-top: 4px;
    }
    .header .qr img { width: 78px; height: 78px; }
    .sub-header {
        width: 100%;
        text-align: right;
        font-size: 10px;
        margin: 4px 0 8px 0;
    }
    .sub-header .label { color: #555; }
    .sub-header .value { font-weight: 700; }
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
    .col-no { width: 24px; }
    .col-variant { width: 80px; }
    .col-foto { width: 58px; }
    .col-rak { width: 100px; }
    .col-qty { width: 44px; }
    .col-unit { width: 40px; }
    .col-ket { width: 120px; }
    .ket-order {
        font-size: 8.5px;
        line-height: 1.4;
        word-break: break-all;
    }
    .barang-sku {
        font-family: DejaVu Sans Mono, monospace;
        font-weight: 700;
        font-size: 10px;
    }
    .barang-name {
        margin-top: 2px;
        font-size: 9.5px;
    }
    .foto-wrap {
        text-align: center;
    }
    .foto-wrap img {
        max-width: 60px;
        max-height: 60px;
    }
    .foto-empty {
        display: inline-block;
        width: 56px;
        height: 56px;
        background: #f3f3f3;
        border: 1px dashed #bbb;
        color: #999;
        font-size: 8px;
        line-height: 56px;
        text-align: center;
    }
    .rak-cell {
        line-height: 1.45;
    }
    .rak-loc {
        font-size: 9.5px;
    }
    .rak-sep {
        border-top: 1px solid #999;
        margin: 3px 0;
    }
    .rak-bin {
        font-family: DejaVu Sans Mono, monospace;
        font-weight: 700;
        font-size: 10px;
    }
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
