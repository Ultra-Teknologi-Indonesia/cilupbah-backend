# Hardening jalur pesanan, stok, dan label — 24 September 2026

## Status dan batas klaim

Perubahan ini memperbaiki bug yang direproduksi melalui tes PostgreSQL lokal.
Ini **bukan sertifikasi kapasitas 1 juta pesanan**, hasil load test server, atau
jaminan selesai hitungan detik ketika marketplace lambat/rate-limited.
Satu juta pesanan per hari berarti rata-rata 11,57 pesanan/detik; satu juta
dalam 10 menit berarti 1.667 pesanan/detik. Satu pesanan dapat menghasilkan
beberapa webhook, pembaruan stok, panggilan API, dan job.

## Perubahan yang dibatasi pada keandalan eksekusi

- Pencarian listing/SKU numerik tetap menggunakan binding teks PostgreSQL.
  PHP sebelumnya mengubah key array numerik menjadi integer, menyebabkan
  pencatatan pesanan gagal dengan `character varying = integer`.
- Refresh pesanan Shopee melalui jalur kritis tidak mengambil escrow kedua
  kali secara inline. Finance tetap mengikuti eligibility dan jalur sinkronisasi
  finance yang sudah dijadwalkan oleh upsert pesanan. Penarikan manual tidak berubah.
- Pengiriman stok sukses mereset penghitung kegagalan, termasuk saat versi stok
  yang lebih baru menunggu. Pengiriman gagal tetap dibatasi; recovery tidak
  mengabaikan `next_attempt_at`. Pembuatan outbox pertama diserialisasi per mapping.
- Permintaan label identik dari pengguna yang sama menggunakan batch aktif yang
  sama. Urutan pesanan, opsi dokumen, dan lingkup akses gudang masuk fingerprint.
  Batch selesai tidak digunakan sebagai batch aktif baru; mekanisme cache label
  yang sudah ada tetap berlaku.
- Exception sementara mengembalikan klaim item ke pending. Redelivery dapat
  mengambil item downloading setelah lock pemilik lama dilepas/kedaluwarsa;
  status ready/waiting/transforming tidak direset oleh penanganan exception.
- Pendaftaran penunggu label mendahului dispatch persiapan agar callback cepat
  tidak hilang. TikTok/Lazada dikerjakan worker unduh per item, bukan worker merge.
- Persiapan bulk Shopee dapat dipisahkan ke worker unduh, per toko/per 50 order.
  Endpoint bulk tetap digunakan. Aktivasi bertahap dijelaskan di bawah.
- Grace period pod mencakup timeout job. Pemantauan antrean membaca seluruh
  profil, bukan hanya profil pod tempat perintah dijalankan.
- Pipeline tidak lagi menghapus Deployment scheduler aktif atau mematikan pool
  background yang sudah terisolasi pada setiap deploy. Jalur legacy tetap
  dikuras saat migrasi dari master generik lama.

Aturan status bisnis, pembayaran, pembatalan, rumus stok, cutoff intake,
pause sinkronisasi, dan otorisasi gudang tidak diubah. Saat sinkronisasi sengaja
dipause, perilaku penerimaan webhook lama tetap berlaku: jangan gunakan pause
sebagai cara menguji ketahanan tanpa memahami kebijakan tersebut.

## Deployment aman

1. Gunakan image yang sama untuk app, scheduler, dan seluruh worker. Pipeline
   production menjalankan migrasi dari image baru melalui Job **sebelum** rollout.
   Kegagalan migrasi membatalkan rollout. Pipeline staging juga migrate sebelum
   mengganti app/worker. Migrasi menambah kolom nullable `request_key` dan indeks
   concurrent; schema tetap kompatibel dengan kode lama.
2. Untuk rollout pertama, biarkan `LABEL_ASYNC_SHOPEE_PREPARATION=false` pada
   semua producer/worker. Ini mencegah worker versi lama menerima kelas job baru.
3. Setelah semua worker label memakai image baru dan tidak ada pod versi lama,
   aktifkan `LABEL_ASYNC_SHOPEE_PREPARATION=true` lewat konfigurasi deployment,
   lalu refresh config/rollout normal. Tanpa langkah ini, bulk Shopee masih
   menggunakan jalur persiapan lama yang kompatibel; perbaikan lain tetap aktif.
