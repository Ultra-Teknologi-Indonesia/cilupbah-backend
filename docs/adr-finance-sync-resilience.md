# ADR: Finance Sync Resilience dan Idempotensi Durable

Status: Accepted
Tanggal: 2026-09-09

## Masalah

Sinkronisasi settlement dipicu oleh webhook, polling, backfill, dan resync. Lock `ShouldBeUnique`
berbasis Redis hanya sementara; ketika job gagal, lock dapat dilepas dan event berikutnya membuat
job yang sama lagi. Akibatnya queue finance dan histori Horizon membesar, sementara akar error
tertutup oleh `MaxAttemptsExceededException`.

## Keputusan

- Setiap order marketplace memiliki satu baris `finance_sync_states` sebagai state machine durable:
  `pending`, `queued`, `processing`, `waiting`, `failed`, `succeeded`, atau `dead_letter`.
- Dispatch finance selalu melalui `FinanceSyncControlService`. Update atomik mencegah dua producer
  mengantrikan order yang sama pada waktu yang sama.
- Respons settlement kosong atau finance belum settled menjadi `waiting`, bukan failed storm.
- Error permanen masuk `finance_sync_dead_letters`; transient error memakai retry terbatas dan
  backoff. Log menyimpan order, channel, stage, attempt, exception, dan HTTP status bila tersedia.
- Finance menggunakan queue/Redis khusus. Metadata Horizon menggunakan Redis terpisah, sehingga
  histori failed/completed tidak mengambil ruang queue real-time.
- Producer menerapkan backpressure berdasarkan queue depth dan rasio memory Redis. State yang
  tertunda diambil kembali oleh command terjadwal dengan batch kecil.

## Batasan keselamatan

Migrasi database harus selesai sebelum worker baru diaktifkan. Selama rollout, service memiliki
fallback legacy dispatch jika tabel kontrol belum tersedia, sehingga order baru tidak diam-diam
hilang. Jangan memindahkan atau menghapus payload queue secara manual tanpa backup dan rekonsiliasi.

## Kriteria penerimaan

- Duplicate webhook/backfill menghasilkan maksimal satu job finance aktif per order.
- Status `waiting` memiliki `next_attempt_at` dan tidak diputar terus-menerus.
- Error terminal dapat dicari di dead-letter table dengan konteks channel dan order.
- Queue finance, queue real-time, dan Horizon tidak berbagi Redis pada production.
- Health check gagal/alert ketika queue atau memory melewati threshold.
