# Simulasi flash sale tanpa API marketplace sungguhan

Paket ini menjalankan aplikasi Laravel, PostgreSQL, PgBouncer, Redis, Horizon, job AWB, job unduh label, dan pembuatan PDF secara nyata. Yang diganti hanya marketplace dengan simulator lokal. Simulator tidak mempunyai kredensial marketplace dan namespace Kubernetes-nya dibatasi agar hanya berkomunikasi di dalam namespace simulasi.

Simulasi ini menguji **Shopee contract**. Jalur bisnis lintas channel tetap sama, tetapi ini bukan klaim kapasitas TikTok atau Lazada.

## Prasyarat

- Jalankan dari direktori `cilupbah-be` pada host yang memiliki akses `kubectl` ke cluster.
- `metrics-server` harus tersedia (`kubectl top nodes` berhasil).
- Image aplikasi yang sudah berisi checkout ini harus dapat ditarik oleh cluster. Secara default harness menyalin
  secret registry `ghcr-creds` dari namespace sumber ke namespace simulasi saja. Secret ini hanya untuk menarik image,
  tidak berisi token marketplace dan tidak pernah dimasukkan ke `manifest.json` atau laporan.
- Gunakan node khusus pengujian bila tersedia. Jika node yang sama dipakai production, gunakan `--ack-shared-node-risk` hanya setelah menyetujui risiko kontensi resource.
- Jangan menaruh kredensial production pada manifest. Harness membuat kredensial database simulasi sendiri.

Jangan jalankan `migrate:fresh`, jangan memakai namespace `cilupbah`, dan jangan mengarahkan `SHOPEE_HOST` ke domain marketplace.

Jika image public atau sudah cached di node, registry secret tidak diperlukan: tambahkan `--pull-secret ''` pada
`plan` dan `run`. Jika image private, pastikan secret dengan nama tersebut sudah ada di namespace sumber; harness
akan menyalin payload registry ke namespace baru secara otomatis dan tidak menampilkan nilainya.

## Menyiapkan image Docker

K3s memakai containerd, sehingga image yang ada di Docker Desktop atau Docker Engine lokal tidak otomatis terlihat
oleh node Kubernetes. Untuk cluster remote, build lalu push ke registry:

```bash
export IMAGE=ghcr.io/ultra-teknologi-indonesia/cilupbah:flash-sim-$(date +%Y%m%d-%H%M%S)
docker build --pull -t "$IMAGE" .
docker push "$IMAGE"
```

Jika Docker dan K3s berjalan pada node yang sama, image juga dapat diimpor tanpa registry:

```bash
docker build -t cilupbah-flash-sim:local .
docker save cilupbah-flash-sim:local | sudo k3s ctr images import -
```

Untuk mode impor lokal, pakai `--image cilupbah-flash-sim:local --pull-secret ''`; image harus diimpor ke node yang
dipilih lewat `--node` dan tidak boleh memakai `imagePullPolicy: Always`.

## Tahap 1 — audit read-only

Perintah ini hanya membaca deployment worker, limit CPU/memori, grace period, node metrics, routing queue, backpressure, dan batas API efektif dari pod production. Tidak mengubah cluster.

```bash
cd /path/ke/cilupbah-be
python3 scripts/flash-sale-test audit \
  --source-namespace cilupbah \
  --output flash-sale-results
```

Hasil tersimpan di `flash-sale-results/<timestamp>/source-audit.json`. Periksa khususnya `effective.workers`, `effective.routing`, `effective.backpressure`, `node_metrics`, dan limit worker.

### Perintah langsung dari server Kubernetes

Jika source code repository sudah ada di host `deploy`, gunakan wrapper berikut.
Wrapper membaca image yang sedang dipakai `cilupbah-app` dan node production
secara otomatis, lalu meneruskan pekerjaan ke harness yang membuat namespace
`cilupbah-sim-*`. Ia tidak pernah mengubah namespace `cilupbah`.

```bash
cd /path/ke/cilupbah-be
chmod +x scripts/flash-sale-kubectl
./scripts/flash-sale-kubectl audit
```

Untuk mulai smoke test baru (1.000 target untuk masing-masing order, AWB,
label, dan push stok), jalankan setelah audit:

