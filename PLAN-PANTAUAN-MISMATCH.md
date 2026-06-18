# PLAN — Pantauan "Tidak Cocok" (Atribut, Harga, SKU)

> Status: **Planning** · Dibuat: 2026-06-18 · Patokan arsitektur: **Jubelio** (MDM + reconciliation + schema validation)
>
> Tujuan: membangun lens pantauan **Atribut Tidak Cocok**, **Harga Tidak Cocok**, dan **SKU Tidak Cocok** dengan **performa terbaik**, **data akurat**, dan **terintegrasi TikTok + Lazada**. (Shopee ditunda.)

---

## 1. Latar Belakang & Masalah

Endpoint `GET /api/app/products/pantauan?lens=atribut` mengembalikan data **kosong** walau ada produk yang jelas bermasalah atribut (mis. *ASUS Zenbook 14 OLED Series* gagal upload TikTok: `"The sale attribute name or attribute ID should not be empty."`).

**Akar masalah:** lens `atribut` saat ini memakai logika yang **salah konsep** — ia mendeteksi *drift `channel_attributes` antar-toko* (`count(distinct channel_attributes::text) > 1`), bukan *ketidaksesuaian skema atribut marketplace*. Model drift antar-toko **tidak punya padanan di Jubelio** dan praktis selalu kosong di lingkungan single-store/sandbox.

Logika "Atribut Tidak Cocok" versi Jubelio (validasi skema kategori marketplace: atribut wajib kosong / format nilai tidak sesuai dropdown) **sudah terbangun** di codebase sebagai `ChannelListingValidator::validate()` — tetapi **belum tersambung** ke lens pantauan.

### File terkait (kondisi sekarang)

| Peran | File | Catatan |
|---|---|---|
| Lens query (atribut/harga/sku) | `Modules/Product/app/Repositories/ProductPantauanRepository.php` | `applyLens()` `:59-117` — atribut `:91-99` **salah model** |
| Service pantauan | `Modules/Product/app/Services/ProductPantauanService.php` | tipis, delegasi ke repo |
| Resource | `Modules/Product/app/Http/Resources/ProductPantauanResource.php` | belum ada field daftar issue |
| Controller | `Modules/Product/app/Http/Controllers/ProductPantauanController.php` | validasi `lens` `:21-26` |
| **Mesin validasi atribut** | `Modules/Channel/app/Services/ChannelListingValidator.php` | `validate()` `:16-120` — **sumber kebenaran** |
| Penerjemah skema → internal | `Modules/Product/app/Services/CategoryAttributeSyncService.php` | `materializeAllMapped()` |
| Ingest skema Lazada | `Modules/Channel/app/Services/LazadaProductService.php` | `syncCategoryAttributes()` `:165-260` |
| Ingest skema TikTok | — | **belum ada** (gap) |

---

## 2. Keputusan yang Sudah Diambil

1. **Scope channel: TikTok + Lazada.** Shopee **ditunda** (belum ada `ShopeeProductService`/client; integrasi dari nol = scope terpisah).
2. **Granularity: per (produk × channel).** Status dihitung per produk untuk tiap channel (tiktok/lazada), bukan per-toko. Mismatch level varian/toko diagregasi naik ke produk × channel.
3. **Cakupan: tiga lens sekaligus** — atribut, harga, SKU — dibangun di atas **satu** mekanisme materialisasi terpadu.
4. **Arsitektur: materialized status** (precompute) demi performa + akurasi, bukan validasi saat request.

---

## 3. Arsitektur Sasaran (Jubelio-style MDM)

