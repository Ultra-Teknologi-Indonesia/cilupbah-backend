# Plan: Merge & Auto-Merge Produk (port dari cilupbah-ops)

> Status: **✅ IMPLEMENTED** (31 test hijau) · Target module: `Modules/Product` · Tanggal: 2026-06-08

## ✅ Status implementasi

Semua komponen di §3 sudah dibuat & lulus tes (`php artisan test Modules/Product/tests/Feature/ProductMerge*` → 25 passed, 0 regресi pada tes Product lama).

**Multi-store / multi-channel** (requirement tambahan): merge bekerja **lintas toko & channel**.
Karena `Product ↔ ChannelShop` adalah many-to-many via `ProductChannelMapping` dan tiap shop
punya `Channel`, overlay merge di level Product otomatis menggabungkan produk dari toko/channel
manapun. Tiap master group di `catalog` & `applied` mengembalikan agregat `channels[]` +
`channel_count` (distinct lintas semua produk anggota). Diverifikasi oleh test
`test_auto_merge_works_across_different_stores_and_channels` (produk SKU `SLR-A` di Shopee +
`SLR-B` di TikTok → 1 master, `channel_count = 2`, channels `[Shopee, TikTok]`).

**Performa (tetap Eloquent):** query produk induk pakai `->lazy()` (LazyCollection,
streaming per-chunk 1000) + projeksi kolom (`id, name, sku, category_id, brand_id`),
jadi tidak menahan ribuan model + relasinya di memori sekaligus. Eager load tetap jalan
per chunk (bukan `cursor()` yang memicu N+1). Trade-off: query relasi per chunk (≈N/1000×),
tapi peak memory bounded. Order lazy `mergesWithProducts` dikunci `(master_name, id)`
agar paginasi offset deterministik (tidak skip/duplikat).

Catatan deviasi kecil dari rencana: **Resources tidak dibuat sebagai file terpisah** —
service sudah mengembalikan array bersih, dikemas langsung via `ApiResponse`. Endpoint
`unmergeMaster` divalidasi inline di controller (bukan FormRequest terpisah). Sisanya sesuai §3.

### Otorisasi: wajib login (Sanctum) + Spatie Laravel Permission

**Semua** endpoint merge berada di dalam grup `auth:sanctum` → wajib login dulu. Stack
middleware tiap route: `Authenticate:sanctum` → `PermissionMiddleware`, jadi tanpa token
valid langsung **401** (sebelum permission dievaluasi). Diverifikasi
`test_all_endpoints_require_sanctum_login` (11 endpoint → 401 tanpa auth).

Setelah login, akses berbasis **permission** — selama user (lewat role manapun) punya permission-nya, boleh akses.
Role `owner` otomatis lolos via `Gate::before` di `AppServiceProvider` (super-user). Spatie
`PermissionMiddleware` memakai `canAny()` yang lewat Gate, jadi bypass owner berlaku.
Permission di-seed `Modules/Product/database/seeders/ProductPermissionSeeder.php` (guard `web`),
default di-assign ke role `owner` + `admin`, dan terdaftar di `ProductDatabaseSeeder`.

**Mapping route → permission:**

| Permission | Endpoint |
|---|---|
| `view-product-merge` | GET `catalog`, `suggestions`, `applied` |
| `auto-merge-product` | POST `auto` |
| `merge-product` | POST `apply`, `bulk` |
| `unmerge-product` | POST `bulk-unmerge`, DELETE `master`, DELETE `{product}` |
| `hide-product` | POST `hide`, `unhide` |

Untuk memberi akses ke role lain: cukup `givePermissionTo(...)` permission yang relevan —
tidak perlu ubah kode/route. Diverifikasi `ProductMergePermissionTest`: 401 tanpa auth,
403 tanpa permission, 200 dengan permission per-aksi, admin (dari seeder) lolos, owner bypass.

---


Dokumen ini memetakan fitur **Merge** dan **Auto-Merge produk** yang sudah ada di
`cilupbah-ops` (Fastify + tRPC + Prisma) ke `cilupbah-be` (Laravel 12 modular monolith,
`nwidart/laravel-modules`). Logic grouping dibuat **identik** dengan cilupbah-ops:
produk dikelompokkan berdasarkan **kode awal SKU sampai tanda `-` pertama**
(mis. `SLR-GREEN-IP14` → prefix `SLR`).

---

