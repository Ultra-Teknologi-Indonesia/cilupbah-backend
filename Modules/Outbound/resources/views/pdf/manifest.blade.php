@php
    /** @var \Modules\Outbound\Models\Shipment $shipment */
    /** @var string|null $qrDataUri */
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Manifest {{ $shipment->shipment_no }}</title>
    @include('outbound::pdf._manifest-styles')
</head>
<body>
    <div class="manifest-page">
        @include('outbound::pdf.manifest-body', ['shipment' => $shipment, 'qrDataUri' => $qrDataUri])
    </div>
</body>
</html>
