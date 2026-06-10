# Planning — Tab Naikkan Produk (Raise Product)

## 1. Tujuan

"Naikkan Produk → Promosikan produk agar lebih mudah ditemukan." Fitur untuk **menaikkan/boost** produk di marketplace (relist/bump agar tampil segar & mudah ditemukan), menggantikan Jubelio `core-api/inventory/v2/raise-product/`.

Struktur respons mengikuti contoh Jubelio:
- **List** `GET /raise-product/` → 1 baris = konfigurasi naik-produk **per toko** (`raiseproduct_id`, `store_id`, `channel_id`, `store_name`, `product_active`).
- **Detail** `GET /raise-product/{store}` → header toko + `details[]` (daftar produk yang dinaikkan, lengkap dengan varian, SKU, thumbnail, status & jadwal raise).

> Catatan dokumentasi: `raise-product` **tidak terdokumentasi** di `dist (2).yaml`/`dist (3).yaml` (keduanya API WMS lama). Spec murni dari 2 contoh JSON yang diberikan user.

## 2. Aturan bisnis (WAJIB)

- **One-to-one toko:** 1 toko hanya boleh ada di **1** data naikkan (`raise_products.channel_shop_id` UNIQUE). Menambah toko yang sudah punya konfigurasi → tolak / arahkan ke yang ada.
- **One-to-one produk:** 1 produk hanya boleh dinaikkan di **1** detail (tidak boleh ada di dua konfigurasi). Diikat ke listing channel (`ProductChannelMapping`) yang memang sudah unik per toko → `raise_product_details.product_channel_mapping_id` UNIQUE.
- Menambah data naikkan = pilih **channel → toko → produk** (produk harus milik toko tsb).

## 3. Pemetaan JSON ↔ skema kita

### Header (`data[]` / detail root)
| Field Jubelio | Sumber kita | Catatan |
|---|---|---|
| `raiseproduct_id` (int) | `raise_products.id` (UUID) | id internal |
| `store_id` (int) | `channel_shop_id` (UUID) | Jubelio int → kita UUID |
| `channel_id` (int) | `channelShop.channel_id` (UUID) | + `channel_code`/`channel_name` |
| `store_name` | `channelShop.shop_name` | |
| `channel_name` (detail) | `channelShop.channel.name` | mis. "SHOPEE" |
| `product_active` ("5") | `count(details where is_active)` | jumlah produk aktif dinaikkan |

### Detail (`details[]`)
| Field | Sumber | Catatan |
|---|---|---|
| `item_group_name` | `mapping.product.name` | nama produk |
| `channel_group_id` | `mapping.external_product_id` | id listing marketplace |
| `channel_url` | `mapping.channel_url` ?: `ChannelUrlBuilder` | reuse builder |
| `item_id[]` | `product.variants[].id` | UUID varian (Jubelio int) |
| `item_codes[]` | `product.variants[].sku` | SKU varian |
| `thumbnails[]` | media per varian → fallback media produk | sejajar `item_id`/`item_codes` |
| `is_active` | `raise_product_details.is_active` | sedang dinaikkan/tidak |
| `is_repeatable` | `raise_product_details.is_repeatable` | boleh diulang otomatis |
| `is_success` | `raise_product_details.is_success` | hasil eksekusi terakhir |
| `start_time` / `end_time` | kolom datetime | jendela jadwal raise |
| `reason` | `raise_product_details.reason` | pesan hasil ("Produk sudah diterapkan di shopee") |
| `raiseproduct_detail_id` | `raise_product_details.id` | |
| `raiseproduct_id` | `raise_product_details.raise_product_id` | FK header |

## 4. Skema DB (2 tabel baru, migrasi UUID)

### `raise_products` (header per toko)
- `id` uuid PK (`HasUuid7`)
- `channel_shop_id` uuid **UNIQUE** → FK channel_shops (one-to-one toko)
- `created_by` uuid nullable → users
- `is_active` boolean default true
- timestamps
- Index: `channel_shop_id`

### `raise_product_details` (produk yang dinaikkan)
- `id` uuid PK
- `raise_product_id` uuid → FK raise_products (cascade delete)
- `product_channel_mapping_id` uuid **UNIQUE** → FK product_channel_mappings (one-to-one produk)
- `is_active` boolean default true
- `is_repeatable` boolean default false
- `is_success` boolean nullable
- `start_time` timestamptz nullable
- `end_time` timestamptz nullable
- `reason` string nullable
- timestamps
- Index: `raise_product_id`, `product_channel_mapping_id`