## 1. Ringkasan fitur di cilupbah-ops (sumber kebenaran)

Sumber: `cilupbah-ops/apps/api/src/trpc/routers/product.router.ts` + `ProductMergeClient.tsx`.

Fitur ini adalah **overlay non-destruktif**: tidak mengubah data produk sumber
(Jubelio). Ia hanya menyimpan dua tabel kecil:

| Tabel cilupbah-ops | Isi | Fungsi |
|---|---|---|
| `local_product_merge` | `sku` → `master_name` | menempelkan SKU ke satu "master" |
| `local_product_hidden` | `master_name` | menyembunyikan master dari konsumen hilir |

Konsep kunci yang **wajib direplikasi**:

1. **`skuTypeCode(sku)`** — segmen pertama SKU sebelum `-`, di-uppercase.
   Return `null` kalau panjang `< 2` (biar tidak nge-bucket noise).
   ```
   "SLR-GREEN-IP14" → "SLR"
   "ABC"            → "ABC"
   "A-B"            → null   (prefix "A" < 2 char)
   ""               → null
   ```
2. **`normalizeName(name)`** — strip diakritik, trim, lowercase, collapse spasi.
   Dipakai sebagai **kunci grouping katalog** & dedup nama.
3. **`nameSignature(name)`** — 3 kata pertama (buang device tail "for iphone/…",
   tanda kurung, dan setelah `/`). Dipakai khusus tab **Rekomendasi**.
4. **`resolveMasterPerKey()`** — untuk tiap grup, pilih master yang sudah ada;
   tie-break by jumlah pemakaian lalu panjang nama (desc).
5. **Auto-merge** — grup SKU solo by `code:<prefix>` (plus 2 grup khusus by nama:
   `premium full cover` & `matte soft case`). Master existing **tidak** ditimpa.
   Master baru = nama unik **terpanjang** dalam grup. Hanya apply kalau grup ≥ 2.
6. **Cascade hidden** saat merge/unmerge (lihat §6).

Endpoint tRPC yang ada:

| Procedure | Tipe | Guna |
|---|---|---|
| `catalog` | query | list katalog ter-group (`all/merged/unmerged/hidden`, search, paginate) |
| `suggestions` | query | rekomendasi grup by `nameSignature + skuTypeCode` |
| `listMerges` | query | semua merge aktif, ter-group per master (tab "Sudah Di-merge") |
| `autoMergeAll` | mutation | grup semua SKU solo by prefix; insert massal |
| `applyMerge` | mutation | merge daftar SKU eksplisit ke 1 master (atomik) |
| `bulkMergeProducts` | mutation | merge ≥2 **nama produk** → 1 master |
| `unmerge` | mutation | lepas 1 SKU |
| `unmergeMaster` | mutation | hapus 1 master (semua SKU balik) |
| `bulkUnmergeMasters` | mutation | hapus banyak master |
| `bulkHide` / `bulkUnhide` | mutation | hide/unhide master |

---

## 2. Pemetaan model: cilupbah-ops → cilupbah-be

Perbedaan struktural penting:

- **cilupbah-ops**: `jubelio_products` adalah baris **per-SKU** (flat). Merge bekerja
  pada level SKU. Tidak ada konsep variant.
- **cilupbah-be**: hierarki **`Product` (UUID) → `ProductVariant` (UUID, punya `sku`)**.
  `products.sku` ada tapi **nullable**; SKU "asli" hidup di `product_variants.sku`.

### Keputusan desain

**Unit merge = `Product`** (bukan variant). Ini paling dekat dengan "katalog item"
di cilupbah-ops dan sesuai permintaan user ("merge **produk**"). Overlay tetap
non-destruktif — produk & variant sumber tidak diubah.

**Sumber prefix SKU** untuk grouping (fungsi `skuTypeCode`):

Dasar dari spec Jubelio (`dist (3).yaml` → `getItemCatalogResponse`, baris ~10134):
SKU asli = **`item_code`** yang hidup di array **`product_skus[]`** (level item/varian),
contoh `RIC-COO-MIY-RCM-PIN` → prefix `RIC`. `item_group` (induk) tidak punya SKU sendiri.
Di cilupbah-be `item_code` ≈ **`product_variants.sku`**. Maka:

1. Pakai **`product_variants.sku` varian pertama** (= `item_code`) — sumber utama, paling setia ke Jubelio.
2. Fallback ke `products.sku` kalau produk tak punya varian ber-SKU.
3. Kalau dua-duanya kosong → produk **tidak bisa** di-auto-merge (skip; kasus negatif).

