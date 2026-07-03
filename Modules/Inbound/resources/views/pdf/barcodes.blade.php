<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Barcodes</title>
    <style>
        @page {
            margin: 0;
            size: 50mm 30mm;
        }
        body {
            margin: 0;
            padding: 2mm;
            font-family: sans-serif;
            font-size: 10px;
            width: 46mm;
            height: 26mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            box-sizing: border-box;
        }
        .page-break {
            page-break-after: always;
        }
        .container {
            width: 100%;
            height: 100%;
            display: table;
        }
        .content {
            display: table-cell;
            vertical-align: middle;
            text-align: center;
        }
        .qr-image {
            width: 16mm;
            height: 16mm;
            margin-bottom: 1mm;
        }
        .sku {
            font-weight: bold;
            font-size: 8px;
            margin: 0;
            line-height: 1.2;
        }
        .desc {
            font-size: 7px;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 44mm;
        }
    </style>
</head>
<body>

@foreach ($pages as $index => $page)
    <div class="container">
        <div class="content">
            <img class="qr-image" src="data:image/png;base64, {!! base64_encode(\SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')->size(100)->margin(0)->generate($page['sku'])) !!}" alt="QR">
            <div class="sku">{{ $page['sku'] }}</div>
            <div class="desc">{{ \Illuminate\Support\Str::limit($page['name'], 25) }}</div>
        </div>
    </div>
    @if (!$loop->last)
        <div class="page-break"></div>
    @endif
@endforeach

</body>
</html>
