<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Penyesuaian ({{ count($adjustments) }} dokumen)</title>
    @include('inventory::pdf._stock-adjustment-styles')
</head>
<body>
    @foreach($adjustments as $adjustment)
        <div class="adjustment-page">
            @include('inventory::pdf.stock-adjustment-body', ['adjustment' => $adjustment])
        </div>
    @endforeach
</body>
</html>