> Catatan: 1 produk bisa punya banyak varian dengan prefix beda; prefix produk diambil
> dari SKU representatif (varian pertama), **bukan** gabungan semua varian — menjaga
> paritas dengan ops yang 1 baris = 1 `item_code` = 1 prefix.

### Tabel baru (mirror `local_product_merge` & `local_product_hidden`)

```
product_merges
  id            uuid    PK (HasUuid7)
  product_id    uuid    FK → products.id (ON DELETE CASCADE), UNIQUE
  master_name   string  (index)
  created_at / updated_at

product_merge_hidden
  id            uuid    PK
  master_name   string  UNIQUE
  created_at / updated_at
```

- `product_id` **unique** → 1 produk hanya boleh menempel ke 1 master (sama seperti
  `local_product_merge.sku` yang unik di ops).
- FK `ON DELETE CASCADE` → kalau produk dihapus, baris merge ikut hilang (ops tidak
  punya FK karena beda DB; di Laravel kita perketat).
- `master_name` di `product_merge_hidden` adalah **nama efektif** (master name untuk
  produk merged, atau `products.name` untuk produk solo) — persis pola ops.

---

## 3. Berkas yang dibuat / disentuh

```
Modules/Product/
├── database/migrations/
│   ├── 2026_06_08_1000_create_product_merges_table.php          (BARU)
│   └── 2026_06_08_1001_create_product_merge_hidden_table.php    (BARU)
├── app/Models/
│   ├── ProductMerge.php          (BARU)
│   └── ProductMergeHidden.php    (BARU)
├── app/Support/
│   └── SkuGrouping.php           (BARU — skuTypeCode, normalizeName, nameSignature)
├── app/Repositories/
│   └── ProductMergeRepository.php (BARU — query mentah katalog/merge/hidden)
├── app/Services/
│   └── ProductMergeService.php    (BARU — semua logic, transaksional)
├── app/Http/Controllers/
│   └── ProductMergeController.php (BARU)
├── app/Http/Requests/
│   ├── ApplyMergeRequest.php       (BARU)
│   ├── BulkMergeProductsRequest.php(BARU)
│   ├── BulkMasterNamesRequest.php  (BARU — dipakai unmerge/hide/unhide)
│   └── AutoMergeRequest.php        (BARU)
├── app/Http/Resources/
│   ├── ProductCatalogResource.php  (BARU)
│   └── ProductMergeGroupResource.php (BARU)
├── routes/api.php                  (EDIT — tambah grup route merge)
└── tests/Feature/
    ├── ProductMergeServiceTest.php (BARU — unit logic murni)
    └── ProductMergeApiTest.php     (BARU — endpoint + edge cases)
```

Relasi tambahan di `Product.php`:
```php
public function merge(): HasOne          // hasOne(ProductMerge::class)
```

---

## 4. Spesifikasi endpoint (REST, prefix `v1`)

Semua di belakang `auth:sanctum`. Mengikuti envelope `ApiResponse`
(`successResponse` / `successPaginatedResponse` / `errorResponse`).

> Penempatan route: definisikan **sebelum** `apiResource('products')` supaya
> `products/merge/...` tidak ketangkap `products/{id}` (pola yang sama sudah dipakai
> untuk `products/uploadable` di `routes/api.php:17`).

| Method & Path | Service call | Body / Query |
|---|---|---|
| `GET /v1/products/merge/catalog` | `catalog()` | `filter`, `q`, `page`, `limit` |
| `GET /v1/products/merge/suggestions` | `suggestions()` | `q?` |
| `GET /v1/products/merge/applied` | `listMerges()` | `q?` |
| `POST /v1/products/merge/auto` | `autoMergeAll()` | `name_pattern_groups?` |
| `POST /v1/products/merge/apply` | `applyMerge()` | `master_name`, `product_ids[]` (≥2) |
| `POST /v1/products/merge/bulk` | `bulkMergeProducts()` | `master_name`, `product_names[]` (≥2) |
| `POST /v1/products/merge/bulk-unmerge` | `bulkUnmergeMasters()` | `master_names[]` |
| `POST /v1/products/merge/hide` | `bulkHide()` | `master_names[]` |
| `POST /v1/products/merge/unhide` | `bulkUnhide()` | `master_names[]` |
| `DELETE /v1/products/merge/master` | `unmergeMaster()` | `master_name` |
| `DELETE /v1/products/merge/{product}` | `unmerge()` | path = product_id |

