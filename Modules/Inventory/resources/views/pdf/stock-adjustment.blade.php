<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Penyesuaian {{ $adjustment->adjustment_no }}</title>
    @include('inventory::pdf._stock-adjustment-styles')
</head>
<body>
    <div class="adjustment-page">
        @include('inventory::pdf.stock-adjustment-body', ['adjustment' => $adjustment])
    </div>
</body>
</html>
