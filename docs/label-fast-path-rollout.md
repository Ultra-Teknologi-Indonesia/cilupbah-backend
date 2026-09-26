# Optimasi resi dan label: verifikasi dan rollout

Status: perubahan lokal, belum deploy atau uji beban production. Laravel/Horizon dalam Kubernetes namespace `cilupbah`, FE Next.js. Markdown mengikuti pilihan pengguna.

## Perubahan

- TikTok menggunakan package ID tersimpan ketika status lokal layak kirim. Detail batch dibaca jika data belum lengkap. Field packages yang tidak disertakan dalam response tidak menghapus ID tersimpan; array kosong eksplisit tetap authoritative.
- Request TikTok dibagi berdasarkan jumlah package, maksimal konfigurasi 50 package per HTTP request, termasuk jalur multi-package. Ini batas konservatif implementasi, bukan klaim batas resmi terbaru. Satu order dapat memiliki banyak package.
- Response tidak pasti, hasil paket tidak lengkap, atau shipment berhasil sebagian masuk verifikasi sebelum pengiriman ulang. Guard pembatalan, fulfillment, dan operation ledger tetap digunakan. Status lokal dapat tertinggal jika webhook terlambat.
- Prefetch background tidak boleh meminta shipment, termasuk job lama yang membawa flag request. Antrean manual per channel diperiksa sebelum background berjalan. Ini tidak menghentikan API call yang sudah berjalan.
- Persiapan dokumen Shopee asynchronous aktif secara default. Environment eksplisit `false` tetap mengalahkan default. Merge worker tidak perlu mempersiapkan channel secara sinkron.
- Shopee limiter wajib mendapatkan slot baru setelah menunggu, melalui shared lock. Tunggu default maksimal 2 detik; slot habis menjadi error retryable, bukan menerobos kuota. Gunakan Redis bersama.
- Tombol **Cetak N Label Siap** membuat salinan file siap tanpa meminta resi/label channel, kemudian menjalankan merge di worker yang sudah ada. Sumber tetap berjalan.
- Salinan dibatasi 500 label/64 MiB, satu proses copy snapshot sekaligus, lock bersama finalizer, dan pemeriksaan ruang disk untuk dua kali ukuran salinan + cadangan 256 MiB. Bukan kuota global seluruh penulisan aplikasi.
- Snapshot sama dipakai kembali. Ownership, akses gudang dan pembatalan diperiksa; akses gudang diperiksa lagi saat download. Kegagalan copy tidak menghapus sumber.
- Progres baris modal menggunakan explicit refetch yang digabung per 750 ms, bukan invalidateQueries. Polling cadangan 10 detik hanya saat processing; preview 5 detik. Tanggal modal WIB.
- Lazada memecah pengambilan dokumen menjadi kelompok maksimal 20 package sesuai batas jalur yang ada, lalu menggabungkan seluruh PDF. Bagian belum siap, gagal diunduh, error response, atau PDF tidak valid tidak diterbitkan sebagai dokumen lengkap. Potongan sukses disimpan dengan reuse window 10 menit agar retry tidak selalu mengunduh ulang semua bagian.
- Cache per-order (file asal, FPDI dan thermal) disimpan atomik di shared print spool; pencatatan arsip berada di tabel `shipping_label_cache_artifacts`. Upload dan verifikasi ukuran/checksum SHA-256 berjalan di antrean `label-archive`, bukan di jalur cetak. Payload job hanya ID, bukan PDF/base64.
- TikTok cetak langsung menggabungkan seluruh URL paket. Respons yang tidak memuat label untuk semua package ditolak sebagai belum lengkap.
- Lazada admission menunggu paling lama 2 detik secara default, melepas lock selama menunggu dan wajib memperoleh slot sebelum mengirim. Kuota tidak dinaikkan; overload tetap ditunda/retry, bukan diterobos.

## Batas yang tetap ada

1. Cold request yang menunggu marketplace tidak dijamin selesai dalam detik. Batch shipping TikTok tidak berarti endpoint label juga batch.
2. Cetak sebagian mencetak semua label yang saat itu siap, termasuk yang pernah dicetak. Belum ada deteksi keberhasilan printer fisik atau pengecualian otomatis label pernah dicetak.
3. Retry/polling terbatas yang ada tetap berjalan; belum diubah menjadi tepat satu putaran lalu wajib klik retry. Perhitungan stok dan status bisnis tidak diubah.
4. Arsip PDF gabungan mengikuti `bulk-labels.archive_disk` (default `documents`) setelah delivery. Cache PDF per-order kini diarsipkan background ke disk yang sama. Konfigurasi server harus menunjuk R2; bila masih driver local, patch tidak otomatis mengubahnya menjadi R2. Membaca arsip lama/yang salinan lokalnya sudah dibersihkan masih membutuhkan object storage. Konversi PDF tetap dikerjakan di jalur render yang ada.
5. Memori Horizon adalah pemicu recycle, bukan hard cap setiap job. Native PDF merger, Redis persistence, database, pod surge dan host tetap membutuhkan headroom. Worker/replica tidak dinaikkan pada patch ini.
6. Single-node bukan zero-downtime saat host rusak. Regresi bukan bukti kapasitas satu juta order/hari.