Catatan beda dari ops:
- Pakai `product_ids` (UUID) alih-alih `skus` untuk `apply`, karena unit merge = Product.
- `bulk` tetap pakai `product_names` (cocokkan case-insensitive via `normalizeName`),
  meniru `bulkMergeProducts` ops.

---

## 5. Detail logic per method (porting 1:1)

### 5.1 `catalog(filter, q, page, limit)`
Mirror `productRouter.catalog`.

1. Ambil produk `status = master` (analog `status = 1` di ops — di Jubelio item yang
   `is_active && sell_this` adalah item layak katalog; padanan di be = `STATUS_MASTER`).
   Kolom: `id, name, sku, category, brand`, + foto primary (join `product_media is_primary`),
   + sku varian pertama (untuk prefix & search). Field **vendor** ditampilkan dari
   `brand.name` (lihat keputusan §11-Q5).
2. Ambil semua `product_merges` (map `product_id → master_name`) dan
   `product_merge_hidden` (set `master_name`).
3. Untuk tiap produk: `effName = masterName ?? product.name`; `key = normalizeName(effName)`.
4. Group ke `Map<key, Group>` (akumulasi: count, foto pertama non-null, vendor/brand,
   daftar produk anggota). `merged = true` kalau ada anggota yang punya master.
   `hidden = hiddenSet.has(effName)`.
5. Filter: `hidden` → hanya hidden; `merged` → merged & !hidden; `unmerged` →
   !merged & !hidden; `all` → !hidden.
6. Search (kalau `q`): cocokkan `normalizeName(q)` ke key / brand / category / sku anggota.
7. Sort: merged dulu (by count desc, lalu nama), baru solo (alfabet).
8. Hitung `counts {all, merged, unmerged, hidden}` dari set **tanpa** search.
9. Paginate (offset). Return `{ rows, total, truncated, counts }`.

### 5.2 `suggestions(q?)`
Mirror `productRouter.suggestions`.

- `keyFn(p) = "${nameSignature(name)}|${skuTypeCode(prefixSku)}"`; null kalau salah satu null.
- `existingMasterBySig = resolveMasterPerKey(produk, mergeMap, keyFn)`.
- Group **produk solo** (belum merged) by key.
- Skip grup kalau: tidak ada master existing **dan** `< 2` anggota.
- Skip kalau tidak ada existing **dan** semua nama identik (sudah natural).
- Master = existing, atau nama paling sering / terpanjang.
- Output per grup: `prefix` (`"sig · CODE"`), `suggested_master_name`,
  `existing_master`, `unique_name_count`, `total`, `products[]`.
- Sort: existing dulu, total desc, unique asc, nama.

### 5.3 `listMerges(q?)`
Mirror `productRouter.listMerges`. Ambil semua `product_merges` urut `master_name, product`.
Group per `master_name` → `{ master_name, products: [{id, name, sku, updated_at}] }`.
Filter `q` opsional (master / nama produk / sku).

### 5.4 `autoMergeAll(name_pattern_groups?)`
Mirror `productRouter.autoMergeAll`. **Method paling penting** — ini "by huruf
di awal sampai - pertama".

Default pattern groups (boleh dioverride lewat body):
```
[ ["premium full cover","premium silikon full","premium silicone full"],
  ["matte soft case"] ]
```
`groupKey(p)`:
1. Kalau `lower(name)` `startsWith` salah satu pattern → `"name:group{i}"`.
2. Else → `"code:{skuTypeCode(prefixSku)}"`, atau `null` kalau prefix null.

Langkah:
1. Ambil semua produk master + mergeMap existing.
2. `existingMasterByKey = resolveMasterPerKey(...)`.
3. Group **produk solo** by key; `groupAllSize` = total termasuk yang sudah merged.
4. Untuk tiap key: skip kalau `!existing && totalSize < 2`.
   Master = `existing ?? nama unik terpanjang`. Push semua solo ke `toApply`.
5. `DB::transaction` → insert massal ke `product_merges`
   (`upsert`/`insertOrIgnore` by `product_id`, skip duplikat).
6. Return `{ merged: count, groups_affected }`.

### 5.5 `applyMerge(master_name, product_ids)`
Mirror `productRouter.applyMerge` + `applyMergeToSkus`. Atomik:

