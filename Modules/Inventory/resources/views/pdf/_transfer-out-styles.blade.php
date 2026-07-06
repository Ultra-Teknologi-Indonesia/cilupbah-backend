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
    .transfer-page + .transfer-page { page-break-before: always; }
    .title {
        text-align: center;
        font-size: 20px;
        font-weight: 700;
        margin: 4px 0 18px;
    }
    .info-grid { width: 100%; border-collapse: collapse; margin: 0 0 12px; }
    .info-grid td { padding: 2px 0; font-size: 10px; vertical-align: top; }
    .info-grid .label { color: #333; width: 70px; font-weight: 700; }
    .info-grid .sep { width: 10px; }
    .info-grid .value { }
    .info-grid .r-label { text-align: right; width: 90px; font-weight: 700; }
    .info-grid .r-value { text-align: right; width: 130px; }
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
    .total-row td {
        border: none;
        padding-top: 6px;
        font-weight: 700;
        font-size: 10px;
    }
    .catatan { margin-top: 14px; font-size: 10px; }
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
