# Rencana Implementasi — Gap Prioritas MENENGAH

> Tanggal: 2026-06-13
> Lingkup: G-3 `sales-return-setting`, G-4 catalog-by-group, G-5 transfer berarah.
> Prinsip wajib: **bisnis logic & flow benar**, **tidak ada error 500**, **patuh `agents.md`** (Controller tipis → Service → Repository, Resource, Spatie QB untuk listing, pagination 10, validasi → 422, auth:sanctum).
> Status: **✅ SELESAI DIIMPLEMENTASI (2026-06-13)** — G-5, G-4, G-3 (termasuk jurnal retur). Keputusan: `POST /inventory/catalog/` create = dilewati (alias eksisting); jurnal retur = disertakan. Tes: `TransferDirectionTest`, `CatalogReadTest`, `SalesReturnSettingTest`. Suite penuh: 645 passed, 0 gagal.

---

## Ringkasan & Urutan yang Disarankan

| Gap | Risiko | Sifat | Urutan |
|---|---|---|---|
| **G-5** transfer berarah (`in`/`out`/`all-transit`) | 🟢 Rendah | Reuse Spatie QB yang sudah ada | 1 (paling cepat & aman) |
| **G-4** catalog-by-group (read views + alias upload) | 🟢–🟡 | Read view atas data master/merge; 1 alias | 2 |
| **G-3** `sales-return-setting` + wiring ke flow retur | 🟡 | Settings baru + integrasi ke `SalesReturnService` | 3 (paling berdampak ke bisnis flow) |

Catatan: 2 sub-item butuh **keputusan** sebelum dikerjakan (lihat bagian "Keputusan Diperlukan").

---

## G-5 — Transfer berarah: `/inventory/transfers/{in,out,all-transit}`

### Fakta sekarang
- `inventory_transfers`: `source_location_id`, `destination_location_id`, `status` ∈ {DRAFT, IN_TRANSIT, RECEIVED, CANCELLED}.
- Sudah ada: `/transfers` (semua), `/transfers/transit` (IN_TRANSIT), `/transfers/out-finished` (RECEIVED). `InventoryTransferRepository` sudah Spatie QB dengan `allowedFilters(status, source_location_id, destination_location_id)`.
- **Tidak ada** scoping lokasi per-user. Maka arah harus ditentukan via parameter `location_id`.

### Desain (semantik bisnis)
| Endpoint | Filter | Arti |
|---|---|---|
| `GET /inventory/transfers/out?location_id=X` | `source_location_id=X` & `status=IN_TRANSIT` | Barang keluar dari gudang X, masih jalan |
| `GET /inventory/transfers/in?location_id=X` | `destination_location_id=X` & `status=IN_TRANSIT` | Barang masuk ke gudang X, menunggu di-receive |
| `GET /inventory/transfers/all-transit` | `status=IN_TRANSIT` (tanpa lokasi) | Semua transit, dua arah |

> `out-finished` (RECEIVED) sudah meng-cover sisi "selesai". `out`/`in` fokus ke yang masih IN_TRANSIT (actionable untuk WMS).

### Implementasi (agents.md)
- **Repository**: tambah method `getDirectionalPaginated(?int $locationId, string $direction)` yang memakai query builder yang sama (Spatie filters tetap aktif) + base constraint `where(source/destination_location_id, X)` + default `status=IN_TRANSIT`. Reuse `paginate(request('per_page',10))->appends(...)`.
- **Controller**: 3 method tipis (`transfersIn`, `transfersOut`, `allTransit`) → service → repo. Pakai Resource yang sudah dipakai list transfer (kalau belum ada Resource, buat `InventoryTransferResource`).
- **Routes**: daftarkan **sebelum** `/transfers/{id}` agar tidak tertangkap sebagai `{id}` (urutan penting — sama seperti `transit`/`out-finished` yang sudah diletakkan sebelum `{id}`).

### No-500 & validasi
- `location_id` untuk `in`/`out`: `required|integer|exists:locations,id` → tanpa/loc salah = **422**, bukan 500.
- `all-transit`: tanpa parameter wajib.
- `per_page`/`page`: `nullable|integer|min:1|max:500`.

### Tes
- `in`/`out` mengembalikan hanya transfer arah & lokasi yang sesuai; `all-transit` semua IN_TRANSIT; `location_id` invalid → 422; auth → 401; `per_page` dihormati.

---

## G-4 — Catalog-by-group & listing reads