```
                 ┌─────────────────── Integration Layer (ingest) ───────────────────┐
   Lazada API ──▶│ syncCategoryAttributes() ─▶ channel_categories / channel_attributes
   TikTok API ──▶│ [BARU] syncCategoryAttributes() TikTok ─────────────/ channel_attribute_options
                 └──────────────────────────────────────────────────────────────────┘
                                            │
                                            ▼ (schema tersedia)
   Reconcile baca-balik harga/sku ──▶ product_variant_channel_mappings (synced_price, channel_seller_sku)
   Reconcile baca-balik atribut   ──▶ product_channel_mappings (channel_attributes)
                                            │
              ┌─────────────────────────────┼─────────────────────────────┐
              ▼ event-driven                 ▼ cron rekonsiliasi berkala
        (produk/atribut/harga save,    (tangkap drift, full sweep)
         schema sync, mapping change)
                                            │
                                            ▼
                 ┌──────────── Validation/Reconcile Engine ────────────┐
                 │ Atribut → ChannelListingValidator::validate()       │
                 │ Harga   → bandingkan synced_price vs harga efektif  │
                 │ SKU     → bandingkan channel_seller_sku vs master   │
                 └──────────────────────────────────────────────────────┘
                                            │  upsert
                                            ▼
        ┌──────────────── product_channel_validation (BARU) ───────────────┐
        │ (product_id, channel_id) × {atribut|harga|sku} status + issues   │
        └───────────────────────────────────────────────────────────────────┘
                                            │  WHERE status='mismatch' (indexed)
                                            ▼
                  pantauan?lens=atribut|harga|sku  ── cepat, terpaginasi
```

Prinsip: **lens membaca status precomputed**, tidak pernah menjalankan validator saat request. Status di-refresh oleh event + cron (sesuai "Periodic Data Reconciliation" Jubelio).

---

## 4. Desain Tabel Materialized

Tabel baru: **`product_channel_validation`** (grain: produk × channel).

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | uuid (v7) | PK |
| `product_id` | uuid | FK → products |
| `channel_id` | uuid | FK → channels (tiktok/lazada) |
| `attribute_status` | enum `ok\|mismatch\|n/a` | status lens atribut |
| `attribute_issues` | json | daftar issue: `[{code,label,message}]` dari validator |
| `price_status` | enum `ok\|mismatch\|n/a` | status lens harga |
| `price_issues` | json | varian + harga master vs channel |
| `sku_status` | enum `ok\|mismatch\|n/a` | status lens sku |
| `sku_issues` | json | varian + sku master vs channel |
| `checked_at` | timestamp | kapan terakhir dihitung |
| `created_at/updated_at` | timestamp | |

Index:
- `UNIQUE (product_id, channel_id)`
- `INDEX (channel_id, attribute_status)`
- `INDEX (channel_id, price_status)`
- `INDEX (channel_id, sku_status)`

> Catatan grain: harga & SKU secara teknis per (varian × toko). Karena keputusan granularity = produk × channel, status di-agregasi: `mismatch` bila **ada minimal satu** varian/toko di channel itu yang menyimpang. `*_issues` menyimpan rincian varian/toko untuk ditampilkan di detail. (Opsi naik ke grain per-toko bisa menyusul tanpa mengubah kontrak lens.)

> `n/a` dipakai bila channel belum bisa dinilai (mis. skema TikTok belum di-ingest) — dibedakan dari `ok` agar tidak memberi false-negative.

---

## 5. Engine per Tipe Mismatch

### 5a. Atribut Tidak Cocok
- **Sumber kebenaran:** `ChannelListingValidator::validate(Product, channelCode)`.
- **Issue code yang dihitung** (kategori atribut saja, sesuai keputusan scope):
  `variation_attribute_missing`, `attribute_unmapped`, `attribute_missing`, `value_unmapped`.
- **Issue kategori** (`category_unmapped`, `category_deprecated`) **dikecualikan** dari lens atribut (akan jadi sinyal terpisah bila dibutuhkan nanti).
- `attribute_status = mismatch` bila ada ≥1 issue dari daftar di atas.
- **Catatan akurasi:** untuk TikTok, cek `attribute_missing`/`value_unmapped`/`attribute_unmapped` baru akurat **setelah** skema TikTok di-ingest (lihat §6). Sebelum itu, hanya `variation_attribute_missing` yang andal; sisanya → `attribute_status = n/a` untuk TikTok agar tidak menyesatkan.