1. Validasi `master_name` non-kosong (trim).
2. Validasi **semua** `product_ids` ada di `products` → kalau ada yang hilang,
   `422` dengan daftar 5 pertama (persis ops).
3. Dalam transaksi: snapshot nama efektif lama → hapus baris merge lama untuk
   produk-produk itu → insert baris baru ke `master_name` → **cascade hidden** (§6).
4. Return `{ merged: count, master_name }`.

### 5.6 `bulkMergeProducts(master_name, product_names)`
Mirror `productRouter.bulkMergeProducts`.
- `targetNormKeys = product_names.map(normalizeName)`.
- Kumpulkan product_id dari: (a) produk yang `master_name`-nya ∈ product_names
  (sudah merged), (b) produk yang `normalizeName(name)` ∈ targetNormKeys.
- Union → kalau kosong, `422`. Else `applyMergeToProducts(ids, master_name)` (atomik + cascade).

### 5.7 `unmerge(product_id)`
Hapus 1 baris `product_merges` by `product_id`. Idempoten (0 baris = tetap `ok`).

### 5.8 `unmergeMaster(master_name)` & `bulkUnmergeMasters(master_names)`
Mirror ops: dalam transaksi → **cascade hidden turun** (§6) → hapus baris merge.
Return `{ removed }` (+`masters` untuk bulk).

### 5.9 `bulkHide` / `bulkUnhide(master_names)`
`hide`: `insertOrIgnore` ke `product_merge_hidden`. `unhide`: `delete where in`.

---

## 6. Cascade hidden (jangan dilewat — sumber bug halus)

Replika `applyMergeToSkus` + `cascadeHiddenOnUnmerge`:

- **Saat merge**: kumpulkan nama efektif **lama** semua produk yang dimerge
  (master lama untuk yang merged, `products.name` untuk solo). Kalau **ada** yang
  hidden → master **baru** mewarisi flag hidden. Baris hidden lama dibersihkan
  (kecuali yang == master baru).
- **Saat unmerge master**: kalau master sedang hidden → turunkan flag hidden ke
  **nama produk asli** tiap anggota, lalu hapus baris hidden master.

Ini menjaga invariant: "kalau item disembunyikan, ia tetap tersembunyi melewati
operasi merge/unmerge".

---

## 7. Matriks use case — positif & negatif

### 7.1 `skuTypeCode` / prefix (inti "sampai `-` pertama")
| Input | Output | Catatan |
|---|---|---|
| `SLR-GREEN-IP14` | `SLR` | happy path |
| `slr-green` | `SLR` | uppercase-kan |
| `ABCD` (tanpa `-`) | `ABCD` | seluruh string |
| `AB` | `AB` | tepat batas 2 char |
| `A-B` | `null` | prefix `A` < 2 char → tidak di-bucket |
| `` (kosong) | `null` | |
| `-XYZ` | `null` | prefix kosong |
| produk `sku=null`, varian pertama `SLR-1` | `SLR` | fallback ke varian |
| produk `sku=null`, tanpa varian | `null` | tidak bisa auto-merge |

### 7.2 `autoMergeAll`
**Positif**
- 3 produk solo prefix `SLR` → 1 master "nama terpanjang", `merged=3, groups_affected≥1`.
- Grup yang sudah punya master existing → produk solo baru ditambahkan ke master itu.
- Produk nama diawali "premium full cover" → masuk grup nama, bukan grup kode SKU.
- Override `name_pattern_groups` dari body → dipakai sebagai pattern.

**Negatif / edge**
- Prefix muncul cuma 1× (totalSize<2, tanpa existing) → **tidak** dimerge.
- Semua produk sudah merged → `merged=0, groups_affected=0`.
- Produk `sku` & varian kosong → di-skip (tidak error).
- Panggil 2× berturut → idempoten (`insertOrIgnore`, run kedua `merged=0`).
- `name_pattern_groups` berisi array kosong → tolak `422` (min:1 per grup).

### 7.3 `applyMerge`
**Positif**: 2 product_id valid + master_name → merged, baris merge tercatat.
**Negatif**
- `product_ids` < 2 → `422` (validasi).
- Salah satu product_id tidak ada → `422` "Produk tidak ditemukan: …".
- product_id bukan UUID valid → `422` (validasi `uuid`).
- `master_name` kosong / hanya spasi → `422`.
- `master_name` > 200 char → `422`.
- Produk sudah merged ke master lain → dipindah (delete-then-insert, atomik).
- Item lama hidden → master baru inherit hidden (cek §6).

