<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 8mm; }
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 7px; }
        h1 { font-size: 14px; margin: 0 0 3px; }
        .meta { color: #4b5563; font-size: 8px; margin: 0 0 10px; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th, td { border: 0.4px solid #9ca3af; overflow-wrap: anywhere; padding: 3px; text-align: left; vertical-align: top; }
        th { background: #e5e7eb; font-weight: bold; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">Dibuat {{ $generatedAt->format('d/m/Y H:i') }} · {{ count($rows) }} baris</p>
    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($headings as $index => $heading)
                        <td>{{ $row[$index] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ max(1, count($headings)) }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
