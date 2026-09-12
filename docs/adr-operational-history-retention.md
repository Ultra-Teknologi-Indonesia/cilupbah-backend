# ADR: Retensi riwayat operasional bertahap

## Keputusan

Data yang tidak lagi dipakai oleh alur aktif dibersihkan lewat satu Kubernetes
CronJob yang terisolasi dari pod aplikasi dan scheduler:

- `failed_jobs`: 14 hari;
- `notifications`: 90 hari;
- `channel_webhook_inbox` berstatus `PROCESSED` atau `SKIPPED`: 30 hari;
- webhook `FAILED` dan `RECEIVED`: tidak dibersihkan otomatis.

CronJob berjalan setiap 15 menit dan setiap proses menghapus maksimal 10.000
baris per sumber data, dalam batch 500 baris. Indeks dibuat secara
`CONCURRENTLY`, sehingga pembuatannya tidak menahan transaksi aplikasi.

## Alasan

Tabel `failed_jobs`, `notifications`, dan webhook inbox tumbuh tanpa retensi.
Menghapus semuanya dalam satu query akan menghasilkan lock dan lonjakan I/O
yang berisiko mengganggu transaksi. Pekerjaan pendek tiap 15 menit memberi
ruang bagi PostgreSQL untuk melakukan vacuum dan memakai kembali ruang yang
telah kosong.

## Dampak

Riwayat export/import tetap dipertahankan sesuai kebijakan sebelumnya. Audit
webhook gagal tetap tersedia untuk investigasi dan replay. Ruang file database
tidak langsung mengecil setelah delete; PostgreSQL akan memakai ulang ruang itu
secara otomatis. Pengembalian ruang ke filesystem perlu maintenance terpisah
(`pg_repack` atau `VACUUM FULL`) dan tidak dilakukan saat sistem aktif.
