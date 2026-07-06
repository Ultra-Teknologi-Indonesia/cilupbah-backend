@php
    /** @var \Illuminate\Support\Collection<int, \Modules\Outbound\Models\Picklist> $picklists */
    /** @var \Illuminate\Support\Collection<string, string|null> $qrMap */
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Picklist ({{ count($picklists) }} dokumen)</title>
    @include('outbound::pdf._picklist-styles')
</head>
<body>
    @foreach($picklists as $picklist)
        <div class="picklist-page">
            @include('outbound::pdf.picklist-body', ['picklist' => $picklist, 'qrDataUri' => $qrMap[$picklist->id] ?? null])
        </div>
    @endforeach
</body>
</html>
