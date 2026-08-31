<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 22px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 8px; }
        h1 { margin: 0 0 4px; font-size: 15px; }
        .meta { color: #6b7280; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th { background: #e5e7eb; color: #111827; font-weight: bold; }
        th, td { border: 0.5px solid #cbd5e1; padding: 4px 3px; vertical-align: top; word-wrap: break-word; }
        tr { page-break-inside: avoid; }
        .empty { padding: 18px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $filters }} · Dibuat {{ now()->format('d-m-Y H:i') }}</div>
    @if (! $hasRows)
        <div class="empty">Tidak ada data sesuai filter.</div>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($headings as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ is_scalar($cell) || $cell === null ? ($cell ?? '-') : json_encode($cell) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
