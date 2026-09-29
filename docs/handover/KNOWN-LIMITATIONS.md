# Known Limitations dan Status Audit

Dokumen ini menjelaskan batasan yang harus dibaca sebelum menggunakan sistem
atau menyatakan Go-Live.

## Kondisi resource dan rollout

- Cluster hanya menggunakan satu node k3s; kapasitas node menjadi batas utama
  ketika terjadi burst traffic atau rollout dengan `maxSurge`.
- Audit 28 September 2026 menunjukkan penggunaan sekitar 10% CPU dan 41%
  memori pada saat pengambilan snapshot. Ini bukan kapasitas maksimum.
- Saat audit, masih ada pod lama berstatus `Terminating` dan beberapa worker
  restart satu kali. Penyebabnya belum dikonfirmasi dari audit ringan.
- Deployment Redis khusus (`cache`, `finance`, `horizon`, `long`) terlihat `0/0`.
  Dampaknya harus dicocokkan dengan mapping queue aplikasi sebelum dinyatakan
  sehat.

## Data operasional saat snapshot

Angka berikut berasal dari estimasi cepat PostgreSQL, bukan audit rekonsiliasi:

| Tabel | Perkiraan baris |
|---|---:|
| `channel_catalog_sku_indexes` | 32.085 |
| `channel_webhook_inbox` | 638 |
| `failed_jobs` | 191 |
| `product_sync_logs` | 3.601 |
| `webhook_deliveries` | 0 |
| `webhook_subscriptions` | 0 |

`failed_jobs` tidak boleh dianggap kosong atau dihapus hanya untuk membuat
dashboard terlihat bersih. Setiap kelompok error perlu memiliki keputusan
retry, perbaikan koneksi, atau penerimaan sebagai data historis.

## Keterbatasan marketplace

- Webhook hanya memberi notifikasi; penyimpanan, validasi, dan pemrosesan tetap
  bergantung pada kapasitas internal.
- AWB dan label pada Shopee, TikTok Shop, dan Lazada dapat asynchronous serta
  terkena rate limit. Batch API tidak menjamin semua dokumen langsung tersedia.
- Target waktu harus dibedakan antara menerima webhook, menyimpan order,
  menerima AWB dari channel, mengunduh dokumen, dan merge PDF.

## Status penerimaan

Keterbatasan di atas dapat diterima sebagai handover as-is bila client menyetujui
secara tertulis. Jika targetnya Go-Live, item tersebut menjadi pekerjaan
verifikasi/perbaikan terpisah dan tidak boleh dianggap lulus hanya berdasarkan
audit snapshot.