## Prasyarat rollout

- Deploy BE sebelum FE snapshot. Migrasi request_key terdahulu dan index `(user_id, request_key, status)` harus tersedia.
- App/worker harus berbagi PVC print spool dengan izin sesuai.
- Jalankan migrasi baru `2026_09_25_090000_create_shipping_label_cache_artifacts_table` melalui job migrasi deployment sebelum image app/worker baru menerima pekerjaan. Migrasi hanya menambah tabel dan index baru. Jangan drop tabel atau menghapus spool ketika masih ada arsip pending.
- Semua worker/scheduler perlu versi kode baru; `shipping-labels:reconcile-cache` terdaftar setiap menit. Worker `label-archive` yang sudah ada harus aktif. Tidak ada penambahan replica/process pada patch ini.
- Pastikan `LABEL_ASYNC_SHOPEE_PREPARATION=true` efektif setelah config cache dibuat oleh deployment biasa; worker `label-download-shopee` harus aktif.
- `LABEL_TIKTOK_REUSE_PACKAGE_IDS=false` mengembalikan batch preflight tanpa menghapus data atau mengganti queue backend.
- Jangan mengganti host/database/prefix Redis saat ada job, flush Redis, atau retry seluruh failed jobs sekaligus.

## Audit server read-only

Setelah image dengan command baru deploy, jalankan di host Kubernetes. Artisan dijalankan di container, bukan host. Tidak memanggil channel, meminta shipment, atau menjalankan simulasi beban.

```bash
kubectl --request-timeout=30s get nodes
kubectl --request-timeout=30s top nodes
kubectl -n cilupbah --request-timeout=30s top pods --containers
kubectl -n cilupbah --request-timeout=30s get deploy cilupbah-horizon-labels cilupbah-horizon-labels-awb cilupbah-horizon-order-intake cilupbah-horizon-stock -o json | jq '.items[] | {name: .metadata.name, replicas: .spec.replicas, strategy: .spec.strategy, grace: .spec.template.spec.terminationGracePeriodSeconds, containers: [.spec.template.spec.containers[] | {name, resources}]}'
for workload in cilupbah-horizon-labels cilupbah-horizon-labels-awb cilupbah-horizon-order-intake cilupbah-horizon-stock; do
  kubectl -n cilupbah --request-timeout=30s exec "deploy/$workload" -- php artisan channel:audit-label-capacity --json
done
kubectl -n cilupbah --request-timeout=30s exec deploy/cilupbah-app -- df -h /var/www/html/storage/app/print-spool
kubectl -n cilupbah --request-timeout=30s exec deploy/cilupbah-app -- php artisan shipping-labels:reconcile-cache --status
```

`configuration_checks_passed` berarti anggaran konfigurasi per pod dan timeout lolos, bukan load test. `review_required` menghasilkan exit code 1. `archive_uses_r2_endpoint` memeriksa hostname standar R2, bukan upload/kredensial; custom endpoint perlu pemeriksaan terpisah. Output tidak memuat token/password.

`remaining_budget_mb` bukan RAM bebas node. Bandingkan dengan limit pod aktual, replica/surge dan metrik runtime; jangan menaikkan worker hanya karena CPU sedang idle.

## Arsip per-order: pemulihan dan kapasitas

`--status` hanya membaca: `pending`, `pending_bytes`, `oldest_pending_at`, dan `with_errors`. Tidak memanggil API marketplace, meminta shipment, mengunggah, menghapus, atau dispatch job.

Tanpa `--status`, command melakukan pemulihan aktif: maksimal 100 intent per putaran secara default (opsi `--limit` dibatasi 500), serta membersihkan maksimal sejumlah itu file lokal yang sudah terverifikasi terarsip lebih dari 24 jam. Scheduler menjalankannya setiap menit. File pending/gagal tidak pernah menjadi target cleanup ini. Job gagal mendapat backoff; intent tetap ada setelah retry antrean habis. Redis mati saat dispatch tidak menghapus file ataupun intent.

Batas lokal: 32 MiB per artifact, cadangan ruang filesystem 512 MiB + dua kali ukuran file, dan write lock lokal bertahap. Lazada agregasi dibatasi 500 package, 16 MiB input/output, 1.000 halaman, serta waktu pemeriksaan dan memori terbatas. Batas tersebut adalah pengaman implementasi; melebihi batas menghasilkan kegagalan jelas, bukan pemotongan paket. Cadangan filesystem bukan quota PVC atau jaminan ruang untuk seluruh layanan lain.