### 5b. Harga Tidak Cocok
- **Sumber:** `product_variant_channel_mappings.synced_price` (harga aktual di marketplace, hasil reconcile) vs **harga efektif master** = `coalesce(override_price, product_variants.sell_price)`.
- **Logika:** `price_status = mismatch` bila ada varian dengan `synced_price IS NOT NULL AND synced_price <> harga_efektif`.
- Mempertahankan semantik lens `harga` sekarang (`ProductPantauanRepository.php:73-82`), tapi dipindah ke precompute & dipecah per channel.

### 5c. SKU Tidak Cocok
- **Sumber:** `product_variant_channel_mappings.channel_seller_sku` (SKU di marketplace) vs `product_variants.sku` (Master SKU).
- **Logika:** `sku_status = mismatch` bila ada varian dengan `channel_seller_sku IS NOT NULL AND channel_seller_sku <> product_variants.sku`.
- Mempertahankan semantik lens `sku` sekarang (`ProductPantauanRepository.php:84-89`), dipindah ke precompute & dipecah per channel.

> Harga & SKU **sudah** punya data untuk TikTok + Lazada (keduanya menulis `synced_price` & `channel_seller_sku` saat reconcile), jadi keduanya akurat untuk kedua channel sejak fase awal.

---

## 6. Gap Integrasi: Ingest Skema Atribut TikTok

Agar lens **atribut** akurat untuk TikTok (bukan hanya `variation_attribute_missing`), perlu meng-ingest skema kategori TikTok ke `channel_categories` / `channel_attributes` / `channel_attribute_options`, setara `LazadaProductService::syncCategoryAttributes()`.

- **Tambah:** `TikTokProductService::syncCategoryAttributes()` (atau service ingest terpisah) yang menembak API kategori & atribut TikTok, lalu mengisi tabel skema.
- Setelah itu jalankan `CategoryAttributeSyncService::materializeAllMapped()` agar skema TikTok diterjemahkan ke atribut internal.
- Sampai ini selesai, status atribut TikTok untuk cek berbasis-skema = `n/a`.

---

## 7. Pemicu Refresh Status

1. **Event-driven (incremental):**
   - Produk/varian disimpan (nama, kategori, spesifikasi, harga, sku).
   - `product_specifications` / `variant_options` berubah.
   - Mapping kategori channel berubah; skema channel di-sync ulang.
   - Reconcile baca-balik marketplace selesai (harga/sku/atribut ter-update).
   - → re-validasi produk tersebut untuk channel terkait, upsert ke `product_channel_validation`.
2. **Cron rekonsiliasi berkala (full sweep):**
   - Job terjadwal (mis. tiap jam/harian) memvalidasi ulang produk aktif × channel aktif → menangkap drift yang lolos event.
   - Pola mengikuti `materializeAllMapped()` (batch, idempotent).

Implementasi: `Modules/Product/app/Jobs/RecomputeProductChannelValidationJob.php` (per produk) + command/scheduler untuk full sweep.

---

## 8. Perubahan Lens & API

- **`ProductPantauanRepository::applyLens()`** — ganti 3 cabang:
  - `atribut` → `whereHas('channelValidations', status atribut = mismatch)` (join ke tabel baru).
  - `harga` → baca `price_status = mismatch` (hapus `whereRaw` lama).
  - `sku` → baca `sku_status = mismatch` (hapus subquery lama).
  - `gagal_upload` & `belum_upload` tetap (tidak terdampak).
- **Filter channel:** lens menerima `?channel=tiktok|lazada` (filter sudah ada) untuk membatasi mismatch per channel.
- **Resource** — tambah `mismatch_channels` + `issues` (dari `*_issues`) agar UI bisa tampilkan rincian "kenapa tidak cocok" per channel.
- **Validasi controller** — `lens` tetap `in:belum_upload,harga,sku,atribut,gagal_upload`.

