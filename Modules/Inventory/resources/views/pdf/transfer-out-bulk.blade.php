<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Jalan ({{ count($transfers) }} dokumen)</title>
    @include('inventory::pdf._transfer-out-styles')
</head>
<body>
    @foreach($transfers as $transfer)
        <div class="transfer-page">
            @include('inventory::pdf.transfer-out-body', ['transfer' => $transfer])
        </div>
    @endforeach
</body>
</html>
