# ADR: Pool Worker Horizon Berdasarkan Kapabilitas dan Koneksi Redis

## Status

Accepted

## Konteks

Audit produksi menunjukkan worker idle memakai memori tinggi karena Horizon
menjalankan satu proses supervisor dan sedikitnya satu proses PHP worker untuk
hampir setiap queue. Pola satu queue-satu-supervisor menghasilkan 55
supervisor, walaupun antrean sedang sepi. Menambah worker pada pola tersebut
mempercepat sebagian alur, tetapi menaikkan baseline RAM dan meningkatkan
risiko OOM.

Sistem wajib memprioritaskan pencatatan order, fulfillment, tarik AWB, unduh
label, dan push stok. Sebaliknya, satu marketplace yang lambat tidak boleh
menghambat marketplace lain pada proses AWB atau unduh label.

## Keputusan

1. Queue dipisahkan menurut kapabilitas bisnis: order intake, fulfillment,
   stok, marketplace control/webhook, background, AWB, label/PDF, dan
   maintenance.
2. Queue yang kompatibel memakai koneksi Redis yang sama digabung dalam satu
   supervisor dengan `balance=auto`, minimum satu worker, dan batas burst yang
   eksplisit. Horizon mengubah jumlah worker dari backlog queue, bukan jumlah
   worker tetap per queue.
3. AWB request/poll dan unduh label tetap memiliki pool per Shopee, TikTok,
   dan Lazada. Ini adalah bulkhead terhadap outage, kuota API, atau retry
   storm pada satu channel.
4. Order recovery dimasukkan ke pool order-sync, sedangkan prefetch/archive
   label dimasukkan ke pool label. Deployment lama dihapus eksplisit oleh
   pipeline agar manifest yang sudah hilang tidak meninggalkan worker idle.
5. Semua worker recycle dengan `maxTime` dan `maxJobs`. Request memori tiap
   pod dihitung dari master + supervisor + worker idle dengan anggaran 128 MiB
   per proses berdasarkan pengukuran RSS produksi; limits memberi ruang untuk
   burst, bukan konsumsi idle yang dijamin.
6. Queue yang diklaim satu pool harus berada di koneksi Redis yang sama.
   Konfigurasi gagal cepat jika aturan ini dilanggar, agar queue tidak diam-diam
   tidak terlayani.

## Konsekuensi

### Positif

- Supervisor permanen turun dari 55 menjadi 24; baseline idle turun tanpa
  menggabungkan jalur order, fulfillment, stok, AWB, dan label.
- Order, stok, dan resi tetap memiliki kapasitas minimum sendiri serta burst
  yang dibatasi.
- Kegagalan API AWB/label satu marketplace tidak menggunakan worker channel
  lain.
- Worker tidak mengakumulasi memori tanpa batas karena direcycle.

### Negatif

- Queue dalam satu pool berbagi burst ceiling; limit perlu dinaikkan berdasarkan
  metrik oldest-job, depth, rate-limit API, CPU throttling, dan RSS.
- Memindahkan sebuah queue ke koneksi Redis lain memerlukan perubahan pool,
  bukan hanya environment variable.
- Horizon autoscaling mengatur proses di dalam pod. Autoscaling jumlah pod
  berbasis Redis backlog (misalnya KEDA) merupakan fase berikutnya setelah
  metrik backlog stabil dan operator menyetujui instalasi controller cluster.

## Alternatif yang Ditolak

- **Satu supervisor untuk setiap queue**: isolasi tinggi tetapi baseline RAM
  buruk dan tidak sesuai dengan workload idle.
- **Satu pool generic untuk semua pekerjaan**: hemat proses tetapi order,
  resi, label, dan stok dapat saling menunggu.
- **Scale-to-zero seluruh jalur**: menghemat idle RAM, tetapi menambah cold
  start dan berisiko terhadap target respons order/resi operasional.

## Verifikasi Rilis

1. Jalankan test queue coverage dan safety worker.
2. Setelah deploy, pantau RSS semua pod Horizon, jumlah proses, depth ready /
   delayed / reserved, umur job tertua, failed jobs, API 429, dan OOMKill.
3. Bandingkan baseline RSS 15 menit saat idle dengan baseline sebelum rilis.
4. Load-test per channel sebelum menaikkan burst ceiling atau memasang KEDA.