---

## 9. Fase Implementasi

**Fase 0 — Fondasi (performa + 3 lens, channel yang sudah siap)**
1. Migration `product_channel_validation` + model + relasi `Product::channelValidations()`.
2. Engine recompute (atribut via `ChannelListingValidator`, harga & sku via reconcile compare) → service `ProductChannelValidationService`.
3. Job per-produk + command full-sweep + scheduler.
4. Rewire 3 lens ke tabel materialized; update Resource.
5. Backfill awal (jalankan full-sweep sekali).
- **Hasil:** ASUS muncul di lens atribut (via `variation_attribute_missing`); harga & sku akurat untuk TikTok+Lazada; lens cepat.

**Fase 1 — Akurasi atribut TikTok penuh**
6. `TikTokProductService::syncCategoryAttributes()` (ingest skema TikTok).
7. Materialize + recompute; status atribut TikTok naik dari `n/a` → penuh.

**Fase 2 — (Ditunda) Shopee**
8. Integrasi Shopee (auth/client/product/category sync) lalu daftarkan ke pipeline yang sama. **Di luar scope sekarang.**

---

## 10. Pengujian

- **Unit:** `ProductChannelValidationService` per tipe (atribut/harga/sku) — kasus mismatch & ok, termasuk agregasi varian.
- **Feature:** perluas `ProductPantauanTest.php`:
  - lens atribut menangkap multi-varian tanpa `variant_options` (kasus ASUS).
  - lens atribut menangkap atribut wajib kosong / nilai tak terpetakan (Lazada, skema terisi).
  - lens harga & sku konsisten dengan perilaku lama, tapi terpecah per channel.
  - status `n/a` untuk atribut TikTok sebelum ingest skema.
- **Integrasi:** event refresh memperbarui `product_channel_validation`; cron full-sweep idempotent.

---

## 11. Risiko & Catatan

- **Konsistensi status:** status materialized bisa basi bila event terlewat → cron full-sweep sebagai jaring pengaman (wajib).
- **Biaya recompute:** validator ~5–8 query/produk/channel; full-sweep harus batch + dijadwalkan di jam sepi.
- **Backfill:** sweep awal pada katalog besar perlu chunking.
- **`n/a` vs `ok`:** jangan menandai `ok` saat skema belum ada (false-negative) — gunakan `n/a`.
- **Grain harga/sku:** agregasi produk×channel menyembunyikan toko mana yang menyimpang; rincian disimpan di `*_issues`. Naik ke grain per-toko bila dibutuhkan, tanpa ubah kontrak lens.
- **Lens `atribut` lama (drift antar-toko) dihapus** — konsep ini tidak ada di Jubelio; bila tetap diinginkan, jadikan sinyal bernama lain (mis. `atribut_drift`) agar tak rancu.

---

## 12. Definition of Done (Fase 0)

- [x] Tabel `product_channel_validations` + model + index.
- [x] `ProductChannelValidationService` (atribut/harga/sku) + job + full-sweep command + scheduler.
- [x] 3 lens membaca status materialized; Resource menampilkan `mismatches` (issues) per channel.
- [x] Event-driven refresh tersambung di `SyncProductToChannelJob` + cron rekonsiliasi hourly.
- [x] Test feature hijau (ProductPantauanTest 12/12; regresi terkait 48/48).
- [ ] **Deploy:** jalankan `php artisan migrate` lalu `php artisan products:recompute-validation` (backfill) di env target.

### Catatan deploy Fase 0
Migration sudah diverifikasi lewat `RefreshDatabase` di test, tetapi **belum** dijalankan di DB nyata.
Untuk mengaktifkan fitur di staging/produksi:
1. `php artisan migrate` (buat tabel `product_channel_validations`).
2. `php artisan products:recompute-validation` (backfill status; tamb. `--queue` untuk lewat queue).
Setelah itu ASUS akan tampil di `pantauan?lens=atribut` via `variation_attribute_missing`.
