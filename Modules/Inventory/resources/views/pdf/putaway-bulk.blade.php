@php
    /** @var array $docs  daftar ['putaway' => ..., 'sourceLabel' => ...] */
    $printedAt = now()->timezone('Asia/Jakarta')->format('d M Y H:i');
    $docs = collect($docs ?? []);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Putaway (Bulk)</title>
    @include('inventory::pdf._putaway-styles')
</head>
<body>
    @forelse($docs as $doc)
        <div @if(! $loop->last) class="doc-break" @endif>
            @include('inventory::pdf._putaway-body', [
                'putaway' => $doc['putaway'],
                'sourceLabel' => $doc['sourceLabel'],
            ])
        </div>
    @empty
        <p style="text-align:center; padding: 24px;">Tidak ada dokumen penempatan.</p>
    @endforelse

    <div class="footer">
        <table>
            <tr>
                <td>Dicetak tanggal: {{ $printedAt }} &nbsp;|&nbsp; Dicetak oleh: {{ $printedBy ?? '-' }} &nbsp;|&nbsp; {{ $docs->count() }} dokumen</td>
                <td class="right">Hal: <span class="page-num"></span></td>
            </tr>
        </table>
    </div>
</body>
</html>
