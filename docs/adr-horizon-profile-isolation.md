# ADR: Pool Horizon Berdasarkan Kapabilitas Operasional

Status: Superseded by `adr-horizon-pooled-worker-architecture.md`
Tanggal: 2026-09-22

## Konteks

Satu deployment `critical` sebelumnya melayani order masuk, fulfillment,
tarik resi, stok, pembatalan, dan tracking. Ketika satu alur marketplace
melambat, worker PHP yang tetap hidup mengonsumsi RAM dan pekerjaan lain ikut
menunggu. Menambah semua worker sekaligus tidak aman karena kuota API tiap
marketplace dan toko berbeda.

## Keputusan

Dokumen ini mencatat keputusan awal bahwa setiap kapabilitas operasional
memiliki profil Horizon dan Deployment sendiri:

- `order-intake`: order internal, order Shopee/TikTok/Lazada, dan refresh order;
- `fulfillment`: fulfillment umum dan per marketplace/package;
- `stock`: kalkulasi stok, warehouse safety, outbox, serta push stok;
- `marketplace-ops`: pembatalan, tracking, dan webhook operasional non-order;
- `labels-awb`: request dan polling resi per marketplace;
- `labels-pdf`: download dokumen label per marketplace dan penggabungan PDF;
- `background`: katalog, finance, after-sales, ekspor, serta tugas non-operasional.
- `maintenance`: cutover, QR label, dan download generik yang dapat berat di memori.

Satu deployment hanya menjalankan satu profil dan profil tidak boleh memiliki
supervisor yang overlap. Queue Redis tetap menjadi sumber kerja yang durable.
Pada rollout, deployment generic lama dihentikan hingga nol sebelum pool baru
diterapkan, sehingga dua master tidak mengeksekusi side effect marketplace yang
sama.

Supervisor memakai satu worker hangat dan dapat tumbuh sampai ceiling yang
dibatasi. Untuk AWB dan label, ceiling ini berlaku per marketplace; tidak
berarti request API dapat dikirim tanpa batas. Lock per order/package serta
rate limit marketplace tetap menjadi pagar utama terhadap duplikasi dan 429.

## Alternatif yang dipertimbangkan

- **Satu `critical` besar dengan worker maksimum** — ditolak; tidak memberi
  isolasi, baseline RAM tinggi, dan satu antrean lambat mengganggu yang lain.
- **Satu pod permanen per job individual** — ditolak; terlalu banyak master
  Horizon, mahal di RAM, dan sulit dioperasikan. Unit isolasi yang tepat adalah
  kapabilitas bisnis, bukan satu instance job.
- **Memecah aplikasi menjadi microservice sekarang** — ditunda; modular monolith
  Laravel + queue terpisah memberi isolasi yang dibutuhkan dengan risiko rilis
  dan biaya operasi jauh lebih kecil.

## Konsekuensi historis

- Positif: resi/label, stok, fulfillment, dan order tidak saling menyumbat.
- Negatif yang ditemukan setelah audit RSS: satu supervisor permanen per queue
  tetap membentuk baseline RAM tinggi walaupun worker hanya satu. Desain ini
  digantikan oleh pool queue berdasarkan kapabilitas dan koneksi Redis.
- Positif: batas RAM dan CPU dapat disetel per domain berdasarkan hasil load test.
- Negatif: jumlah Deployment dan dashboard yang perlu dipantau bertambah.
- Negatif: kapasitas tidak boleh dinaikkan melebihi kuota API marketplace.

## Verifikasi rilis

1. Pastikan setiap profil Horizon hanya memuat supervisor miliknya.
2. Pastikan queue prioritas dilayani tepat satu pool dan tidak ada pending job
   tanpa worker.
3. Pantau umur job tertua, depth ready/delayed/reserved, failed job, API 429,
   cgroup OOM, CPU throttling, dan RSS per pool.
4. Load test per channel/toko sebelum menaikkan concurrency ceiling.
