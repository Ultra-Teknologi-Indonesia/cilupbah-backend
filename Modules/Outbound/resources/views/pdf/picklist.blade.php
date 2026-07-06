@php
    /** @var \Modules\Outbound\Models\Picklist $picklist */
    /** @var string|null $qrDataUri */
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pengambilan Barang {{ $picklist->picklist_no }}</title>
    @include('outbound::pdf._picklist-styles')
</head>
<body>
    <div class="picklist-page">
        @include('outbound::pdf.picklist-body', ['picklist' => $picklist, 'qrDataUri' => $qrDataUri])
    </div>
</body>
</html>
