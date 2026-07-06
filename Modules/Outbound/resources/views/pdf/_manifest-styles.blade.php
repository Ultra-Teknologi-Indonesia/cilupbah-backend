<style>
    @page { margin: 14mm 12mm 16mm 12mm; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 11px;
        color: #111827;
        margin: 0;
        padding: 0;
        line-height: 1.4;
    }
    .manifest-page + .manifest-page { page-break-before: always; }
    .title {
        font-size: 20px;
        font-weight: 700;
        text-align: right;
        margin: 0 0 14px;
    }
    .header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 16px;
    }
    .header-table td {
        vertical-align: top;
        padding: 0;
    }
    .header-left { width: 50%; }
    .header-right { width: 50%; }
    .qr-img { width: 100px; height: 100px; margin-bottom: 6px; }
    .info-row {
        margin-bottom: 2px;
        font-size: 11px;
    }
    .info-label {
        display: inline-block;
        width: 170px;
        font-weight: 600;
    }
    .info-sep {
        display: inline-block;
        width: 10px;
    }
    .items {
        width: 100%;
        border-collapse: collapse;
        margin-top: 6px;
        font-size: 10px;
    }
    .items th, .items td {
        border: 1px solid #000;
        padding: 5px 7px;
        vertical-align: top;
    }
    .items th {
        background: #f3f4f6;
        font-weight: 600;
        text-align: left;
        font-size: 10px;
    }
    .center { text-align: center; }
    .col-no { width: 30px; text-align: center; }
    .summary-row {
        margin-top: 10px;
        font-size: 11px;
    }
    .summary-row .info-label { font-weight: 700; }
    .signature {
        width: 100%;
        border-collapse: collapse;
        margin-top: 40px;
    }
    .signature td {
        width: 50%;
        text-align: center;
        vertical-align: top;
        padding: 0 20px;
    }
    .signature .sig-title {
        font-weight: 600;
        font-size: 11px;
        margin-bottom: 70px;
    }
    .signature .sig-line {
        border-top: 1px solid #000;
        display: inline-block;
        width: 180px;
        margin-top: 60px;
    }
    .signature .sig-name {
        font-size: 10px;
        margin-top: 4px;
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