### Fakta sekarang
- "group" = `ProductMerge.master_name` (berbasis **nama**, bukan UUID). "master" = `Product.status='master'`. "listing" = `ProductChannelDraft` → `ProductChannelMapping`.
- Sudah ada: `/products/master`, `/products/master/{id}`, `/products/merge/catalog`, `/inventory/catalog/listing`, `/inventory/catalog/set-master`, dan **upload listing** (`ProductChannelDraftService::bulkUpload`).

### Desain per endpoint
| Endpoint | Sifat | Sumber data | Catatan |
|---|---|---|---|
| `GET /inventory/catalog/{group_id}` | Read baru | `ProductMerge` where `master_name`=group_id → produk + varian + stok | `group_id` = **string** (master_name), bukan UUID |
| `GET /inventory/catalog/for-listing/{id}` | Read baru | `Product{id}` + varian + harga + stok + status channel mapping | `{id}` = Product UUID |
| `GET /inventory/items/group/{id}` | Read baru (≈ for-listing) | Sama seperti for-listing by Product UUID | Kemungkinan **alias** for-listing; konfirmasi |
| `POST /inventory/catalog/upload` | **Alias** | `ProductChannelDraftService::bulkUpload` (sudah ada) | Hanya route alias |
| `POST /inventory/catalog/` (create) | ⚠️ Write baru | Buat Product+varian | **Keputusan** — lihat di bawah |

### Implementasi (agents.md)
- **Repository** (`ProductCatalogRepository` baru atau perluas `MasterFeedRepository`):
  - `getItemsByGroup(string $masterName)` — Spatie QB `Product` join `product_merges` where master_name, `with(variants, media, channelMappings)`, `allowedSearch('name','sku')`, paginate 10.
  - `getForListing(string $productId)` — Eloquent biasa (single resource by id; agents.md §3 pengecualian) + eager-load varian + inventori (sum on_hand/available per varian) + channel mapping.
- **Resource**: reuse `MasterItemResource`/`ProductResource` bila cocok; bila perlu bentuk khusus listing, buat `CatalogListingResource` (produk + varian{sku,price,stock} + channel_status).
- **Controller**: method tipis; route alias upload → controller `ProductChannelDraft` yang sudah ada.

### No-500 & validasi
- `{group_id}` string bebas → bila tak ada produk, kembalikan **paginator kosong 200** (bukan 404/500). Tidak pakai `whereUuid` (memang nama).
- `for-listing/{id}` & `items/group/{id}`: `->whereUuid('id')` → non-UUID = **404**. Bila Product tak ada → 404 via guard service.
- Listing read pakai Spatie QB → aman terhadap `filter`/`sort`/`search`/`per_page`.

### Tes
- group berisi 2 produk ter-merge → keduanya muncul; group tak dikenal → data kosong 200; for-listing UUID valid → produk+varian+stok; non-UUID → 404; upload alias mengantre job (Queue::fake).

---

## G-3 — `GET/POST /systemsetting/sales-return-setting` + wiring ke flow retur

### Fakta sekarang (flow retur)
- `sales_returns` (PENDING→ACCEPTED/REJECTED→COMPLETED) + `sales_return_items` (`condition` ∈ GOOD/DAMAGE, **hardcoded** di validasi).
- `accept()` → `InboundService::receiveFromSalesReturn()` membuat Inbound GRN; **lokasi restock = `return.location_id`** (hardcoded). `reject()` → stok tak berubah. Restock nyata terjadi saat inbound di-receive (movement source `sales_return`).
- Settlement & refund terpisah; `refund_method` **free-text**. **Belum ada jurnal** untuk retur.

### Desain settings (singleton bertipe — bukan key/value)
Karena setting heterogen (boolean, id, array), pakai **tabel singleton bertipe** (lebih aman & validasinya eksplisit daripada key/value generik):

`sales_return_settings` (satu baris):
| Kolom | Tipe | Default | Fungsi bisnis |
|---|---|---|---|
| `auto_accept` | bool | `false` | Retur baru langsung ACCEPTED (buat Inbound) tanpa langkah manual |
| `default_restock_location_id` | FK locations (nullable) | `null` | Lokasi restock default; fallback ke `return.location_id` |
| `allowed_conditions` | jsonb | `["GOOD","DAMAGE"]` | Kondisi item yang diizinkan saat create |
| `allowed_refund_methods` | jsonb | `["cash","transfer","store_credit"]` | Metode refund yang valid |
| `return_validity_days` | int (nullable) | `null` | Tolak retur bila order lebih tua dari N hari (null = tanpa batas) |

