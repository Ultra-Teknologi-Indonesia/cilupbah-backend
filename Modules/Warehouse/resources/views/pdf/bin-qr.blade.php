@php
    /** @var \Modules\Warehouse\Models\Location $location */
    /** @var array $items */
    /** @var string $paper */
    $locationName = $location->location_name ?? '-';
    $hasItems = !empty($items);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bin QR Labels</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #000;
        }
        .page {
            width: 100%;
            page-break-after: always;
        }
        .page:last-child {
            page-break-after: auto;
        }
        .empty-msg {
            text-align: center;
            padding: 40px 12px;
            font-size: 11pt;
            color: #555;
        }

        /* Thermal 50x40 */
        @if($paper === 'thermal_50x40')
        @page { margin: 2mm; }
        body { font-size: 7pt; }
        .label {
            width: 46mm;
            height: 36mm;
            text-align: center;
            padding: 1mm 0;
        }
        .label .loc-name {
            font-size: 7pt;
            font-weight: bold;
            line-height: 1.1;
            margin: 0 0 1mm 0;
            word-wrap: break-word;
        }
        .label .qr {
            width: 24mm;
            height: 24mm;
            margin: 0 auto;
        }
        .label .qr img { width: 100%; height: 100%; }
        .label .bin-code {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8pt;
            font-weight: bold;
            margin-top: 1mm;
            word-wrap: break-word;
            line-height: 1.1;
        }
        @endif

        /* Thermal 80x40 */
        @if($paper === 'thermal_80x40')
        @page { margin: 2mm; }
        body { font-size: 8pt; }
        .label {
            width: 76mm;
            height: 36mm;
            padding: 1mm 0;
            text-align: center;
        }
        .label .loc-name {
            font-size: 9pt;
            font-weight: bold;
            line-height: 1.1;
            margin: 0 0 1mm 0;
        }
        .label .qr {
            width: 26mm;
            height: 26mm;
            margin: 0 auto;
        }
        .label .qr img { width: 100%; height: 100%; }
        .label .bin-code {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 10pt;
            font-weight: bold;
            margin-top: 1mm;
        }
        @endif

        /* A4 single */
        @if($paper === 'a4_single')
        @page { margin: 15mm; }
        body { font-size: 12pt; }
        .label {
            text-align: center;
            padding: 20mm 0;
        }
        .label .loc-name {
            font-size: 20pt;
            font-weight: bold;
            margin: 0 0 10mm 0;
        }
        .label .qr {
            width: 100mm;
            height: 100mm;
            margin: 0 auto;
        }
        .label .qr img { width: 100%; height: 100%; }
        .label .bin-code {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 24pt;
            font-weight: bold;
            margin-top: 10mm;
            letter-spacing: 1pt;
        }
        @endif

        /* A4 multi 4x2 = 8 per page */
        @if($paper === 'a4_multi')
        @page { margin: 10mm; }
        body { font-size: 9pt; }
        .grid {
            width: 100%;
            border-collapse: collapse;
        }
        .grid td {
            width: 50%;
            height: 65mm;
            border: 1px dashed #999;
            text-align: center;
            vertical-align: middle;
            padding: 4mm;
        }
        .grid .loc-name {
            font-size: 9pt;
            font-weight: bold;
            line-height: 1.1;
            margin: 0 0 2mm 0;
        }
        .grid .qr {
            width: 35mm;
            height: 35mm;
            margin: 0 auto;
        }
        .grid .qr img { width: 100%; height: 100%; }
        .grid .bin-code {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 11pt;
            font-weight: bold;
            margin-top: 2mm;
        }
        .grid td.empty {
            border: 1px dashed transparent;
        }
        @endif
    </style>
</head>
<body>
@if (! $hasItems)
    <div class="empty-msg">
        Tidak ada bin di lokasi ini.
    </div>
@elseif ($paper === 'a4_multi')
    @php
        $chunks = array_chunk($items, 8);
    @endphp
    @foreach ($chunks as $chunkIndex => $chunk)
        <div class="page">
            <table class="grid">
                @for ($row = 0; $row < 4; $row++)
                    <tr>
                        @for ($col = 0; $col < 2; $col++)
                            @php $i = $row * 2 + $col; $item = $chunk[$i] ?? null; @endphp
                            @if ($item)
                                <td>
                                    <div class="loc-name">{{ $locationName }}</div>
                                    @if (!empty($item['qr_data_uri']))
                                        <div class="qr"><img src="{{ $item['qr_data_uri'] }}" alt="QR"></div>
                                    @endif
                                    <div class="bin-code">{{ $item['bin_final_code'] }}</div>
                                </td>
                            @else
                                <td class="empty"></td>
                            @endif
                        @endfor
                    </tr>
                @endfor
            </table>
        </div>
    @endforeach
@else
    @foreach ($items as $item)
        <div class="page">
            <div class="label">
                <div class="loc-name">{{ $locationName }}</div>
                @if (!empty($item['qr_data_uri']))
                    <div class="qr"><img src="{{ $item['qr_data_uri'] }}" alt="QR"></div>
                @endif
                <div class="bin-code">{{ $item['bin_final_code'] }}</div>
            </div>
        </div>
    @endforeach
@endif
</body>
</html>
