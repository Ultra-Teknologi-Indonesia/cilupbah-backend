<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title>Stock Cutover Console</title>
    <style>
        body { margin: 0; color: #172033; background: #f3f6fa; font: 15px/1.5 system-ui, sans-serif; }
        main { width: min(900px, calc(100% - 32px)); margin: 48px auto; }
        .card { background: #fff; border: 1px solid #dbe3ef; border-radius: 14px; padding: 24px; margin: 16px 0; box-shadow: 0 8px 30px #12233b0d; }
        h1 { margin: 0 0 6px; font-size: 24px; } h2 { font-size: 18px; margin-top: 0; }
        label { display: block; font-weight: 650; margin: 14px 0 6px; } input[type=file], input[type=text] { width: 100%; box-sizing: border-box; }
        input[type=text] { padding: 10px; border: 1px solid #b9c6d8; border-radius: 8px; }
        button { margin-top: 18px; padding: 10px 15px; border: 0; border-radius: 8px; background: #1769e0; color: #fff; font-weight: 700; cursor: pointer; }
        button.danger { background: #c53131; } .muted { color: #607087; } .error { color: #b42318; } .state { font-weight: 800; text-transform: uppercase; }
        pre { overflow: auto; max-height: 360px; padding: 14px; background: #101827; color: #d9e4f2; border-radius: 8px; }
        a { color: #165ec1; } .hidden { display: none; }
    </style>
</head>
<body>
<main>
    <section class="card">
        <h1>Stock Cutover — Qty Aktual</h1>
        <p class="muted">Tidak perlu login. Halaman ini hanya dapat dibuka melalui URL token operasi. Preview dan apply berjalan di queue agar request web tidak menunggu proses Excel.</p>
    </section>

    @if ($errors->any())
        <section class="card error">{{ $errors->first() }}</section>
    @endif

    @if (! $job)
        <section class="card">
            <h2>1. Upload dan dry run</h2>
            <p class="muted">Sumber stok adalah <strong>Qty Aktual</strong>. File hanya disimpan untuk job ini dan tidak mengubah database pada tahap preview.</p>
            <form method="post" action="{{ route('operations.stock-cutover.preview', ['token' => $token]) }}" enctype="multipart/form-data">
                @csrf
                <label>migrasi-kecil.xlsx</label>
                <input required type="file" name="gudang_kecil" accept=".xlsx">
                <label>migrasi-pusat.xlsx</label>
                <input required type="file" name="pusat" accept=".xlsx">
                <button type="submit">Mulai dry run</button>
            </form>
        </section>
    @else
        <section class="card" id="job-card" data-status-url="{{ route('operations.stock-cutover.status', ['token' => $token, 'job' => $job->id]) }}">
            <h2>Job {{ $job->type }}</h2>
            <p>Status: <span class="state" id="status">{{ $job->status }}</span></p>
            <p class="error" id="error">{{ $job->error }}</p>
            <p><a id="report" class="{{ $job->report_path ? '' : 'hidden' }}" href="{{ route('operations.stock-cutover.report', ['token' => $token, 'job' => $job->id]) }}">Download laporan JSON</a></p>
            <p id="csv-reports" class="{{ $job->report_path ? '' : 'hidden' }}">
                <a href="{{ route('operations.stock-cutover.report.csv', ['token' => $token, 'job' => $job->id, 'location' => 'O']) }}">CSV Gudang Kecil</a>
                ·
                <a href="{{ route('operations.stock-cutover.report.csv', ['token' => $token, 'job' => $job->id, 'location' => 'WH-PUSAT']) }}">CSV Pusat</a>
            </p>
            <pre id="report-data">{{ $job->report ? json_encode($job->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'Menunggu proses queue…' }}</pre>
        </section>

        @if ($job->type === 'preview')
            <section class="card {{ $job->status === 'ready' ? '' : 'hidden' }}" id="apply-card">
                <h2>2. Apply stok aktual</h2>
                <p class="error">Apply menulis stok fisik berdasarkan Qty Aktual dan menolkan stok yang tidak ada dalam file. Jalankan hanya setelah operasional gudang benar-benar berhenti.</p>
                <form method="post" action="{{ route('operations.stock-cutover.apply', ['token' => $token, 'job' => $job->id]) }}">
                    @csrf
                    <label><input required type="checkbox" name="operations_stopped" value="1"> Operasional gudang sudah berhenti.</label>
                    <label><input type="checkbox" name="allow_partial" value="1"> Izinkan apply hanya baris valid (baris invalid dilewati)</label>
                    <p class="muted">Tanpa opsi ini, satu baris invalid membatalkan seluruh apply. Gunakan mode partial hanya setelah laporan invalid ditinjau.</p>
                    <label>Ketik <code>APPLY-STOK-AKTUAL</code> untuk konfirmasi</label>
                    <input required type="text" name="confirmation" autocomplete="off">
                    <button class="danger" type="submit">Jalankan apply di background</button>
                </form>
            </section>
        @endif

        <p><a href="{{ route('operations.stock-cutover.index', ['token' => $token]) }}">Mulai job baru</a></p>
    @endif
</main>

@if ($job)
<script>
const box = document.getElementById('job-card');
const state = document.getElementById('status');
const error = document.getElementById('error');
const report = document.getElementById('report');
const csvReports = document.getElementById('csv-reports');
const reportData = document.getElementById('report-data');
const applyCard = document.getElementById('apply-card');

async function refresh() {
    if (refresh.inFlight) return;
    refresh.inFlight = true;

    try {
        const response = await fetch(box.dataset.statusUrl, { credentials: 'same-origin', referrerPolicy: 'no-referrer' });

        if (response.status === 429) {
            const retryAfter = Number(response.headers.get('Retry-After'));
            refresh.delay = Math.min(Math.max((retryAfter || 15) * 1000, 5000), 60000);
            error.textContent = `Status sedang dibatasi. Mencoba lagi dalam ${Math.ceil(refresh.delay / 1000)} detik.`;
            refresh.timer = setTimeout(refresh, refresh.delay);
            return;
        }

        if (!response.ok) {
            refresh.delay = Math.min(refresh.delay * 2, 60000);
            error.textContent = `Status sementara tidak dapat dibaca (${response.status}).`;
            refresh.timer = setTimeout(refresh, refresh.delay);
            return;
        }

        const data = await response.json();
        state.textContent = data.status;
        error.textContent = data.error || '';
        reportData.textContent = data.report ? JSON.stringify(data.report, null, 2) : 'Menunggu proses queue…';
        if (data.download_ready) { report.classList.remove('hidden'); csvReports.classList.remove('hidden'); }
        if (data.type === 'preview' && data.status === 'ready' && applyCard) applyCard.classList.remove('hidden');

        refresh.delay = 5000;
        if (['queued', 'processing'].includes(data.status)) {
            refresh.timer = setTimeout(refresh, refresh.delay);
        }
    } catch (exception) {
        refresh.delay = Math.min(refresh.delay * 2, 60000);
        error.textContent = 'Status sementara tidak dapat dibaca. Mencoba lagi otomatis.';
        refresh.timer = setTimeout(refresh, refresh.delay);
    } finally {
        refresh.inFlight = false;
    }
}

refresh.delay = 5000;
refresh.inFlight = false;
refresh.timer = null;
refresh();
</script>
@endif
</body>
</html>
