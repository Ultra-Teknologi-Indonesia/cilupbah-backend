# PLAN — Pindahkan PostgreSQL ke Luar Docker (Native di Host)

Status: DRAFT (belum dieksekusi)
Konteks: data masih dummy → boleh **mulai dari kosong** (tanpa dump/restore).
Keputusan: pakai **cluster PostgreSQL 14 yang sudah ada di host** (port 5432).
Stack aktif: `docker-compose.staging.yml` (app `cilupbah-staging`, horizon, db, redis).

---

## 1. Tujuan & Hasil Akhir

- PostgreSQL **tidak lagi** jalan di Docker; app + Horizon (di Docker) konek ke Postgres **native host**.
- DB fresh: jalankan **migrate + seeder**, lalu **fetch/sync kategori** Lazada, TikTok, Shopee.
- Redis tetap di Docker (tidak diubah).
- Container `db` + volume `cilupbah_staging_pgdata` dihapus setelah verifikasi.

## 2. Kondisi Awal (snapshot)

| Item | Nilai |
|---|---|
| Container DB | `cilupbah-staging-db` (`postgres:15-alpine`), volume `cilupbah_staging_pgdata` |
| App `.env` | `DB_HOST=db`, `DB_PORT=5432`, `DB_DATABASE=cilupbah`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres` |
| Host PG | cluster **14 main**, online di `0.0.0.0:5432` (⚠️ terbuka publik) |
| Docker bridge | `docker0` = `172.17.0.1` (host-gateway untuk container) |

---

## 3. Langkah Eksekusi

### Fase 1 — Hardening & konfigurasi PostgreSQL host (v14)

> Cluster v14 sekarang dengar di `0.0.0.0:5432` (terbuka internet). Ini harus dibatasi.

1. **`/etc/postgresql/14/main/postgresql.conf`**
   ```conf
   listen_addresses = 'localhost,172.17.0.1'   # localhost + bridge Docker, BUKAN '*'
   password_encryption = scram-sha-256
   ```
2. **`/etc/postgresql/14/main/pg_hba.conf`** — izinkan subnet bridge Docker:
   ```conf
   # Docker bridge containers
   host    cilupbah    cilupbah_user    172.17.0.0/16    scram-sha-256
   ```
3. **Firewall** — pastikan 5432 tertutup dari publik:
   ```bash
   ufw deny 5432/tcp        # atau biarkan default-deny; cukup pastikan tidak ada allow 5432
   ```
4. Reload:
   ```bash
   systemctl reload postgresql
   ```

### Fase 2 — Buat role & database

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE cilupbah_user LOGIN PASSWORD '<PASSWORD_KUAT_BARU>';
CREATE DATABASE cilupbah OWNER cilupbah_user;
GRANT ALL PRIVILEGES ON DATABASE cilupbah TO cilupbah_user;
SQL
```
> Pakai password baru yang kuat, **jangan** `postgres/postgres`.

### Fase 3 — Arahkan aplikasi ke Postgres host

1. **`.env`** (`/var/www/cilupbah/.env`):
   ```env
   DB_CONNECTION=pgsql
   DB_HOST=host.docker.internal
   DB_PORT=5432
   DB_DATABASE=cilupbah
   DB_USERNAME=cilupbah_user
   DB_PASSWORD=<PASSWORD_KUAT_BARU>
   ```
2. **`docker-compose.staging.yml`** — pada service `app` **dan** `horizon`:
   - Tambah:
     ```yaml
     extra_hosts:
       - "host.docker.internal:host-gateway"
     ```
   - Hapus `db` dari `depends_on` (sisakan `redis`).
   - Hapus seluruh blok service `db:` dan volume `staging_pgdata` (boleh ditunda ke Fase 6).

### Fase 4 — Restart app & inisialisasi DB

```bash
cd /var/www/cilupbah
docker compose -f docker-compose.staging.yml up -d --no-deps app horizon

# config di-cache oleh start.staging.sh → clear dulu
docker exec cilupbah-staging php artisan config:clear
docker exec cilupbah-staging php artisan config:cache

# uji koneksi
docker exec cilupbah-staging php artisan db:show

# skema + data dasar (fresh)
docker exec cilupbah-staging php artisan migrate --force
docker exec cilupbah-staging php artisan db:seed --force
```

