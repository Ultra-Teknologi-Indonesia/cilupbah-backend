@php
    /** @var \Modules\Inventory\Models\Putaway $putaway */
    /** @var string $sourceLabel */
    $printedAt = now()->timezone('Asia/Jakarta')->format('d M Y H:i');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Putaway {{ $putaway->putaway_no }}</title>
    @include('inventory::pdf._putaway-styles')
</head>
<body>
    @include('inventory::pdf._putaway-body', [
        'putaway' => $putaway,
        'sourceLabel' => $sourceLabel,
    ])

    <div class="footer">
        <table>
            <tr>
                <td>Dicetak tanggal: {{ $printedAt }} &nbsp;|&nbsp; Dicetak oleh: {{ $printedBy ?? '-' }}</td>
                <td class="right">Hal: <span class="page-num"></span></td>
            </tr>
        </table>
    </div>
</body>
</html>