> `item_group_name`, `channel_url`, `channel_group_id`, `item_id[]`, `item_codes[]`, `thumbnails[]` **tidak disimpan** — diturunkan saat serialisasi dari relasi `mapping.product.variants/media` (selalu konsisten, tak duplikat data). `product_active` dihitung, tidak disimpan.

## 5. Endpoint

Prefix `v1` (Product module), diletakkan sebelum `apiResource('products')`.

| Method | Path | Fungsi |
|---|---|---|
| GET | `/v1/raise-products` | List per toko (Spatie: search/filter/sort/paginate) |
| GET | `/v1/raise-products/{id}` | Detail header + `details[]` (paginasi details) |
| POST | `/v1/raise-products` | Buat data naikkan (pilih `shop_id`) — tolak bila toko sudah ada |
| POST | `/v1/raise-products/{id}/products` | Tambah produk ke data naikkan (pilih `product_channel_mapping_id`) — tolak bila produk sudah dinaikkan / bukan milik toko |
| DELETE | `/v1/raise-products/{id}/products/{detailId}` | Lepas produk dari data naikkan |
| POST | `/v1/raise-products/{id}/raise` | **Eksekusi naik** (async) untuk produk terpilih/seluruhnya |
| PATCH | `/v1/raise-products/{id}/products/{detailId}` | Toggle `is_active`/`is_repeatable` |
| DELETE | `/v1/raise-products/{id}` | Hapus data naikkan (+ cascade detail) |

### Query (Spatie / agents.md)
- List: `?search=` (FTS nama toko via leftJoin channel_shops/shop_name), `filter[channel]`, `filter[shop_id]`, `filter[is_active]`, `sort=-created_at`, `per_page` default 10.
- Detail `details[]`: `?search=` (nama produk), `filter[is_active]`, `filter[is_success]`, paginasi 10.

## 6. Eksekusi "naik" (async)

Pola sama seperti Download (Bagian B) yang sudah ada:
- `POST /{id}/raise` → buat/queue job `RaiseProductJob`, set `start_time = now`, kembalikan **202**.
- Job memanggil adapter channel untuk relist/bump produk; pada selesai set `is_success`, `reason`, `end_time`. Bila `is_repeatable`, dijadwalkan ulang (command terjadwal harian, opsional fase lanjut).
- **Gap adapter:** contoh data = SHOPEE, tapi adapter push/relist saat ini **hanya TikTok**. Untuk fase awal: `assertSupported()` (tiktok saja) atau simpan status `pending`/`reason="adapter channel belum tersedia"` untuk channel non-tiktok. Eksekusi nyata Shopee = fase terpisah (butuh Shopee adapter).

## 7. File yang dibuat/diubah

### Baru
- Migrasi: `..._create_raise_products_table`, `..._create_raise_product_details_table`
- Model: `RaiseProduct` (hasMany `details`, belongsTo `channelShop`, `creator`; accessor `product_active`), `RaiseProductDetail` (belongsTo `raiseProduct`, `channelMapping`)
- Resource: `RaiseProductResource` (header/list), `RaiseProductDetailResource` (details[] shape Jubelio)
- Repository: `RaiseProductRepository` (paginate list, find header, paginate details — Spatie + leftJoin channel_shops + allowedSearch)
- Service: `RaiseProductService` (create header w/ one-to-one guard, addProduct w/ guard, removeProduct, toggle, raise dispatch, delete)
- Controller: `RaiseProductController`
- Job: `RaiseProductJob` (Channel module, async eksekusi)
- Test: `RaiseProductTest` (list/detail/struktur), `RaiseProductManageTest` (one-to-one guards, add/remove/raise/toggle)

### Diubah
- `Modules/Product/routes/api.php` — daftarkan route + `use ...RaiseProductController`.

## 8. Bentuk respons (ringkas)

`GET /v1/raise-products`:
```jsonc
{ "data": [ { "raiseproduct_id": "uuid", "store_id": "uuid", "channel_id": "uuid",
  "channel_code": "shopee", "store_name": "Cilupbah ID Mall", "product_active": 5 } ],
  "meta": { "per_page": 10, "total": 5 } }
```
`GET /v1/raise-products/{id}`:
```jsonc
{ "data": { "raiseproduct_id": "uuid", "store_id": "uuid", "channel_id": "uuid",
  "store_name": "Cilupbah ID Mall", "channel_name": "Shopee",
  "details": [ { "item_group_name": "...", "channel_group_id": "25190513574",
    "channel_url": "...", "item_id": ["uuid", ...], "item_codes": ["LSM-...", ...],
    "thumbnails": ["...", ...], "is_active": true, "is_repeatable": true,
    "is_success": true, "start_time": "...", "end_time": "...",
    "reason": "Produk sudah diterapkan di shopee", "raiseproduct_detail_id": "uuid",
    "raiseproduct_id": "uuid" } ] },
  "meta": { "per_page": 10, "total": 5 } }
```

