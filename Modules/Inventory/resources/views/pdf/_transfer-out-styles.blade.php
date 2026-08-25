<style>
    @page { margin: 10mm 10mm 13mm 10mm; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 8.5px;
        color: #1a1a1a;
        margin: 0;
        padding: 0;
        line-height: 1.2;
    }
    .transfer-page + .transfer-page { page-break-before: always; }
    .title {
        text-align: center;
        font-size: 18px;
        font-weight: 700;
        margin: 0 0 10px;
    }
    .info-grid { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 0 0 7px; }
    .info-grid td { padding: 1px 0; font-size: 8.5px; vertical-align: top; }
    .info-grid .label { color: #333; width: 8%; font-weight: 700; }
    .info-grid .sep { width: 3%; }
    .info-grid .value { }
    .info-grid .r-label { text-align: right; width: 18%; font-weight: 700; }
    .info-grid .r-value { text-align: right; width: 21%; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 1px; table-layout: fixed; }
    table.items .col-no { width: 5%; }
    table.items .col-rak { width: 11%; }
    table.items .col-location { width: 11%; }
    table.items .col-sku { width: 18%; }
    table.items .col-name { width: 29%; }
    table.items .col-qty { width: 6%; }
    table.items .col-unit { width: 6%; }
    table.items .col-notes { width: 14%; }
    table.items th, table.items td {
        border: 1px solid #555;
        padding: 3px 4px;
        vertical-align: top;
        font-size: 8px;
        word-wrap: break-word;
        overflow-wrap: break-word;
        word-break: break-word;
        line-height: 1.2;
    }
    table.items th {
        background: #efefef;
        text-align: center;
        font-weight: 700;
        font-size: 7.5px;
        text-transform: uppercase;
        letter-spacing: 0.1px;
    }
    table.items tr {
        page-break-inside: avoid;
    }
    .mono { font-family: DejaVu Sans Mono, monospace; }
    .center { text-align: center; }
    .num { text-align: right; }
    table.totals { width: 100%; border-collapse: collapse; }
    .total-row td {
        border: none;
        padding-top: 4px;
        font-weight: 700;
        font-size: 8.5px;
    }
    .total-row td:first-child { width: 94%; }
    .total-row td:nth-child(2) { width: 6%; }
    .catatan { margin-top: 8px; font-size: 8.5px; }
    .footer {
        position: fixed;
        bottom: -7mm;
        left: 0;
        right: 0;
        font-size: 8px;
        color: #555;
    }
    .footer table { width: 100%; border-collapse: collapse; }
    .footer .right { text-align: right; }
    .page-num:after { content: counter(page); }
</style>