### Wiring ke bisnis flow (INI bagian "matang")
1. **Create retur** (`StoreSalesReturnRequest`): `condition` divalidasi `Rule::in(settings.allowed_conditions)`; bila `return_validity_days` di-set & order melewati batas → tolak (422).
2. **`accept()`**: lokasi restock = `settings.default_restock_location_id ?? $return->location_id`.
3. **`auto_accept`**: bila true, setelah create langsung jalankan `accept()` (dalam transaksi yang sama, idempoten — guard status agar tak dobel).
4. **Refund** (`StoreRefundRequest`): `refund_method` divalidasi `Rule::in(settings.allowed_refund_methods)`.

### Implementasi (agents.md)
- Migration singleton + model `SalesReturnSetting`.
- `SalesReturnSettingRepository` (`current()` = `firstOrCreate` default, `update(array)`), `SalesReturnSettingService` (`get()` mengembalikan setting efektif + accessor bertipe: `autoAccept():bool`, `restockLocationId():?int`, `allowedConditions():array`, dst.).
- `SaveSalesReturnSettingRequest` (semua field **opsional/partial**, validasi per field: `auto_accept boolean`, `default_restock_location_id nullable exists:locations,id`, `allowed_conditions array` + `*.string`, `allowed_refund_methods array`+`*.string`, `return_validity_days nullable integer min:1`).
- `SalesReturnSettingResource`, `SalesReturnSettingController` (index/store), route GET/POST `/systemsetting/sales-return-setting` (auth:sanctum).
- Inject `SalesReturnSettingService` ke `SalesReturnService` untuk wiring di atas.

### No-500 & flow aman
- GET selalu balas default (singleton `firstOrCreate`) → tak pernah kosong/500.
- Semua wiring punya **fallback aman** (lokasi → return.location_id; kondisi/metode → default array). Setting rusak/ kosong tak menggagalkan retur.
- `auto_accept` membungkus `accept()` dalam try/catch agar kegagalan inbound tak menggagalkan pembuatan retur (fail-open seperti pola jurnal otomatis), atau—lebih ketat—dalam transaksi; **keputusan** (lihat bawah).

### Tes
- GET default; POST set `auto_accept=true` & `default_restock_location_id` → retur baru langsung ACCEPTED + Inbound pakai lokasi setting; `condition`/`refund_method` di luar allowlist → 422; validasi field salah → 422; auth → 401.

---

## Keputusan Diperlukan (sebelum implementasi)

1. **G-4 `POST /inventory/catalog/` (create product)** — kemungkinan **tumpang tindih** dengan endpoint pembuatan produk yang sudah ada (`POST /inventory/items` / product create). Pilihan: **(a)** jadikan alias ke create yang ada, **(b)** lewati (sudah tercakup), **(c)** bangun create katalog khusus. **Rekomendasi: (b) lewati / (a) alias** — verifikasi dulu endpoint create eksisting. (Sisanya G-4 tetap dikerjakan.)
2. **G-4 `GET /inventory/items/group/{id}`** — apakah benar-benar identik `for-listing/{id}`? Rekomendasi: jadikan **alias** read yang sama bila datanya sama.
3. **G-3 `auto_accept` saat gagal Inbound** — fail-open (retur tetap PENDING bila inbound gagal, log) **atau** atomik (rollback). Rekomendasi: **fail-open** (retur tetap dibuat; auto-accept best-effort) demi konsistensi "domain tak pernah 500".
4. **G-3 jurnal retur** (Dr retur penjualan / pengurangan piutang, dll.) — **DI LUAR** rencana ini (butuh keputusan akuntansi + key mapping baru). Diusulkan sebagai item terpisah memakai pola `account_mapping` (tambah key `sales_return`/`refund`). Konfirmasi bila ingin dimasukkan.

---

## Estimasi & Dependensi
- G-5: ~0.5 hari. Tanpa migrasi. Reuse repo.
- G-4: ~0.5–1 hari. Mungkin 1 Resource baru. Tanpa migrasi (kecuali create dibangun).
- G-3: ~1 hari. 1 migrasi (singleton) + wiring + tes.
- Semua: jalankan `php artisan test` penuh setelah tiap gap; target 0 gagal, tidak ada 500 di jalur domain.
