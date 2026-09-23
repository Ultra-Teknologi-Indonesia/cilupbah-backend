# Runbook: PgBouncer Connection Pooling

## Tujuan

PgBouncer membatasi jumlah koneksi nyata dari App, Horizon, scheduler, dan
worker ke PostgreSQL. Aplikasi dapat memiliki banyak client connection, tetapi
PostgreSQL tetap diberi budget backend yang terukur.

## Arsitektur

```text
App/Horizon/Scheduler/Workers
             |
             v
  cilupbah-pgbouncer:6432
             |
             v
  PostgreSQL asli:5432
```

Manifest production membuat dua replica PgBouncer. Masing-masing dibatasi
hingga 35 koneksi backend; total target aplikasi adalah 70 koneksi, di bawah
`max_connections=100` yang saat ini terdeteksi di PostgreSQL.

## Prasyarat server

Sebelum mengubah `cilupbah-env`, simpan endpoint PostgreSQL asli ke Secret
terpisah `cilupbah-pgbouncer-upstream`. Jangan masukkan password ke Git.

Secret tersebut harus memiliki key:

```text
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASSWORD
```

PostgreSQL juga harus menerima koneksi dari alamat/node tempat pod PgBouncer
berjalan. Jika PostgreSQL memiliki firewall atau `pg_hba.conf`, whitelist
network tersebut.

## Aktivasi

1. Apply manifest `k8s/production/01-pgbouncer.yaml`.
2. Tunggu kedua pod PgBouncer Ready.
3. Tes koneksi melalui Service `cilupbah-pgbouncer:6432`.
4. Ubah `DB_HOST` pada Secret aplikasi menjadi `cilupbah-pgbouncer` dan
   `DB_PORT` menjadi `6432`.
5. Restart rolling App, Horizon, scheduler, dan worker agar Laravel membaca
   `config:cache` baru.
6. Verifikasi `pg_stat_activity`, queue health, packing, order intake, stok,
   request AWB, dan download label.

## Rollback

Kembalikan hanya `DB_HOST` dan `DB_PORT` pada `cilupbah-env` ke endpoint
PostgreSQL asli, lalu restart deployment aplikasi dan worker. PgBouncer dapat
tetap hidup selama rollback.

## Catatan kompatibilitas

Runtime Laravel menggunakan `DB_EMULATE_PREPARES=true` dan PgBouncer memakai
`pool_mode=transaction`. Transaksi Laravel tetap aman; fitur PostgreSQL yang
bergantung pada session seperti temporary table, `LISTEN/NOTIFY`, atau setting
session khusus harus memakai koneksi langsung/session pooling.