```bash
cd /path/ke/cilupbah-be
./scripts/flash-sale-kubectl run \
  --namespace "cilupbah-sim-smoke-$(date +%Y%m%d-%H%M%S)" \
  --count 1000 \
  --seconds 600 \
  --drain-seconds 1800 \
  --shops 20 \
  --skus 100 \
  --bulk 100 \
  --producers 4 \
  --pull-secret ghcr-creds \
  --ack-shared-node-risk
```

`--ack-shared-node-risk` wajib karena cluster saat ini memakai node yang sama
untuk production dan simulasi. Untuk menguji tanpa membuat resource, ganti
`run` menjadi `plan`. Untuk menghentikan test tanpa menghapus bukti, gunakan:

```bash
./scripts/flash-sale-kubectl stop \
  --namespace cilupbah-sim-smoke-YYYYMMDD-HHMMSS
```

Perintah tersebut dijalankan dari host yang memiliki akses `kubectl`, bukan
dari dalam pod aplikasi. API marketplace tidak dipanggil; traffic hanya menuju
simulator lokal di namespace simulasi.

## Tahap 2 — buat rencana tanpa membuat resource

Gunakan image aplikasi yang memang tersedia di registry cluster. Contoh:

```bash
IMAGE=ghcr.io/ultra-teknologi-indonesia/cilupbah:v1.0.0
NODE=temet01bare127.neometal.id
PULL_SECRET=ghcr-creds

python3 scripts/flash-sale-test plan \
  --source-namespace cilupbah \
  --namespace cilupbah-sim-plan-20260925 \
  --image "$IMAGE" \
  --node "$NODE" \
  --count 1000 \
  --seconds 600 \
  --shops 20 \
  --skus 100 \
  --bulk 100 \
  --producers 4 \
  --pull-secret "$PULL_SECRET"
```

`plan` hanya menulis `manifest.json`; tidak membuat namespace, PVC, pod, job, atau antrean.

## Tahap 3 — smoke test

Mulai dari 1.000 operasi untuk setiap kategori. `--count` berarti target masing-masing: 1.000 order, 1.000 pekerjaan/pesanan AWB, 1.000 label, dan 1.000 permintaan sinkronisasi stok. Job AWB dan stok boleh memakai endpoint massal atau menggabungkan perubahan sesuai business logic; laporan tetap memeriksa setiap pesanan dan versi stok terakhir. Test tidak berhenti hanya karena producer selesai; coordinator menunggu seluruh antrean terkuras.

```bash
python3 scripts/flash-sale-test run \
  --source-namespace cilupbah \
  --namespace cilupbah-sim-smoke-20260925 \
  --image "$IMAGE" \
  --node "$NODE" \
  --count 1000 \
  --seconds 600 \
  --drain-seconds 1800 \
  --shops 20 \
  --skus 100 \
  --bulk 100 \
  --producers 4 \
  --pull-secret "$PULL_SECRET" \
  --ack-shared-node-risk
```

Target berikutnya baru boleh dijalankan setelah smoke test selesai tanpa `INCOMPLETE`:

```bash
python3 scripts/flash-sale-test run \
  --source-namespace cilupbah \
  --namespace cilupbah-sim-10k-20260925 \
  --image "$IMAGE" \
  --node "$NODE" \
  --count 10000 \
  --seconds 3600 \
  --drain-seconds 3600 \
  --shops 50 \
  --skus 500 \
  --bulk 100 \
  --producers 4 \
  --pull-secret "$PULL_SECRET" \
  --ack-shared-node-risk
```

## Tahap 4 — target satu juta

Untuk satu juta setiap kategori selama 24 jam:

```bash
python3 scripts/flash-sale-test run \
  --source-namespace cilupbah \
  --namespace cilupbah-sim-1m-day-20260925 \
  --image "$IMAGE" \
  --node "$NODE" \
  --count 1000000 \
  --seconds 86400 \
  --drain-seconds 21600 \
  --shops 200 \
  --skus 5000 \
  --bulk 100 \
  --producers 8 \
  --pull-secret "$PULL_SECRET" \
  --ack-shared-node-risk
```

Untuk meniru flash sale sepuluh menit, gunakan `--seconds 600` dan tetap beri `--drain-seconds` yang cukup. Ini adalah lonjakan yang jauh lebih berat daripada satu juta yang tersebar selama sehari.

