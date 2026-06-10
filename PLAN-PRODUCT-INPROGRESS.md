# Plan — Selesaikan 8 Endpoint Product (in_progress, PIC: Darriel)

> **Disusun:** 2026-06-10 · **Scope:** 8 item `in_progress` domain **Product** dari Dev Tracker (id 78,82,92,95,102,105,106,112).
> **Status verifikasi:** dicek langsung ke kode modul `Product` & `Inventory`.
> **Temuan utama:** ke-8 endpoint **bukan pekerjaan dari nol** — model & controller pendukung sudah ada, tinggal menambah method/route + samakan kontrak Jubelio. Hanya **1 item (bundle create)** yang mungkin butuh tabel komponen baru.

---

## 1. Fakta Kode (hasil verifikasi)

| Komponen | Sudah ada? | Bukti |
|---|---|---|
| `Product` model | ✅ | kolom `sku`, `is_bundle`, `status`; relasi `variants()` |
| `ProductVariant` model | ✅ | kolom `sku`, `sell_price` (decimal) |
| Mapping kategori→channel | ✅ | `ChannelCategory.localCategories` (pivot) + `CategoryController@mapChannel` (`mapToChannel`) |
| Channel category listing | ✅ | `ChannelCategoryController@index(channelId)` |
| Channel attribute listing | ✅ | `ChannelAttributeController@index(channelId, categoryId)` |
| Sumber stok produk | ✅ | `InventoryController@stockProducts` |
| Komponen bundle | ⚠️ belum | `Product.is_bundle` ada, tapi **belum ada relasi/tabel komponen bundle** |

---

## 2. Ringkasan Task & Estimasi

| ID | Endpoint | Effort | Inti pekerjaan |
|---|---|---|---|
| 105 | `GET /inventory/items/by-sku/{sku}` | 🟢 S (~2 jam) | query by sku (Product/Variant) |
| 92 | `GET /inventory/item-bundles/` | 🟢 S (~2 jam) | filter `is_bundle=true` |
| 82 | `GET /inventory/categories/category-map/{id}` | 🟢 S (~3 jam) | baca pivot mapping |
| 102 | `POST /inventory/items/all-stocks/` | 🟢 S (~3 jam) | stok by banyak ID |
| 112 | `POST /inventory/items/prices/` | 🟢 S (~3 jam) | harga (`sell_price`) by banyak ID |
| 106 | `GET /inventory/items/channel-category-attributes/` | 🟡 M (~½ hari) | varian listing global attribute |
| 78 | `GET /inventory/categories/{channel_id}/store-categories/{store_id}` | 🟡 M (~1 hari) | dimensi `store_id` per channel |
| 95 | `POST /inventory/items/` (bundle) | 🟠 L (~1–1.5 hari) | komponen bundle (tabel baru) |

**Total estimasi: ~4 hari kerja.**

---

## 3. Detail Per Endpoint

### 🟢 105 — `GET /inventory/items/by-sku/{sku}` — Ambil produk per SKU
- **Sudah ada:** `Product.sku`, `ProductVariant.sku`, `ProductController@show`.
- **Task:**
  1. `ProductController@showBySku($sku)` — cari `Product::where('sku',$sku)` ATAU lewat `ProductVariant::where('sku',$sku)->product`.
  2. Route: `GET products/by-sku/{sku}` + alias compat `inventory/items/by-sku/{sku}`.
  3. Response samakan dengan `@show`.
- **DoD:** SKU valid → produk; SKU tak ada → 404 format Jubelio.

### 🟢 92 — `GET /inventory/item-bundles/` — Ambil semua bundle produk
- **Sudah ada:** `Product.is_bundle`, `ProductController@index`.
- **Task:**
  1. `ProductController@bundles()` = `Product::where('is_bundle',true)->with('variants')->paginate()`.
  2. Route: `GET item-bundles` + alias `inventory/item-bundles/`.
- **DoD:** hanya produk `is_bundle=true` yang muncul, terpaginasi.

### 🟢 82 — `GET /inventory/categories/category-map/{id}` — Pemetaan kategori ke marketplace
- **Sudah ada:** `POST categories/{category}/map-channel` (set mapping via `mapToChannel`), pivot `localCategories`.
- **Task:**
  1. `CategoryController@channelMap($id)` — baca channel categories yang ter-map ke `category $id` (dari pivot).
  2. Route: `GET categories/{id}/channel-map` + alias `inventory/categories/category-map/{id}`.
- **DoD:** kembalikan daftar channel category termapping (arah baca dari `mapChannel` yang sudah ada).

### 🟢 102 — `POST /inventory/items/all-stocks/` — Stok produk per banyak ID
- **Sudah ada:** `InventoryController@stockProducts` (sumber stok).
- **Task:**
  1. `InventoryController@stocksByIds(Request)` — input `{item_ids: []}`, kembalikan stok per ID.
  2. Route: `POST inventory/items/all-stocks`.
  3. Validasi `item_ids` array of uuid.
- **DoD:** input banyak ID → array stok; ID kosong → array kosong.