### 7.4 `bulkMergeProducts`
**Positif**: 2 nama produk (campuran solo + master existing) → union SKU ter-merge.
**Negatif**
- `product_names` < 2 → `422`.
- Tidak ada produk yang cocok nama-nya → `422` "Tidak ada produk ditemukan".
- Nama beda case/spasi/diakritik tapi sama → **tetap** match (normalizeName).

### 7.5 `catalog`
**Positif**: filter `merged/unmerged/hidden/all`, search by nama/brand/sku,
pagination, `counts` akurat & stabil saat search.
**Negatif / edge**
- `q` tanpa hasil → `rows: []`, `total: 0` (200, bukan error).
- `limit` di luar 1..500 → di-clamp / `422` (sesuai aturan validasi).
- Produk tanpa foto → `foto: null` (bukan error).
- Diakritik di nama (`Café`) ↔ search `cafe` → match.

### 7.6 `unmerge` / `unmergeMaster` / `bulkUnmerge`
**Positif**: lepas 1 produk; hapus master (semua anggota balik); bulk hapus banyak.
**Negatif**
- `unmerge` product_id yang tidak ter-merge → `ok` (idempoten, removed=0).
- `unmergeMaster` master_name tidak ada → `removed=0` (bukan 404).
- master sedang hidden → cascade hidden turun ke nama produk asli (§6).

### 7.7 `hide` / `unhide`
**Positif**: hide master → hilang dari `catalog` non-hidden, muncul di filter `hidden`.
**Negatif**: hide master_name yang sudah hidden → idempoten (insertOrIgnore).

### 7.8 Auth & guard umum
- Tanpa token sanctum → `401` semua endpoint.
- Mutasi (auto/apply/bulk/unmerge/hide) di-gate role **admin/owner** (§11-Q4) →
  non-admin `403`. `catalog` cukup `auth:sanctum`.

---

## 8. Aturan validasi (Form Requests)

```
ApplyMergeRequest
  master_name        required|string|max:200
  product_ids        required|array|min:2
  product_ids.*      uuid

BulkMergeProductsRequest
  master_name        required|string|max:200
  product_names      required|array|min:2
  product_names.*    string

BulkMasterNamesRequest   (unmerge bulk / hide / unhide / unmergeMaster)
  master_names         required|array|min:1   (untuk bulk)
  master_name          required|string        (untuk unmergeMaster single)

AutoMergeRequest
  name_pattern_groups            sometimes|array
  name_pattern_groups.*          array|min:1
  name_pattern_groups.*.*        string

catalog query
  filter   in:all,merged,unmerged,hidden   (default all)
  q        nullable|string
  page     integer|min:1
  limit    integer|min:1|max:500
```

Trim `master_name` di service sebelum dipakai (jangan andalkan input mentah).

---

## 9. Rencana test (`tests/Feature`)

Pakai `RefreshDatabase` + `withoutMiddleware` (pola `ProductCatalogTest`).
Seed `categories`/`brands` minimal, buat `Product` + `ProductVariant`.

**`ProductMergeServiceTest`** (logic murni, tanpa HTTP):
- `test_sku_type_code_*` — semua baris tabel §7.1.
- `test_normalize_name_strips_diacritics_and_case`.
- `test_name_signature_strips_device_tail`.
- `test_auto_merge_groups_by_prefix`.
- `test_auto_merge_skips_singletons`.
- `test_auto_merge_is_idempotent`.
- `test_auto_merge_respects_existing_master`.
- `test_auto_merge_name_pattern_group`.
- `test_cascade_hidden_on_merge` & `test_cascade_hidden_on_unmerge`.

**`ProductMergeApiTest`** (endpoint + edge):
- catalog: filter, search, counts, pagination, empty result.
- apply: sukses; <2 → 422; product_id hilang → 422; master kosong → 422.
- bulk: sukses union; <2 → 422; tidak match → 422.
- auto: sukses + idempoten; pattern kosong → 422.
- unmerge / unmergeMaster idempoten; bulk-unmerge.
- hide/unhide pengaruh ke catalog.
- 401 tanpa auth (test terpisah tanpa `withoutMiddleware`).

Jalankan: `rtk pest Modules/Product` atau `rtk php artisan test --filter=ProductMerge`.