## 9. Test (PHPUnit + RefreshDatabase + withoutMiddleware)
**RaiseProductTest**
1. list per toko + `product_active` = jumlah detail aktif + struktur.
2. default pagination 10.
3. search by store_name; filter channel/shop_id/is_active.
4. detail: header + `details[]` shape (item_id/item_codes/thumbnails sejajar, channel_url built/stored).
5. detail unknown → 404.

**RaiseProductManageTest**
6. create header sukses + tolak bila toko sudah punya (one-to-one toko, 422).
7. addProduct sukses + tolak bila produk sudah dinaikkan (one-to-one produk, 422) + tolak bila produk bukan milik toko.
8. removeProduct.
9. toggle is_active/is_repeatable.
10. raise → 202 + start_time terisi + job ter-dispatch (Queue::fake).
11. delete header → cascade details.

## 9b. Kepatuhan agents.md (WAJIB di setiap fase)

| Poin agents.md | Penerapan konkret di fitur ini |
|---|---|
| **1. Service-Repository** | `RaiseProductController` **hanya** validasi request + delegasi ke `RaiseProductService` + balas via ApiResponse. **Tidak ada** query DB / `find()` / `where()` / mutasi model di controller. Semua query DB di `RaiseProductRepository`. Logika bisnis (guard one-to-one, dispatch job, hitung `product_active`) di `RaiseProductService`. |
| **2. ApiResponse + Resources** | Controller pakai trait `App\Traits\ApiResponse`: `successPaginatedResponse` (list/details), `successResponse` (create/detail/toggle), `errorResponse` (404/422). Bentuk data lewat `RaiseProductResource` & `RaiseProductDetailResource` — **tanpa** skema JSON berulang/model mentah. Pada paginasi, transform resource dulu (`setCollection(map->resolve())` atau `->through()`) sebelum `successPaginatedResponse`. **Tanpa** `view()`/`redirect()`/`with()`. |
| **3. Spatie Query Builder** | List `raise-products` & sub-list `details[]` = `QueryBuilder::for(...)` di repository (keduanya bisa difilter/urut/paginasi frontend → wajib Spatie). `AllowedFilter::callback`/`exact` untuk channel/shop_id/is_active/is_success; `AllowedSort::field` (kolom dikualifikasi bila ada `leftJoin`, hindari ambiguous). Lookup 1-record (`find` header by id, resolve `channel_shop_id` by `shop_id`, cek mapping milik toko) = **Eloquent biasa** di repository (pengecualian sah, bukan Spatie). |
| **4. `?search=` via macro** | Search list = `allowedSearch('channel_shops.shop_name')` (leftJoin channel_shops) — FTS `indonesian`, **bukan** `ilike` manual. Search details = `allowedSearch('products.name')` (leftJoin via mapping). Tidak ada manual request-merge. |
| **5. Pagination 10 + appends** | Semua paginate: `->paginate(request('per_page', 10))->appends(request()->query())`. Berlaku untuk list header maupun `details[]`. |
| **Tanpa komentar** | Kode produksi (controller/service/repo/resource/model/job/migrasi) ditulis **tanpa komentar**, konsisten instruksi sebelumnya. |
| **Konvensi query** | Gaya Spatie (`search`/`per_page`/`sort=-field`/`filter[]`), **bukan** gaya Jubelio (`sort_by`/`sort_direction`/`page_size`) — selaras endpoint lain yang sudah dibangun. |

> Verifikasi tiap fase: setelah implementasi, jalankan grep audit (query DB/`view()` di controller harus nihil; setiap `paginate()` berasal dari `QueryBuilder::for`; controller pakai `ApiResponse`) — sama seperti audit yang sudah dilakukan pada modul Product.

## 10. Urutan eksekusi
- **Fase 1** — migrasi + model.
- **Fase 2** — Repository + Resource.
- **Fase 3** — Service (guards one-to-one) + Controller + routes.
- **Fase 4** — Job async + endpoint raise (202).
- **Fase 5** — Test (RaiseProductTest + RaiseProductManageTest), jalankan + regresi modul.
- **Fase 6** — commit `feat(product): tab Naikkan Produk (raise/boost produk per toko)`.

## 11. Di luar scope
- Adapter relist/boost **Shopee/Lazada/Tokopedia** (saat ini hanya TikTok). Eksekusi non-tiktok = pending/ditandai sampai adapter tersedia.
- Penjadwalan auto-repeat berkala (command terjadwal) — bisa fase lanjutan setelah eksekusi dasar jalan.
- UI.
