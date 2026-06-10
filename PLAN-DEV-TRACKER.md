# Plan — Dev Tracker (Internal Project Tracking Route)

> **Tujuan:** Satu route khusus development untuk **tracking progres tiap endpoint/task Jubelio** (sumber: `TASK-BREAKDOWN-JUBELIO.md`). Bisa **ubah status** (✅ Done / 🔄 In Progress / ⬜ Belum) + **catatan (notes)** per item, tersimpan di DB, dengan **filter** & **progress bar**. Aktif di environment **lokal & staging**; diblokir (404) di production.
> **Disusun:** 2026-06-10 · **Stack:** Laravel 11/12 + PostgreSQL (`cilupbah`) · **Branch:** `27-product`

---

## 1. Ruang Lingkup

**In scope**
- Dashboard tabel **287 endpoint Jubelio** + **11 epik** (dari Lampiran A & §18 `TASK-BREAKDOWN-JUBELIO.md`).
- Ubah **status** & **notes** per item (inline, tersimpan ke DB).
- Filter: domain/tag, status, PIC, search; ringkasan progress per domain & per PIC.
- **Dev-only:** route diblokir (404) di production.
- **Sync** dari `dist (2).yaml` (idempotent): endpoint baru ter-insert, status/notes lama tetap terjaga.

**Out of scope (sementara)**
- Auth/login (cukup gating environment).
- Multi-user realtime, komentar berulir, attachment.
- Integrasi Jira/GitHub (bisa fase lanjut).

---

## 2. Arsitektur

```
routes/web.php
  └─ Route::prefix('dev/tracking')->middleware('dev.only')->group(...)
       GET  /dev/tracking              → halaman dashboard (Blade)
       GET  /dev/tracking/data         → JSON semua item + summary (untuk tabel)
       PATCH /dev/tracking/items/{id}  → update status & notes
       GET  /dev/tracking/export       → unduh CSV/Markdown snapshot

app/Http/Middleware/DevOnly.php        → izinkan env local & staging; abort(404) di production
app/Http/Controllers/Dev/TrackingController.php
app/Models/TrackingItem.php
database/migrations/xxxx_create_tracking_items_table.php
database/seeders/TrackingItemsSeeder.php → data 342 item DI-EMBED langsung (tanpa JSON), idempotent
resources/views/dev/tracking.blade.php → UI (Tailwind + Alpine via CDN, self-contained)
config/devtracker.php                  → allowed_envs + Basic Auth opsional
scripts/gen_tracking_seeder.py         → build-time: generate seeder dari dist (2).yaml (bukan runtime)
```

> **Pendekatan final:** data di-**embed di seeder** (bukan baca JSON saat runtime) dan **tanpa command khusus**. Cukup `php artisan db:seed` (auto via `DatabaseSeeder`, guarded local/staging). Setelah seed, semua dikelola via database.