Jangan menaikkan `--shopee-api-rate` untuk menyimpulkan batas production. Nilai bawaan mengikuti konfigurasi channel saat audit (umumnya 4 request/detik). Simulator hanya membantu mengukur antrean internal; batas marketplace sungguhan tetap berlaku di luar tes ini.

## Gangguan yang dapat disimulasikan

Tambahkan opsi berikut pada smoke test atau test 10k:

```bash
--latency-ms 250 --fail-every 100
```

Artinya simulator menunggu 250 ms dan mengembalikan 429 setiap panggilan ke-100. Ini menguji retry, idempotensi, status `delayed`, dan pemulihan. Ini bukan error sungguhan dari marketplace.

## Isi laporan

Setiap run membuat folder `flash-sale-results/<namespace>/`:

- `source-audit.json` — konfigurasi worker/pod production yang dibaca sebelum test.
- `manifest.json` — resource simulasi yang direncanakan.
- `effective-worker-config.json` — queue routing, profile Horizon, backpressure, dan rate limit yang benar-benar aktif.
- `samples.jsonl` — sampel periodik queue, pod CPU/memori, restart, node memory, dan disk. Format JSONL dipakai agar file satu hari tidak dimuat seluruhnya ke RAM.
- `application-final.json` — jumlah order, status inbox, AWB, item label, batch PDF, stock outbox, failed jobs, koneksi dan lock database.
- `marketplace-final.json` — jumlah panggilan simulator, shipment, dokumen, unduhan, dan nilai stok terbaru.
- `resource-summary.json` — puncak CPU/memori per container, puncak ready/delayed/reserved, umur job tertua, deferred peak, restart, dan OOM.
- `file-verification.json` — pemeriksaan PDF dengan `pdfinfo` dan jumlah halaman.
- `summary.json` — hasil akhir.

`summary.completed=true` hanya jika seluruh target masuk, tidak ada pesanan hilang, AWB dan label selesai, PDF valid, stock outbox sudah konvergen ke versi terakhir, failed jobs nol, dan antrean `ready + delayed + reserved` menjadi nol. Queue yang kosong tanpa pemeriksaan database **tidak** dianggap lulus.

## Membaca penumpukan

- `ready` naik terus: worker tidak mengejar laju kedatangan.
- `delayed` naik: retry/backoff atau backpressure aktif. Ini bukan bukti pekerjaan hilang, tetapi harus kembali turun.
- `reserved` tinggi: worker sedang mengerjakan atau lease terlalu lama.
- `oldest_ready_seconds` terus naik: ada pekerjaan yang menunggu terlalu lama.
- `deferred_peak > 0`: sistem pernah menunda penerimaan karena kapasitas; run tidak boleh diklaim “tanpa deferred”.
- `failed_jobs > 0`, `oom_pods` tidak kosong, atau restart meningkat: run gagal untuk tujuan reliability.

Perhatikan `stock_target_kind` pada summary. Satu juta perubahan stok dapat digabung menjadi lebih sedikit panggilan API karena outbox menyimpan nilai terbaru. Yang wajib sama adalah versi stok terakhir dan hasil akhir, bukan jumlah HTTP yang sengaja diperbanyak.

## Menghentikan dan membersihkan

Jika tekanan resource terlalu tinggi atau ingin menghentikan run, ini hanya menskalakan worker/coordinator ke nol dan mempertahankan bukti:

```bash
python3 scripts/flash-sale-test stop \
  --namespace cilupbah-sim-smoke-20260925
```

Setelah laporan disalin dan sudah diverifikasi, namespace simulasi dapat dihapus manual dengan nama **tepat** namespace simulasi. Jangan pernah mengganti nama command menjadi namespace production:

```bash
kubectl delete namespace cilupbah-sim-smoke-20260925
```

Penghapusan namespace menghapus database, Redis, PVC, dan file sintetis run tersebut. Tidak menyentuh namespace `cilupbah`.

## Batas kesimpulan

Run ini membuktikan kapasitas dan ketepatan alur pada spesifikasi node yang dipakai, dengan kontrak marketplace sintetis. Ia tidak membuktikan kuota, latency, atau ketersediaan API marketplace sungguhan, dan tidak menghilangkan risiko single-node. Hasil satu juta harus dibaca bersama CPU, memori, database/PgBouncer, disk, queue drain, retry, dan konsistensi data—bukan hanya waktu producer selesai.