4. `QUEUE_LABEL_DOWNLOAD_CONNECTION=redis-label-download` menggunakan datastore
   yang sama dengan `redis-long`, tetapi reservasi 240 detik, bukan 2160 detik
   untuk impor. Ini lebih panjang dari timeout worker 180 detik dan lebih pendek
   dari tenggat retry item 15 menit. Override lama `redis-long` harus diperbarui
   untuk memperoleh perbaikan ini. Reservasi yang dibuat worker lama tetap
   mengikuti waktu lamanya sampai dikonsumsi kembali.
5. Jangan mengubah host, database, prefix Redis, atau menjalankan flush/queue clear.
   Nama `horizon` dimiliki Laravel. Konfigurasi legacy queue kini diarahkan ke
   `queue_legacy`, menyalin endpoint dan prefix runtime Horizon sebelumnya.
   Ini kompatibilitas, **bukan migrasi pemisahan Redis fisik**. Jika Redis lama
   sebenarnya terpusat, pemisahan fisik membutuhkan prosedur drain/replay tersendiri.
6. Rollback image yang belum mengenal job bulk Shopee baru hanya setelah flag
   dimatikan dan job baru ready/delayed/reserved selesai. Jangan rollback schema
   kolom nullable selama image baru masih berjalan. Jangan menghapus job untuk
   memaksa antrean terlihat kosong.

## Verifikasi dan gate flash sale

Gunakan `channel:monitor-queue-health --json` serta
`channel:monitor-stock-outbox --json` dari pod dengan konfigurasi production
yang sama. Perintah tersedia dan dites, tetapi belum dijalankan ke server dari
task ini. Snapshot sehat saat idle tidak membuktikan throughput saat burst.

Sebelum menyatakan siap, jalankan uji staging terisolasi dengan distribusi toko,
SKU panas, jumlah item per order, dan ukuran bulk PDF yang realistis:

- Traffic bertingkat dan lonjakan bersamaan pada webhook, stok, AWB, dan label.
- Webhook duplikat/terbalik, perubahan stok saat push berlangsung, klik ulang
  label, HTTP 429/timeout channel, serta worker mati setelah klaim.
- Cocokkan event diterima dengan inbox/pesanan sampai tuntas. Pastikan tidak
  ada regresi status atau pengurangan stok ganda dan nilai stok akhir benar.
- Ukur p95/p99 waktu inbox→pesanan, perubahan stok→konfirmasi channel,
  dan klik label→PDF siap; bedakan waktu antre internal dari waktu marketplace.
- Ukur laju kedatangan dibanding penyelesaian, umur backlog, retry/failed,
  RAM puncak/RSS, OOM, database lock, PgBouncer waiting, CPU, disk/AOF dan Redis.
- Setelah burst berhenti, backlog harus terkuras pada target yang disepakati;
  bukan sekadar dipindah ke delayed, failed, atau inbox deferred.

Jangan meningkatkan concurrency melewati budget memori, koneksi DB, dan kuota
API per toko. Reservasi internal yang benar mengurangi risiko oversell, tetapi
sinkronisasi lintas marketplace tidak memberikan jaminan zero-oversell ketika
API channel terlambat. Node tunggal tetap merupakan titik kegagalan tunggal.

Tes lokal mencakup service/job, PostgreSQL, kontrak bulk label, webhook,
side-effect guard, routing Horizon, dan validasi manifest. Tes tersebut tidak
mensimulasikan jaringan marketplace nyata atau membuktikan kapasitas production.

Hasil verifikasi implementasi ini: **227 tes, 1.616 assertion lulus** pada
PostgreSQL `cilupbah_testing`; Pint pada file PHP perubahan lulus; YAML manifest
dan workflow berhasil diparse; script SSH workflow lolos pemeriksaan `bash -n`.
Tidak ada load test server, deploy, perubahan antrean production, atau mutasi
pesanan/stok production yang dilakukan sebagai bagian verifikasi ini.