**Kenapa di luar Modules/**: ini tooling internal, bukan domain bisnis. Ditaruh di `app/` + `routes/web.php` supaya **mudah di-strip** untuk production dan tidak mengotori modul domain.

---

## 3. Skema Database

Tabel **`tracking_items`** (PostgreSQL):

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `domain` | varchar | tag Jubelio (Sales, Inventory, …) atau `Epic`/`Omnichannel` |
| `method` | varchar(10) | GET/POST/PUT/PATCH/DELETE (null utk epik) |
| `endpoint` | varchar | path Jubelio (unik bersama method) |
| `function_id` | text | "untuk apa" (Bahasa Indonesia) |
| `cilupbah_impl` | text null | controller@method target/eksisting |
| `status` | varchar(12) | `done` / `in_progress` / `todo` / `blocked` |
| `baseline_status` | varchar(12) | status awal dari dokumen (untuk deteksi perubahan) |
| `pic` | varchar null | `Darriel` / `Rasyid` |
| `notes` | text null | catatan bebas (editable) |
| `priority` | varchar null | P0/P1/P2/P3 (dari epik terkait) |
| `source` | varchar | `jubelio` / `omnichannel` / `epic` |
| `updated_by` | varchar null | nama editor (input manual / dari config) |
| `timestamps` | | created_at, updated_at |

Index: unique (`method`,`endpoint`), index (`domain`), (`status`), (`pic`).

Enum status (label UI):
| value | label | emoji |
|---|---|---|
| `done` | Done | ✅ |
| `in_progress` | In Progress | 🔄 |
| `todo` | Belum Develop | ⬜ |
| `blocked` | Blocked | ⛔ |

---

## 4. Sumber Data & Sync (dari YAML)

Build-time: `scripts/gen_tracking_seeder.py` membaca `dist (2).yaml` dan **menghasilkan `database/seeders/TrackingItemsSeeder.php`** dengan **342 item ter-embed** (bukan JSON runtime). Dijalankan saat spec berubah saja.

**Seeding (idempotent, via `php artisan db:seed`):**
1. Loop array yang ter-embed di seeder.
2. `firstOrNew` by (`method`,`endpoint`):
   - **insert** item baru → status = baseline.
   - **existing** → perbarui `function_id`/`domain`/`baseline_status` saja; **JANGAN timpa** `status`/`notes`/`pic` yang sudah diedit user.
3. Auto-dipanggil dari `DatabaseSeeder` (hanya local & staging).

> Saat spec Jubelio berubah: jalankan `python3 scripts/gen_tracking_seeder.py` lalu `php artisan db:seed` — progres manual tetap aman. **Tidak ada command khusus & tidak ada file JSON.**

Data tambahan yang ikut di-seed:
- **11 epik** (E1–E11) dari §18 → `source=epic`.
- **44 task omnichannel** (4 channel × 11 fitur) dari §18d → `source=omnichannel`.

---

## 5. UI / UX (`/dev/tracking`)

**Header — ringkasan progress**
- Total: ✅ 111 · 🔄 45 · ⬜ 131 (live dari DB).
- Progress bar keseluruhan + per domain (Sales, Inventory, …).
- Toggle ringkasan **per PIC** (Darriel vs Rasyid).

**Toolbar — filter**
- Dropdown: Domain · Status · PIC · Source (jubelio/omnichannel/epic).
- Search box (cari di endpoint + function_id).
- Tombol: Export CSV/Markdown.

**Tabel utama** (kolom)
| # | Domain | Method | Endpoint | Fungsi (ID) | Status | PIC | Notes | Updated |

Interaksi:
- **Status** = dropdown inline → `PATCH` otomatis saat berubah (optimistic update + toast).
- **Notes** = klik sel → textarea inline → simpan on-blur via `PATCH`.
- **PIC** = dropdown inline (opsional ubah).
- Baris diwarnai per status (hijau/kuning/abu/merah).
- Sticky header + pagination/virtual scroll (287+ baris).

**Teknologi UI:** Blade tunggal + **Alpine.js & Tailwind via CDN** (tanpa perlu `vite build`) agar self-contained & gampang dihapus. Request pakai `fetch` + CSRF token.

---

## 6. Endpoint API (internal)

| Method | Path | Fungsi | Body |
|---|---|---|---|
| GET | `/dev/tracking/data` | semua item + summary (json) | `?domain=&status=&pic=&q=` |
| PATCH | `/dev/tracking/items/{id}` | update status/notes/pic | `{status?, notes?, pic?, updated_by?}` |
| GET | `/dev/tracking/export` | snapshot CSV / Markdown | `?format=csv\|md` |

Validasi PATCH: `status in [done,in_progress,todo,blocked]`, `notes` max 2000, `pic in [Darriel,Rasyid]`.

---

## 7. Keamanan (Dev-only)

Middleware **`DevOnly`**:
```php
$allowedEnvs = config('devtracker.allowed_envs', ['local', 'staging']);
if (! in_array(app()->environment(), $allowedEnvs, true) && ! config('devtracker.enabled')) {
    abort(404);
}
```
- **Aktif di `local` & `staging`** (sesuai kebutuhan). **404 di production** (`APP_ENV=production`).
- `config/devtracker.php`:
  ```php
  return [
      'allowed_envs' => explode(',', env('DEVTRACKER_ALLOWED_ENVS', 'local,staging')),
      'enabled'      => env('DEVTRACKER_ENABLED', false), // override paksa (mis. demo di env lain)
  ];
  ```
- Staging Cilupbah: pakai `docker-compose.staging.yml` / `start.staging.sh` — pastikan `APP_ENV=staging` agar route otomatis nyala.
- **Catatan:** karena bisa diakses di staging (URL publik internal), tambahkan **proteksi ringan** — minimal `auth:sanctum` atau Basic Auth (`DEVTRACKER_USER`/`DEVTRACKER_PASS`) supaya tidak terbuka bebas. Tidak ada data sensitif, tapi hindari edit tak sengaja oleh pihak lain.

---

## 8. Fase Implementasi & Estimasi

| Fase | Output | Est. |
|---|---|---|
| **F1. Data & DB** | migration `tracking_items`, model, generator seeder dari `dist (2).yaml`, seeder ter-embed (idempotent) | 0.5 hari |
| **F2. Route + gating** | `DevOnly` middleware, `config/devtracker.php`, route group, controller skeleton | 0.25 hari |
| **F3. Dashboard read-only** | Blade UI + `/data` endpoint + summary + filter + progress bar | 0.75 hari |
| **F4. Editable** | dropdown status + notes inline + `PATCH` + toast | 0.5 hari |
| **F5. Polish** | per-PIC summary, export CSV/MD, warna baris, pagination | 0.5 hari |
| **Total** | | **~2.5 hari** |

MVP (F1–F4) bisa jalan di **~2 hari**.

---

## 9. Struktur File (ringkas)

```
config/devtracker.php
routes/web.php                                   (+ grup dev/tracking)
app/Http/Middleware/DevOnly.php
app/Http/Controllers/Dev/TrackingController.php
app/Models/TrackingItem.php
database/migrations/2026_06_10_000000_create_tracking_items_table.php
database/seeders/TrackingItemsSeeder.php         (data 342 item ter-embed)
resources/views/dev/tracking.blade.php
scripts/gen_tracking_seeder.py                   (build-time generator)
```

Registrasi middleware alias di `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $m) {
    $m->alias(['dev.only' => \App\Http\Middleware\DevOnly::class]);
})
```

---

## 10. Acceptance Criteria

- [ ] `GET /dev/tracking` tampil di lokal; **404 di production**.
- [ ] Tabel memuat **287 endpoint + 11 epik + 44 omnichannel** dengan fungsi Bahasa Indonesia.
- [ ] Ubah status 1 item → tersimpan, bertahan setelah refresh.
- [ ] Tambah notes → tersimpan & tampil.
- [ ] Filter domain/status/PIC + search berfungsi.
- [ ] Progress bar & angka (✅/🔄/⬜) sinkron dengan isi DB.
- [ ] `php artisan db:seed` ulang tidak menimpa status/notes yang sudah diedit.
- [ ] Export CSV/MD menghasilkan snapshot.

---

## 11. Pengembangan Lanjutan (opsional)
- Tombol **"Sync balik ke Markdown"** — regenerasi tabel status di `TASK-BREAKDOWN-JUBELIO.md` dari DB.
- Riwayat perubahan status (audit log).
- Burndown chart per minggu (progress vs waktu).
- Integrasi GitHub: link endpoint ↔ PR/branch.
- Assign granular (bukan hanya 2 PIC).
```