Ukuran/checksum remote harus cocok sebelum artifact ditandai archived. Salinan lokal dipertahankan 24 jam setelah itu; arsip tidak dihapus oleh command ini. File turunan lama tanpa record outbox boleh dikonversi ulang; file sumber legacy tetap bisa dibaca dari storage lama. Pending file pada single-node masih bergantung pada ketahanan PVC/host; ini bukan replikasi lintas server.

Pantau backlog arsip dan ruang spool bersama antrean order/stok. Pekerjaan upload baru menambah beban I/O dan antrean maintenance, walaupun tidak menghalangi cetak melalui penantian upload. Satu worker maintenance bukan klaim kapasitas jutaan upload/hari. Jika usia/bytes pending terus naik, ukur throughput upload dan budget node sebelum menambah kapasitas; jangan menghapus pending file atau menaikkan worker tanpa pengukuran. Pemulihan massal setelah outage juga dibatasi, bukan sekaligus membanjiri Redis.

Rollback aplikasi sebelum arsip pending selesai dapat mengembalikan pembacaan ke storage lama dan kehilangan akses sementara ke label lokal baru. Pertahankan migrasi/PVC dan selesaikan arsip terlebih dahulu, atau gunakan rollback yang tetap mengerti local-first cache. Jangan menganggap downgrade kode berarti aman menghapus data baru.

## Uji penerimaan

Gunakan staging terisolasi, API palsu/HTTP stub dan data sintetis. Jangan load-test production atau marketplace. Harness flash-sale yang ada belum membuktikan ketiga channel dan PDF real secara lengkap.

Uji cold request, resi sudah ada/PDF belum diunduh, file tersimpan, campuran siap/pending, timeout/429, split package, pembatalan, klik berulang, serta concurrent order/stock. Periksa isi PDF dan jumlah halaman/package, bukan hanya response 202.

Catat p50/p95/p99 hingga label pertama siap dan seluruh batch siap; waktu antre, API, download, konversi, merge; CPU/RSS/restart pod, connection wait DB, Redis memory/latency, sisa spool dan umur antrean order/stock. Uji burst dan sustained load bertahap, bukan sekadar total harian.

Hentikan peningkatan beban jika antrean kritis terus menua, RSS terus tumbuh, disk menipis, timeout naik, atau OOM/restart. Sisakan headroom RAM node, termasuk saat rollout; besarnya ditetapkan berdasarkan hasil pengukuran, bukan angka concurrency arbitrer.

## Endpoint dan error

`POST /api/v1/sales/shipping-labels/bulk/{batch}/ready-snapshot`: tanpa body; auth + permission halaman yang ada + ownership. 202 mengembalikan `data.batch_id` dan `data.total`; buka preview batch tersebut. 403 akses, 409 belum siap/lock/file berubah, 410 kedaluwarsa, 422 batas salinan, 429 terlalu sering, 503 spool kurang. Tidak mencetak otomatis dan tidak memanggil kurir. 202 belum berarti PDF selesai.

Retry snapshot processing menggunakan batch yang sama dan menjadwalkan merge unique. Sumber tidak direset ketika snapshot gagal. Download tetap memakai endpoint PDF terautentikasi yang ada.

## Cakupan dokumentasi dan validasi

Panduan mencakup endpoint baru, fast path, background safety, limiter, audit command, rollout dan keterbatasan. Command audit diuji melalui Artisan test; sintaks shell diperiksa lokal. Command kubectl belum dieksekusi ke server pengguna. Tidak ada klaim latency/throughput production berdasarkan unit/feature test.

Hasil lokal 25 September 2026: 143 tes BE gabungan lolos (1.010 assertions), kemudian tes tambahan pencabutan akses gudang dan pengulangan suite TikTok/snapshot lolos (31 tes, termasuk 1 tes baru). Total 144 tes BE relevan telah lolos; 7 tes FE, TypeScript noEmit, ESLint file FE terkait dan git diff --check juga lolos. Tes TikTok memblokir request HTTP yang tidak di-stub. Ini bukan seluruh test suite aplikasi atau E2E browser/production.

Follow-up 26 September 2026: 143 tes BE terkait lulus bersama (1.021 assertions), mencakup outbox/cache/retention, kegagalan Redis/R2/checksum/disk pressure, agregasi Lazada 20+20+1 dan campuran URL/base64, retry bagian pending, seluruh halaman TikTok, serta regresi modal/antrean/prefetch. Pemeriksaan klasifikasi local rate limit Lazada juga memastikan stok tidak menganggapnya sebagai penolakan permanen. Pint file terkait, diff whitespace, dan sintaks shell dokumentasi diperiksa. Tidak ada perubahan FE baru pada follow-up ini, dan tidak ada push, deploy, uji beban server, maupun request nyata ke marketplace dari pengujian baru.