---

## 10. Urutan implementasi (commit-able steps)

1. **Migrasi** `product_merges` + `product_merge_hidden` → `php artisan migrate`.
2. **Models** `ProductMerge`, `ProductMergeHidden` + relasi `Product::merge()`.
3. **`app/Support/SkuGrouping.php`** — port `skuTypeCode`, `normalizeName`,
   `nameSignature`, `resolveMasterPerKey`. + `ProductMergeServiceTest` bagian helper
   (TDD: tulis test §7.1 dulu).
4. **`ProductMergeRepository`** — query katalog (raw/builder), merge map, hidden set.
5. **`ProductMergeService`** — `catalog`, `suggestions`, `listMerges`, lalu
   `autoMergeAll`, `applyMerge`, `bulkMergeProducts`, unmerge/hide + cascade.
6. **Form Requests** + **Resources**.
7. **`ProductMergeController`** + **routes** (taruh sebelum `apiResource`).
8. **`ProductMergeApiTest`** — lengkapi edge cases §7.
9. `rtk php artisan test --filter=ProductMerge` hijau → selesai.

---

## 11. Keputusan desain (RESOLVED via spec Jubelio `dist (2/3).yaml`)

5 poin yang sebelumnya terbuka kini diputuskan berdasarkan spec Jubelio — sumber data
yang dipakai cilupbah-ops, jadi otoritatif untuk menjaga paritas.

### Q1 — Scope status katalog → **`STATUS_MASTER`**
Di Jubelio, item layak katalog ditandai `is_active` + `sell_this`
(`dist (3).yaml` `getItemCatalogResponse` baris ~9989–10020). Ops memfilter `status = 1`
(= item published/aktif). Padanan paling tepat di cilupbah-be (lifecycle
`download → in_review → master → archived`) adalah **`STATUS_MASTER`** — produk yang
sudah disetujui & siap jual. `in_review`/`download`/`archived` **tidak** dikatalogkan.

### Q2 — Unit merge → **Product-level (overlay non-destruktif)**
Struktur Jubelio: `item_group` (induk) → `product_skus[]` (`item_code` per varian)
(`dist (3).yaml` baris ~9953, ~10134). Di cilupbah-be: `Product` ≈ `item_group`,
`ProductVariant` ≈ `product_sku/item_code`. Grouping varian-ke-induk **sudah native**
di cilupbah-be (Product punya variants), jadi merge yang diport = mengelompokkan
**beberapa `Product`** yang harusnya satu, ke bawah satu **master_name** — sama persis
peran merge di ops. **Tidak** mengubah produk jadi varian (tetap overlay; data sumber utuh).

### Q3 — Sumber prefix saat SKU induk kosong → **varian dulu, baru induk**
SKU asli Jubelio (`item_code`) hidup di level varian (`product_skus[]`), bukan di induk.
Maka prefix diambil: **(1) `product_variants.sku` varian pertama**, (2) fallback
`products.sku`, (3) kosong dua-duanya → skip. (Sudah disesuaikan di §2.)

### Q4 — Otorisasi → **mutasi = admin/owner; baca = any auth**
Spec Jubelio tidak mengatur RBAC internal kita, tapi paritas dengan ops menentukan:
ops memakai `adminProcedure` untuk semua mutation (auto/apply/bulk/unmerge/hide) dan
`protectedProcedure` untuk `catalog`. Di cilupbah-be: GET `catalog` cukup `auth:sanctum`;
seluruh endpoint mutasi di-gate role **admin/owner** (Spatie Permission — sudah ada di
`composer.json`). Non-admin → `403`.

### Q5 — Vendor → tampilkan **`brand.name`**
`dist (3).yaml` baris 16913 & `dist (2).yaml` baris 22960 mendefinisikan `vendor` =
_"Supplier/vendor name in contact menu"_ — yaitu **kontak supplier**, BERBEDA dari
`brand_name`/`brand_id` (baris ~10082, ~10102). Di cilupbah-be `Product` tidak punya
FK supplier (hanya `brand_id`), jadi kolom "vendor" di katalog **diisi `brand.name`**
sebagai label tampilan (display-only, non-kritis). Catatan: kalau nanti dibutuhkan
vendor = supplier sebenarnya, perlu relasi `Product → Supplier` baru (di luar scope ini).

> Semua keputusan terkunci. Implementasi siap jalan mengikuti urutan §10.
