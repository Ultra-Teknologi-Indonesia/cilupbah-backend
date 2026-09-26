# Hasil validasi lokal — simulasi flash sale

Tanggal: 26 September 2026 (WIB)  
Commit yang diuji: `bf62c263` (`test: report complete flash sale latency metrics`)

## Ringkasan

Validasi kode dan kontrak simulasi selesai **lulus**. Pengujian ini tidak
memanggil API Shopee, TikTok, Lazada, kurir, R2 production, atau data
production. Tidak ada business logic order, stok, AWB, maupun label yang
diubah untuk menjalankan validasi ini.

Komputer yang menjalankan validasi ini tidak memiliki `kubectl` maupun Docker,
sehingga simulasi Kubernetes 10.000–20.000 transaksi dan pengukuran CPU/RAM
pod nyata **belum dapat dijalankan dari sini**. Angka kapasitas, waktu rata-rata,
tercepat, terlama, dan puncak resource baru sah setelah harness dijalankan pada
host Kubernetes yang memiliki checkout commit ini.

## Hasil yang benar-benar dijalankan

| Validasi | Hasil | Durasi framework | Cakupan |
| --- | --- | ---: | --- |
| `python3 -m unittest test_harness.py` | Lulus, 8 test | 0,255 detik | Guard rail harness: isolasi namespace, manifest, batas kapasitas, dan laporan. |
| `php artisan test tests/Feature/FlashSaleSimulationTest.php` | Lulus, 3 test / 23 assertion | 20,19 detik | Webhook, pencatatan pesanan, AWB, unduh label, PDF, dan pencatatan metrik melalui kontrak simulator. |
| `php artisan test tests/Feature/AuditLabelCapacityTest.php` | Lulus, 3 test / 6 assertion | 0,69 detik | Audit batas worker/timeout/concurrency untuk label tanpa request channel. |

Waktu proses shell keseluruhan: sekitar 28,5 detik. Ini adalah waktu test lokal,
bukan throughput production dan tidak boleh dipakai sebagai klaim bahwa 20.000
pesanan selesai dalam angka yang sama.

## Metrik yang akan dihasilkan saat test Kubernetes

Untuk setiap alur berikut, laporan `application-final.json` menghasilkan jumlah
sampel, tercepat (`min`), rata-rata (`average`), median (`p50`), `p95`, `p99`,
dan terlama (`max`):

1. webhook diterima;
2. pesanan tercatat;
3. nomor resi/AWB siap;
4. label selesai diunduh;
5. PDF gabungan selesai;
6. stok mencapai versi terbaru.

Laporan yang sama juga mencatat antrean `ready`, `delayed`, dan `reserved`, umur
job tertua, deferred peak, failed job, restart/OOM, serta puncak CPU dan memori
per container dari Metrics Server.

Run dinyatakan lulus hanya bila seluruh target tercatat, tidak ada pesanan
hilang, AWB/label/PDF selesai dan valid, stok mencapai versi terakhir, failed job
nol, serta seluruh antrean akhirnya kosong. `ready` yang sempat naik atau
`delayed` akibat retry tetap terlihat dalam laporan; hasil tersebut tidak boleh
disebut tanpa penumpukan bila deferred peak atau tail latency melewati SLA.

## Menjalankan pengukuran 20.000 transaksi per alur di Kubernetes

Jalankan hanya dari host yang mempunyai `kubectl`, akses cluster, dan checkout
backend pada commit ini. Gunakan namespace yang sama antara `plan` dan `run`:

```bash
cd /path/ke/cilupbah-be
git fetch origin main
git pull --ff-only origin main

export SIM_NS="cilupbah-sim-20k-day-$(date +%Y%m%d-%H%M%S)"

./scripts/flash-sale-kubectl plan \
  --namespace "$SIM_NS" \
  --count 20000 --seconds 86400 --drain-seconds 7200 \
  --shops 50 --skus 500 --bulk 100 --producers 4 \
  --pull-secret ghcr-creds

./scripts/flash-sale-kubectl run \
  --namespace "$SIM_NS" \
  --count 20000 --seconds 86400 --drain-seconds 7200 \
  --shops 50 --skus 500 --bulk 100 --producers 4 \
  --pull-secret ghcr-creds --ack-shared-node-risk
```

`--count 20000` berarti **20.000 target pada masing-masing alur**: webhook dan
pesanan, AWB, label/PDF, dan permintaan sinkronisasi stok. API marketplace
sungguhan tetap tidak dipanggil; traffic hanya mengarah ke simulator di namespace
`cilupbah-sim-*` yang terisolasi.

Cluster saat ini memakai node yang sama untuk production dan simulasi, sehingga
jangan menjalankan run 24 jam saat jam operasional sibuk. Lebih aman melakukan
smoke test 1.000 dulu, lalu test 10.000/20.000 pada maintenance window atau node
khusus. Opsi `--ack-shared-node-risk` adalah pengakuan risiko kontensi resource,
bukan jaminan kapasitas.

## File yang perlu dikirim setelah run

Kirim folder `flash-sale-results/$SIM_NS/`, terutama:

- `summary.json` — status akhir lulus/gagal;
- `application-final.json` — waktu tiap tahap dan pemeriksaan data;
- `resource-summary.json` — puncak CPU/RAM, restart, OOM, dan queue peak;
- `samples.jsonl` — perubahan queue/resource dari waktu ke waktu;
- `effective-worker-config.json` — konfigurasi worker yang benar-benar aktif;
- `marketplace-final.json` dan `file-verification.json` — hasil simulator dan
  validasi PDF.

Dengan file tersebut, hasil dapat dianalisis secara faktual: rata-rata, tercepat,
terlama, ekor keterlambatan, apakah worker mengejar laju masuk, dan apakah ada
deferred/failed/OOM. Sebelum run tersebut, status yang jujur adalah **test kode
lulus, tetapi kapasitas 10.000–20.000 transaksi pada server belum terukur**.
