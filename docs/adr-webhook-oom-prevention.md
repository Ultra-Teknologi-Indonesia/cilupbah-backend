# ADR: Pencegahan OOM Redis untuk Webhook Marketplace

Status: Accepted  
Tanggal: 2026-09-20

## Konteks

Redis `redis-horizon` menolak ribuan dispatch webhook ketika mencapai
`maxmemory`. Webhook telah diterima dan dicatat pada database, tetapi sebagian
kemudian berhenti setelah batas retry replay tercapai. Hal ini membuat status
pembatalan Marketplace terlambat sampai ke WMS.

## Keputusan

- `redis-horizon` memakai `maxmemory` 3 GB dalam pod berlimit 4 GiB. Sisa ruang
  dipertahankan untuk RSS dan penulisan AOF Redis.
- Ingress webhook memeriksa kedalaman antrean dan memori Redis sebelum dispatch.
  Pada 70% kapasitas atau ketika Redis tidak dapat diperiksa, webhook tetap aman
  di `channel_webhook_inbox` dan dispatch ditunda dengan backoff.
- Error infrastruktur—Redis penuh/tidak tersedia, kapasitas ditunda, dan cache
  idempotensi tidak tersedia—tidak diubah menjadi dead-letter permanen setelah
  lima percobaan. Replay terbatas laju akan melanjutkan setelah Redis sehat.
- Pembatalan TikTok tipe 11 masuk antrean `channel-cancellation`; dua worker
  khusus pada profil critical tidak berbagi worker dengan fulfillment.
- Pemeriksaan kesehatan queue berjalan setiap menit. Alarm log dipicu pada 70%,
  80%, dan 90% penggunaan Redis serta untuk inbox webhook yang tertinggal.

## Konsekuensi

- Positif: webhook tidak hilang ketika Redis menolak command; database menjadi
  penyangga yang durable dan pemulihan tidak membanjiri Redis.
- Positif: pembatalan tidak menunggu fulfillment, sehingga risiko pesanan batal
  tetap dipick atau diserahkan ke kurir berkurang.
- Negatif: saat Redis tidak sehat, pembaruan Marketplace memang tertunda, tetapi
  tertahan dengan aman dan dapat dipulihkan, bukan gagal permanen.
- Operasional: kapasitas hanya dinaikkan bila pemakaian puncak tujuh hari
  mencapai 70% dari 3 GB atau terdapat penolakan Redis baru. Target berikutnya
  adalah pod 8 GiB dengan `maxmemory` 6 GB, setelah kapasitas node diverifikasi.

## Alternatif yang Dipertimbangkan

- Menggunakan kebijakan eviction Redis: ditolak karena dapat menghapus data
  antrean secara diam-diam.
- Menaikkan langsung ke 8 GB: ditunda karena pemakaian live saat audit masih
  sekitar 745 MB; kenaikan bertahap lebih aman untuk node yang juga menjalankan
  aplikasi dan worker lain.
- Menghentikan retry setelah lima kali untuk semua error: ditolak karena error
  infrastruktur bersifat sementara dan tidak boleh meninggalkan pembatalan.
