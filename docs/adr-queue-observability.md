# ADR: Observability SLO untuk Antrean Produksi

## Status

Accepted

## Tanggal

2026-09-19

## Konteks

Isolasi queue dan backpressure mencegah backlog baru memperburuk kondisi Redis,
tetapi operator tetap perlu mengetahui kapan antrean sudah tua, webhook tertahan,
atau failed job meningkat. Jumlah queue saja tidak cukup: queue yang kecil tetap
bermasalah bila satu job tertua sudah menunggu terlalu lama.

## Keputusan

Command `channel:monitor-queue-health` diperluas menjadi watchdog SLO yang:

- mengukur ready, delayed, dan reserved untuk queue yang dilayani Horizon;
- membaca umur job ready tertua bila payload menyediakan `pushedAt`;
- mengawasi penggunaan Redis, RSS, fragmentasi, dan eviction;
- menghitung webhook `RECEIVED` yang stale;
- menghitung failed job pada jendela waktu operasional;
- menulis warning/critical event sebagai log terstruktur;
- menyediakan `--json` untuk integrasi monitoring eksternal.

Batas production default:

- umur queue: warning 5 menit, critical 15 menit;
- webhook stale: warning mulai 100 event;
- failed jobs: warning 10 dan critical 50 dalam 15 menit.

Watchdog tidak me-restart worker, tidak menghapus job, dan tidak mengubah status
order/webhook. Tindakan pemulihan tetap eksplisit setelah operator memeriksa
penyebabnya.

## Alternatif yang Dipertimbangkan

- **Restart otomatis saat alarm** — ditolak karena dapat memperbesar retry storm
  dan menyamarkan akar masalah.
- **Polling database untuk setiap payload queue** — ditolak karena menambah beban
  database dan tidak memberi informasi lebih baik dari metadata Redis.
- **Monitoring hanya dari dashboard Horizon** — ditolak karena tidak mencakup
  webhook inbox dan failed jobs aplikasi.

## Konsekuensi

Positif:

- backlog dan retry storm terlihat sebelum menjadi OOM atau 5xx massal;
- operator dapat mengonsumsi snapshot JSON tanpa membaca log mentah;
- watchdog bersifat read-only terhadap pekerjaan bisnis.

Negatif:

- inspeksi `lindex` dan query agregat failed jobs menambah beban kecil pada
  command scheduler;
- payload queue lama tanpa `pushedAt` tidak dapat diberi umur dan tetap hanya
  dipantau berdasarkan depth.