Seeder yang berjalan (dari `DatabaseSeeder`): Role, Region, **Channel**, Warehouse, Finance, Tax, Product Category, Brand, Inbound, Inventory.

### Fase 5 — Connect toko & sync kategori channel

> Kategori marketplace **di-fetch dari API** → wajib ada toko ter-connect (OAuth/token) dulu.

1. Hubungkan toko via UI/OAuth (atau `lazada:connect-manual` untuk Lazada).
2. Sync kategori via **API endpoint** (semua `POST`, butuh Bearer token Sanctum):

   | Channel | Sync kategori | Sync atribut kategori |
   |---|---|---|
   | TikTok | `POST /api/v1/tiktok/sync/categories` | `POST /api/v1/tiktok/sync/category-attributes` |
   | Lazada | `POST /api/v1/lazada/sync/categories` | `POST /api/v1/lazada/sync/category-attributes` |
   | Shopee | `POST /api/v1/shopee/sync/categories` | `POST /api/v1/shopee/sync/category-attributes` |

   Controller: `TikTokSyncApiController` / `LazadaSyncApiController` / `ShopeeSyncApiController` (method `syncCategories` / `syncCategoryAttributes`).

   ```bash
   # contoh (ganti $TOKEN dengan token Sanctum, $BASE host app):
   curl -X POST $BASE/api/v1/lazada/sync/categories -H "Authorization: Bearer $TOKEN"
   curl -X POST $BASE/api/v1/lazada/sync/category-attributes -H "Authorization: Bearer $TOKEN"
   # ulangi untuk tiktok & shopee
   ```
   > Tetap butuh toko ter-connect (fetch dari API marketplace). Alternatif CLI Lazada masih ada: `lazada:sync-categories`, `lazada:sync-category-attributes`, lalu `categories:materialize-attributes`.

### Fase 6 — Pembersihan (setelah verifikasi stabil)

```bash
docker stop cilupbah-staging-db && docker rm cilupbah-staging-db
docker volume rm cilupbah_staging_pgdata
```
- Hapus service `db` + volume `staging_pgdata` dari `docker-compose.staging.yml`, dan dari `docker-compose.override.yml` / `docker-compose.local.yml` bila ingin konsisten.
- **Update CI/CD** (`.github/workflows/ci-cd.yml`): step `migrate`/`db:seed` via `docker exec cilupbah-staging ...` tetap valid. Pastikan tidak ada langkah yang bergantung service `db` Docker.

---

## 4. Verifikasi

- [ ] `php artisan db:show` menunjuk host `host.docker.internal`, koneksi OK.
- [ ] `migrate` & `db:seed` sukses; tabel + data dasar (channel, region, warehouse, dll) ada.
- [ ] App `:8001` & Horizon jalan normal, job ter-proses.
- [ ] Port 5432 **tidak** dapat diakses dari internet (`nmap`/test eksternal).
- [ ] Kategori Lazada/TikTok/Shopee tersinkron setelah toko connect.

## 5. Rollback

Selama **Fase 6 belum dijalankan**, container `db` + volume masih ada:
1. Kembalikan `.env` → `DB_HOST=db`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`.
2. Kembalikan `depends_on: db` & blok service `db` di compose.
3. `docker compose -f docker-compose.staging.yml up -d` + `config:cache`.

## 6. Risiko & Catatan

- **Keamanan**: cluster v14 saat ini terbuka publik di 5432 — Fase 1 wajib, jangan dilewati.
- **Alternatif `DB_HOST`**: bila `host.docker.internal` bermasalah, pakai IP bridge `172.17.0.1` langsung.
- **Versi**: app sebelumnya di PG15, sekarang PG14. Untuk skema Laravel normal tidak masalah; bila ada fitur khusus PG15, pertimbangkan upgrade cluster nanti.
- **Backup**: aktifkan jadwal `pg_dump` host (cron) karena data tak lagi di volume Docker — sebelumnya tidak ada backup terjadwal.
