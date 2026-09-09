# Runbook Finance Sync

## Deployment order

1. Apply Redis finance dan Redis Horizon, lalu tunggu keduanya `Ready`.
2. Set secret aplikasi:
   `QUEUE_CHANNEL_FINANCE_CONNECTION=redis-finance`, `REDIS_FINANCE_CONNECTION=finance`,
   `REDIS_FINANCE_HOST=redis-finance`, `HORIZON_REDIS_CONNECTION=horizon`, dan
   `REDIS_HORIZON_HOST=redis-horizon`.
3. Deploy image aplikasi.
4. Jalankan `php artisan migrate --force` dari satu pod aplikasi.
5. Restart Horizon bertahap dan pastikan supervisor `channel-finance` memakai `redis-finance`.
6. Jalankan health check sebelum mengaktifkan backfill:
   `php artisan orders:finance-queue-health --json`.

## Operasional normal

Scheduler menjalankan `orders:dispatch-due-finance` setiap menit. Command ini mengambil state
yang jatuh tempo dalam batch kecil dan berhenti secara alami saat backpressure aktif. Jangan
menjalankan `orders:sync-finance --force` massal di jam sibuk.

## Diagnosis

- `waiting`: channel belum menyediakan settlement, item belum siap, atau backpressure aktif.
- `failed`: error transient dijadwalkan ulang; lihat `last_error` dan log dengan `order_id`.
- `dead_letter`: error permanen atau retry habis; rekonsiliasi order dan API terlebih dahulu.
- `succeeded`: sync terakhir selesai. Order yang belum settled tetap akan memiliki state `waiting`
  agar tidak dipanggil pada setiap webhook.

## Checklist alert

Periksa queue depth, reserved/delayed, memory ratio, state `processing` yang stale, dan log
`SyncOrderFinanceJob unexpected failure`. Ambang default producer: queue 5.000 item atau memory
Redis 80%. Ambang monitoring umum: 70% warning, 80% error, 90% critical.

## Recovery

1. Hentikan producer bulk/backfill, bukan webhook/order real-time.
2. Turunkan finance worker ke 1 proses.
3. Periksa error asli dan dead-letter sebelum retry.
4. Perbaiki penyebab channel/database, lalu jalankan dispatch due batch kecil.
5. Validasi tidak ada duplicate state dan queue kembali turun.

`FLUSHALL`, `FLUSHDB`, dan penghapusan list queue manual dilarang sebagai prosedur recovery.
