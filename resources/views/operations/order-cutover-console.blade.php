<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title>Order Cutover Console</title>
    <style>
        body { margin: 0; color: #172033; background: #f3f6fa; font: 15px/1.5 system-ui, sans-serif; }
        main { width: min(980px, calc(100% - 32px)); margin: 48px auto; }
        .card { background: #fff; border: 1px solid #dbe3ef; border-radius: 14px; padding: 24px; margin: 16px 0; box-shadow: 0 8px 30px #12233b0d; }
        h1 { margin: 0 0 6px; font-size: 24px; } h2 { font-size: 18px; margin-top: 0; }
        label { display: block; font-weight: 650; margin: 14px 0 6px; } input[type=file], input[type=text], input[type=datetime-local] { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #b9c6d8; border-radius: 8px; }
        button { margin-top: 18px; padding: 10px 15px; border: 0; border-radius: 8px; background: #1769e0; color: #fff; font-weight: 700; cursor: pointer; }
        button.danger { background: #c53131; } .muted { color: #607087; } .error { color: #b42318; } .state { font-weight: 800; text-transform: uppercase; }
        pre { overflow: auto; max-height: 480px; padding: 14px; background: #101827; color: #d9e4f2; border-radius: 8px; } a { color: #165ec1; } .hidden { display: none; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; } @media (max-width: 680px) { .grid { grid-template-columns: 1fr; } }
        .warning { background: #fff8e6; border-color: #f4d58a; }
    </style>
</head>
<body>
<main>
    <section class="card">
        <h1>Order Cutover — Hard Cutoff</h1>
        <p class="muted">Preview ini tidak memakai whitelist CSV. Sistem membersihkan order sebelum cutoff dan mempertahankan order mulai cutoff, khusus order WMS Gudang Kecil (kode O).</p>
    </section>

    @if ($errors->any())
        <section class="card error">{{ $errors->first() }}</section>
    @endif

    @if (! $job)
        <section class="card">
            <h2>1. Tentukan cutoff dan dry-run</h2>
            <form method="post" action="{{ route('operations.order-cutover.preview', ['token' => $token]) }}" enctype="multipart/form-data">
                @csrf
                <label for="cutoff_at">Cutoff order (WIB)</label>
                <input required id="cutoff_at" type="datetime-local" name="cutoff_at" step="60" value="{{ old('cutoff_at') }}">
                <p class="muted">Order sebelum waktu ini akan menjadi kandidat pembersihan. Order tepat pada waktu cutoff dan sesudahnya dipertahankan. Contoh: 11 September 2026 pukul 23.00.</p>
                <button type="submit">Mulai dry-run</button>
            </form>
        </section>
    @else
        <section class="card" id="job-card" data-status-url="{{ route('operations.order-cutover.status', ['token' => $token, 'job' => $job->id]) }}">
            <h2>Job {{ $job->type }}</h2>
            <p>Status: <span class="state" id="status">{{ $job->status }}</span></p>
            <p class="muted" id="cutoff-display"></p>
            <p class="error" id="error">{{ $job->error }}</p>
            <p><a id="report" class="{{ $job->report_path ? '' : 'hidden' }}" href="{{ route('operations.order-cutover.report', ['token' => $token, 'job' => $job->id]) }}">Download laporan JSON</a></p>
            <pre id="report-data">{{ $job->report ? json_encode($job->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'Menunggu proses queue…' }}</pre>
        </section>

        @if (in_array($job->type, ['preview', 'hard_preview'], true))
            <section class="card warning {{ $job->status === 'ready' ? '' : 'hidden' }}" id="apply-card">
                <h2>2. Apply order cutover</h2>
                <p>Apply hard cutoff menghapus order sebelum cutoff dan mempertahankan order mulai cutoff. Pastikan sinkronisasi order channel dan proses gudang sudah dihentikan.</p>
                <form method="post" action="{{ route('operations.order-cutover.apply', ['token' => $token, 'job' => $job->id]) }}">
                    @csrf
                    <label><input required type="checkbox" name="warehouse_stopped" value="1"> Proses gudang sudah berhenti.</label>
                    <label><input required type="checkbox" name="order_sync_paused" value="1"> Sinkronisasi order channel sudah dihentikan.</label>
                    <label><input type="checkbox" name="allow_partial" value="1"> Izinkan apply hanya kandidat aman (blocking dilewati)</label>
                    <p class="muted">Tanpa opsi ini, satu blocking issue membatalkan seluruh apply. Mode partial hanya menghapus kandidat yang belum diproses dan tidak memiliki relasi child.</p>
                    <label>Ketik <code>APPLY-ORDER-CUTOVER</code> untuk konfirmasi</label>
                    <input required type="text" name="confirmation" autocomplete="off">
                    <button class="danger" type="submit">Jalankan apply di background</button>
                </form>
            </section>
        @endif
        <p><a href="{{ route('operations.order-cutover.index', ['token' => $token]) }}">Mulai job baru</a></p>
    @endif
</main>
@if ($job)
<script>
const box = document.getElementById('job-card');
const state = document.getElementById('status');
const cutoffDisplay = document.getElementById('cutoff-display');
const error = document.getElementById('error');
const report = document.getElementById('report');
const reportData = document.getElementById('report-data');
const applyCard = document.getElementById('apply-card');
async function refresh() {
    if (refresh.inFlight) return;
    refresh.inFlight = true;
    try {
        const response = await fetch(box.dataset.statusUrl, { credentials: 'same-origin', referrerPolicy: 'no-referrer' });
        if (response.status === 429) { refresh.delay = Math.min(Math.max(Number(response.headers.get('Retry-After') || 15) * 1000, 5000), 60000); error.textContent = `Status dibatasi. Coba lagi dalam ${Math.ceil(refresh.delay / 1000)} detik.`; refresh.timer = setTimeout(refresh, refresh.delay); return; }
        if (!response.ok) { refresh.delay = Math.min(refresh.delay * 2, 60000); error.textContent = `Status sementara tidak dapat dibaca (${response.status}).`; refresh.timer = setTimeout(refresh, refresh.delay); return; }
        const data = await response.json();
        state.textContent = data.status; error.textContent = data.error || '';
        cutoffDisplay.textContent = data.report && data.report.cutoff_at_wib ? `Cutoff WIB: ${data.report.cutoff_at_wib}` : '';
        reportData.textContent = data.report ? JSON.stringify(data.report, null, 2) : 'Menunggu proses queue…';
        if (data.download_ready) report.classList.remove('hidden');
        if (['preview', 'hard_preview'].includes(data.type) && data.status === 'ready' && applyCard) applyCard.classList.remove('hidden');
        refresh.delay = 5000;
        if (['queued', 'processing'].includes(data.status)) refresh.timer = setTimeout(refresh, refresh.delay);
    } catch (exception) { refresh.delay = Math.min(refresh.delay * 2, 60000); error.textContent = 'Status sementara tidak dapat dibaca. Mencoba lagi otomatis.'; refresh.timer = setTimeout(refresh, refresh.delay); }
    finally { refresh.inFlight = false; }
}
refresh.delay = 5000; refresh.inFlight = false; refresh.timer = null; refresh();
</script>
@endif
</body>
</html>
