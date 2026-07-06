<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Jalan {{ $transfer->transfer_number }}</title>
    @include('inventory::pdf._transfer-out-styles')
</head>
<body>
    <div class="transfer-page">
        @include('inventory::pdf.transfer-out-body', ['transfer' => $transfer])
    </div>
</body>
</html>