### 🟢 112 — `POST /inventory/items/prices/` — Harga produk per banyak ID
- **Sudah ada:** `ProductVariant.sell_price`.
- **Task:**
  1. `ProductController@pricesByIds(Request)` — input `{item_ids: []}`, ambil `sell_price` per produk/varian.
  2. Route: `POST inventory/items/prices`.
- **DoD:** kembalikan harga per ID (mengikuti field harga Jubelio: harga jual; tambah cost bila perlu).

### 🟡 106 — `GET /inventory/items/channel-category-attributes/` — Semua atribut kategori channel
- **Sudah ada:** `ChannelAttributeController@index(channelId, categoryId)` (per kategori), `ChannelAttributeService`.
- **Task:**
  1. Tambah `ChannelAttributeController@all(Request)` — listing atribut channel (filter opsional `channel_id`, `category_id`), tanpa wajib categoryId.
  2. Tambah method service `listAll()`.
  3. Route: `GET inventory/items/channel-category-attributes`.
- **DoD:** mengembalikan atribut channel global/terfilter, format sama dengan per-kategori.

### 🟡 78 — `GET /inventory/categories/{channel_id}/store-categories/{store_id}` — Kategori toko per channel
- **Sudah ada:** `ChannelCategoryController@index(channelId)`, model `ChannelCategory(channel_id, external_id, parent_external_id)`.
- **Gap:** belum ada dimensi **`store_id`** (kategori spesifik per toko, mis. TikTok shop tertentu).
- **Task:**
  1. Pastikan sumber data store-category: apakah kategori sama per channel atau beda per store. Bila beda → tambah kolom/relasi `store_id` di `channel_categories` (migration) atau tabel `store_categories`.
  2. `ChannelCategoryController@storeCategories($channelId, $storeId)` + service.
  3. Route: `GET inventory/categories/{channel_id}/store-categories/{store_id}`.
- **DoD:** kategori terfilter per channel + store. **Catatan:** bila ternyata kategori tidak per-store, cukup delegasikan ke `@index` (jadi turun ke effort S).

### 🟠 95 — `POST /inventory/items/` — Buat/ubah bundle produk
- **Sudah ada:** `Product.is_bundle`, `ProductController@store` (produk single), `ProductVariant`.
- **Gap:** **belum ada struktur komponen bundle** (produk apa saja + qty di dalam bundle).
- **Task:**
  1. **Migration** `bundle_items` (id, bundle_product_id, component_product_id/variant_id, qty).
  2. Relasi `Product@bundleItems()` (hasMany) + `components()` (belongsToMany via pivot).
  3. `ProductController@storeBundle(Request)` — set `is_bundle=true`, simpan komponen + qty (transaksi).
  4. (Opsional) validasi: komponen harus produk non-bundle yang ada.
  5. Route: `POST inventory/items` (atau `products/bundles`).
- **DoD:** buat bundle dengan ≥1 komponen; edit bundle; stok bundle dihitung dari komponen (bila diperlukan, fase lanjut).

---

## 4. Pendekatan Teknis (konsisten)

1. **Compatibility route** — buat/tambah di file alias (mis. `routes/jubelio-compat.php`) yang memetakan path Jubelio (`/inventory/...`) ke method controller Cilupbah. Hindari mengubah route Cilupbah yang sudah dipakai frontend.
2. **Response transformer** — bila perlu menyamai field Jubelio, buat API Resource (mis. `ItemResource`, `StockResource`, `PriceResource`).
3. **Validasi kontrak** — uji tiap response terhadap schema `dist (2).yaml`.
4. **Update Dev Tracker** — set status `done` + notes (ringkasan + nama route) via `/dev/tracking` setelah tiap item kelar.

---

## 5. Urutan Kerja (quick win dulu)

**Hari 1 — 5 quick win (S):** 105, 92, 82, 102, 112 → 5 endpoint done.
**Hari 2 — listing global (M):** 106 + cek dimensi store untuk 78 (mungkin turun jadi S).
**Hari 3 — store-categories (M):** 78 (migration store_id bila perlu).
**Hari 4 — bundle (L):** 95 (migration `bundle_items` + relasi + store).

**Target:** 8 item in_progress Product → **done**, progress Darriel naik dari 33→41 done.

---

## 6. Definition of Done (per endpoint)
1. Route Jubelio terdaftar & balas 200 dengan data benar.
2. Response menyamai struktur Jubelio (validasi vs `dist (2).yaml`).
3. Validasi input (FormRequest) untuk endpoint POST.
4. Minimal 1 feature test (happy path + 1 error path).
5. Status di `/dev/tracking` di-set **done** + notes.

---

## 7. Risiko & Catatan
- **78 (store-categories):** effort tergantung apakah kategori benar-benar per-store. **Cek dulu** sumber data (TikTok store categories) sebelum bikin migration.
- **95 (bundle):** keputusan desain — apakah komponen pakai `product_id` atau `variant_id`. Sarankan `variant_id` agar presisi SKU. Perhitungan stok bundle = `min(stok_komponen / qty)` bisa jadi fase lanjut, tidak wajib untuk DoD endpoint create.
- **112 (prices) & 102 (all-stocks):** pastikan format harga/stok Jubelio (per produk vs per varian) — ikuti `dist (2).yaml`.
