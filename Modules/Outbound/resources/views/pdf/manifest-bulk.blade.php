@php
    /** @var \Illuminate\Support\Collection<int, \Modules\Outbound\Models\Shipment> $shipments */
    /** @var \Illuminate\Support\Collection<string, string|null> $qrMap */
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Manifest ({{ count($shipments) }} dokumen)</title>
    @include('outbound::pdf._manifest-styles')
</head>
<body>
    @foreach($shipments as $shipment)
        <div class="manifest-page">
            @include('outbound::pdf.manifest-body', ['shipment' => $shipment, 'qrDataUri' => $qrMap[$shipment->id] ?? null])
        </div>
    @endforeach
</body>
</html>
