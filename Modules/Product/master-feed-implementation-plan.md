# Planning: Master Item Feed (Pengganti Output Jubelio)

> Tujuan: sistem mampu **menyimpan** dan **menyajikan** data produk dalam struktur
> yang setara `example-master.json` (format item-master Jubelio), namun memakai
> **UUID** sebagai identitas (bukan integer ID).
>
> Status referensi: cabang `27-product`, modul `Modules/Product`.

---

## 1. Ringkasan Gap (hasil audit)

| Area | Kondisi sekarang | Tindakan |
|---|---|---|
| Penyimpanan inti (produk, varian, mapping channel, media) | ✅ Ada (UUID) | — |
| `variations[]` & `variation_values` | ⚠️ Datanya ada di `product_variation_types` & `variant_options`, **tapi ditulis via raw `DB::table`, tanpa model/relasi Eloquent** | Tambah model + relasi |
| `tax_rate` (varian) | ❌ Tidak ada kolom | Migration |
| `channel_url` (online_status) | ❌ Tidak disimpan | Migration + `ChannelUrlBuilder` (§6.1) |
| `is_internal`, `sequence_item` (varian) | ❌ Tidak ada | Migration (dipakai) |
| `is_po` (item) | ⚠️ Hanya ada `order_type` enum | Derivasi, tanpa kolom baru |
| `sell_price` item-level | ⚠️ Hanya per-varian | Derivasi (min varian) |
| `item_name` / `is_bundle` / `is_consignment` per-varian | ⚠️ Hanya di level produk | Derivasi dari produk |
| Output formatter (Resource + endpoint) gaya `example-master.json` | ❌ Belum ada | `MasterItemResource` + endpoint |

**Inti pekerjaan:** (a) lengkapi kolom yang hilang, (b) buat model/relasi varian,
(c) buat Resource + endpoint baru yang merakit struktur master.

---

## 2. Pemetaan Field → Skema (acuan implementasi)

### 2.1 Item (top-level) ← `products`
| Field master | Sumber | Catatan |
|---|---|---|
| `item_group_id` | `products.id` (uuid) | UUID, bukan int |
| `item_name` | `products.name` | |
| `last_modified` | `products.updated_at` | ISO-8601 |
| `item_category_id` | `products.category_id` (uuid) | UUID |
| `is_consignment` | `products.is_consignment` | |
| `is_po` | `products.order_type === 'PREORDER'` | derivasi boolean |
| `sell_price` | `min(variants.sell_price)` | derivasi |
| `variations[]` | `product_variation_types` → `attribute.name` + distinct `variant_options.value` | butuh relasi |
| `total_variants` | `variants.count()` | derivasi |
| `thumbnail` | `product_media` is_primary (product-level) | |
| `variants[]` | lihat 2.2 | |
| `online_status[]` | lihat 2.3 | |

### 2.2 `variants[]` ← `product_variants`
| Field master | Sumber | Catatan |
|---|---|---|
| `item_group_id` / `item_id` | `product_id` / `id` (uuid) | |
| `item_code` | `variants.sku` | |
| `item_name` | fallback `product.name` | varian tak punya kolom name |
| `barcode` | `variants.barcode` | |
| `sell_price` | `variants.sell_price` | |
| `is_bundle` | `product.is_bundle` | derivasi dari produk |
| `is_consignment` | `product.is_consignment` | derivasi dari produk |
| `variation_values[]` | `variant_options` → `{label: attribute.name, value}` | butuh relasi |
| `thumbnail` | `product_media` where `variant_id` (fallback product thumbnail) | |
| `store_names[]` | `variant.channelMappings → channelMapping.channelShop.shop_name` | |
| **`tax_rate`** | kolom baru `variants.tax_rate` | **migration** |
| `is_internal` | kolom baru (opsional) | **migration** |
| `sequence_item` | kolom baru (opsional) | **migration** |

### 2.3 `online_status[]` ← `product_channel_mappings` + `channel_shops`
| Field master | Sumber | Catatan |
|---|---|---|
| `channel_id` | `channel_shops.channel_id` (uuid) | UUID, bukan kode numerik Jubelio |
| `channel_code` | `channels.code` | **tambahan** untuk usability |
| `channel_name` | `channels.name` | **tambahan** untuk usability |
| `store_id` | `channel_shops.id` (uuid) | |
| `store_name` | `channel_shops.shop_name` | |
| `shop_id` | `channel_shops.shop_id` (string eksternal) | |
| `channel_group_id` | `product_channel_mappings.external_product_id` | |
| `error_text` | `product_channel_mappings.error_message` | |
| **`channel_url`** | kolom baru `product_channel_mappings.channel_url` (fallback `ChannelUrlBuilder`) | **migration** |

---

## 3. Rencana Implementasi (per fase)

### Fase 0 — Keputusan desain (lihat §6 Open Questions)
Tetapkan: apakah `is_internal`/`sequence_item` dipakai; apakah `channel_url`
disimpan atau dibangun dari template per-channel; apakah ID tetap UUID di output.

### Fase 1 — Migration (kolom yang hilang)
1. `add_master_fields_to_product_variants_table`
   - `decimal('tax_rate', 5, 2)->default(0)->after('sell_price')`
   - `boolean('is_internal')->nullable()->after('is_active')`
   - `integer('sequence_item')->nullable()->after('is_internal')`
2. `add_channel_url_to_product_channel_mappings_table`
   - `string('channel_url')->nullable()->after('external_product_id')`

> Catatan: jangan tambah kolom yang bisa diturunkan (`is_po`, `sell_price`
> item-level, `total_variants`, per-varian `is_bundle/is_consignment`).

### Fase 2 — Model & Relasi Eloquent (yang belum ada)
1. Buat `Models/ProductVariationType.php` (table `product_variation_types`,
   `belongsTo Attribute`, `belongsTo Product`).
2. Buat `Models/VariantOption.php` (table `variant_options`,
   `belongsTo Attribute`, `belongsTo ProductVariant`).
3. `Product`: tambah `variationTypes(): HasMany`.
4. `ProductVariant`: tambah `options(): HasMany` (variant_options) dan
   pastikan `channelMappings()` ada (sudah ada).
5. Update `$casts` varian untuk `tax_rate` (decimal/float), `is_internal` (bool).

> Pertimbangkan refactor `ProductService` (baris ~173–210) agar penulisan
> `product_variation_types`/`variant_options` lewat model — **opsional**, boleh
> tahap berikutnya supaya scope tetap fokus.

### Fase 3 — Output Layer
1. `Http/Resources/MasterItemResource.php` — merakit struktur item master
   (lihat §2). Bentuk `variations[]`, `variants[]`, `online_status[]`.
2. (Opsional) `MasterVariantResource` & `MasterOnlineStatusResource` untuk
   keterbacaan, atau inline di satu resource.
3. Helper di Resource:
   - `is_po` ← `order_type === 'PREORDER'`
   - `sell_price` item ← min varian
   - `variations` ← group `variationTypes` + distinct value dari options
   - `thumbnail` varian ← media per `variant_id`, fallback produk.

### Fase 4 — Service & Query
1. `MasterFeedService::list()` (atau method di `ProductService`):
   - Filter default `status = 'master'` (produk yang sudah diverifikasi).
   - Dukung `q`, `page`, `limit`, `updated_since` (untuk incremental sync).
   - **Eager load** untuk hindari N+1:
     ```php
     Product::query()
       ->with([
         'category',
         'variationTypes.attribute',
         'variants.options.attribute',
         'variants.channelMappings.channelMapping.channelShop.channel',
         'media',
         'channelMappings.channelShop.channel',
       ])
       ->where('status', 'master')
     ```
2. Pagination memakai `ApiResponse` meta yang sudah dipakai modul (lihat
   `ProductMergeController::catalog`).

### Fase 5 — Endpoint & Permission
1. Route: `GET /v1/products/master` (daftar) + `GET /v1/products/master/{id}`
   (detail) di `routes/api.php`, **didefinisikan sebelum** `apiResource('products')`
   agar `master` tidak tertangkap `products/{id}`.
2. Controller: `MasterFeedController` (atau method di `ProductController`).
3. Permission: pakai gate Spatie konsisten modul (mis. `view-product` atau
   permission baru `view-product-master`; daftarkan di `ProductPermissionSeeder`).

### Fase 6 — Tests
1. Migration test: kolom baru ada.
2. Feature test endpoint: struktur JSON cocok dengan kunci `example-master.json`
   (item, variants, variations, online_status, total_variants).
3. Test derivasi: `is_po`, `sell_price` min, `variation_values`.
4. Test N+1 (assert jumlah query stabil saat data bertambah).

---

## 4. Daftar Artefak (checklist)

**Migration**
- [ ] `..._add_tax_rate_to_product_variants_table.php`
- [ ] `..._add_channel_url_to_product_channel_mappings_table.php`
- [ ] (opsional) `..._add_master_fields_to_product_variants_table.php`

**Model**
- [ ] `Models/ProductVariationType.php`
- [ ] `Models/VariantOption.php`
- [ ] `Product::variationTypes()`
- [ ] `ProductVariant::options()`, `$casts` update

**Output**
- [ ] `Http/Resources/MasterItemResource.php`
- [ ] `Support/ChannelUrlBuilder.php` (fallback URL deterministik)
- [ ] (opsional) resource pendukung

**Service/Controller/Route**
- [ ] `Services/MasterFeedService.php`
- [ ] `Http/Controllers/MasterFeedController.php`
- [ ] Route `GET /v1/products/master[/{id}]`
- [ ] Permission + seeder

**Tests**
- [ ] Feature test kontrak JSON
- [ ] Unit test derivasi
- [ ] N+1 guard

---

## 5. Edge Cases & Catatan

- **Produk tanpa varian** → `variants: []`, `total_variants: 0`, `sell_price: 0/null`.
- **Varian tanpa mapping channel** → `store_names: []`; produk tanpa mapping →
  `online_status: []`.
- **`channel_url` kosong** (belum pernah sync) → `null`, jangan error.
- **Konsistensi tipe**: di Jubelio `barcode`/`channel_group_id` campur string &
  number. Tetapkan **satu tipe** (disarankan string) agar konsumen tidak pecah.
- **UUID vs int**: konsumen lama yang mengharap ID numerik perlu adaptasi —
  dokumentasikan sebagai breaking change yang disengaja.
- **Filter status**: master feed sebaiknya hanya keluarkan `status = 'master'`
  (bukan `download`/`in_review`/`archived`), kecuali ada parameter eksplisit.
- **Incremental sync**: sediakan `updated_since` agar konsumen bisa narik delta,
  bukan full-scan tiap kali.

---

## 6. Keputusan Final (sudah ditetapkan)

1. **`channel_url` → SIMPAN kolom** di `product_channel_mappings`, diisi saat
   sync/download. Tambahan: helper `ChannelUrlBuilder` mengisi otomatis untuk
   channel deterministik bila kolom kosong (lihat §6.1).
2. **`is_internal` & `sequence_item` → PAKAI.** Tambah kolom di `product_variants`
   (jadi **bukan** opsional lagi — masuk scope migration inti).
3. **`channel_id` → UUID** (`channels.id`) **+ sertakan `channel_code` &
   `channel_name`** di tiap `online_status[]` agar platform tetap mudah dikenali
   tanpa hafal UUID (lihat §6.2).
4. **Envelope → `ApiResponse` standar modul** (`successResponse($data, $msg, 200,
   $meta)`), konsisten dengan `ProductMergeController::catalog`. **Bukan**
   `{ data, totalCount }`.
5. **Scope refactor variation → tahap berikutnya.** Untuk fitur ini cukup tambah
   model + relasi **baca**; penulisan di `ProductService` tetap apa adanya dulu.

### 6.1 Cara membangun `channel_url`

`channel_url` tidak bisa 100% direkonstruksi dari template karena mengandung slug
& id sekunder dari marketplace. Karena itu **kolom = sumber kebenaran**, diisi
ketika produk di-sync/download. Untuk channel yang URL-nya deterministik,
`ChannelUrlBuilder::build($channelCode, $mapping, $shop, $variant = null)`
mengisi fallback bila kolom kosong:

| Channel (`channels.code`) | Template | Sumber data |
|---|---|---|
| `tiktok` | `https://shop.tiktok.com/view/product/{external_product_id}` | `product_channel_mappings.external_product_id` |
| `shopee` | `https://shopee.co.id/product/{shop_id}/{item_id}` | `channel_shops.shop_id` + `external_product_id` |
| `lazada` | `https://www.lazada.co.id/products/-i{item_id}-s{sku_id}.html` | `external_product_id` + (sku_id per-varian, kalau ada) — kalau tak lengkap, pakai nilai tersimpan |
| `tokopedia`/lainnya | — (butuh slug) | **wajib** ambil dari kolom tersimpan |

Aturan resolusi di Resource: `channel_url = mapping.channel_url ?? ChannelUrlBuilder::build(...) ?? null`.

### 6.2 Bentuk `online_status[]` (final)

```jsonc
{
  "channel_id":        "<uuid channels.id>",   // identitas platform
  "channel_code":      "tokopedia",            // tambahan untuk usability
  "channel_name":      "Tokopedia",            // tambahan untuk usability
  "store_id":          "<uuid channel_shops.id>",
  "store_name":        "Shop | Tokopedia - Cilupbah ID Mall",
  "shop_id":           "<channel_shops.shop_id eksternal>",
  "channel_group_id":  "<external_product_id>",
  "channel_url":       "https://...",
  "error_text":        null
}
```

---

## 6.3 Keputusan Tax (verifikasi vs OpenAPI Jubelio)

Hasil cek `dist (2).yaml` / `dist (3).yaml` (OpenAPI "Jubelio API Reference",
isi hampir identik; `(2)` sedikit lebih lengkap). Tax dimodelkan **2 lapis**:

**A. Tax Master** (`systemsettingTaxesResponse`): daftar pajak yang bisa dipilih.
- `tax_id` (mis. `-1` = PPN, `1` = No PPN), `rate` (`"10.00"`), `tax_name` (`PPN`),
  `tax_in`/`tax_out` (akun akuntansi: PPN Masukan / Pengeluaran).

**B. Tax di level item/varian** (dipakai endpoint item-master & produk):
- **`tax_rate`** (number persen, contoh `10`) — **inilah** yang ada di
  `example-master.json`.
- `sell_tax_id` / `buy_tax_id` (referensi ke tax master, default PPN=-1, NoPPN=1).
- `is_tax_included` (boolean — harga sudah termasuk pajak atau belum).
- Turunan di transaksi/order: `tax_amount`, `total_tax`, `tax_name`.

### Keputusan untuk scope ini → **Opsi 1 (flat `tax_rate`)**

| Opsi | Yang disimpan | Status |
|---|---|---|
| **1. Flat** ✅ dipakai sekarang | `product_variants.tax_rate decimal(5,2)` (persen) | Cukup untuk menyamai output master feed `example-master.json` |
| **2. Tax Master** ⏳ fase lanjutan | Tabel `taxes` + `sell_tax_id`/`buy_tax_id` + `is_tax_included` di varian | Hanya bila modul **akuntansi/faktur PPN** dibangun (beda pajak beli/jual, harga inklusif, akun PPN in/out) |

Alasan: master feed hanya butuh `tax_rate` persen. Tax master penuh = over-engineering
untuk scope ini; **disiapkan terpisah** saat modul akuntansi/invoice direncanakan.
Migration Fase 1 cukup menambah kolom `tax_rate` (sudah tercantum di §3 Fase 1).

> **Catatan fase lanjutan (Opsi 2)** bila diperlukan: buat tabel `taxes`
> (`id`, `rate`, `name`, `is_default`, akun in/out), tambah `sell_tax_id`,
> `buy_tax_id`, `is_tax_included` di `product_variants`, dan `tax_rate` di master
> feed menjadi turunan dari `sell_tax.rate`.

---

## 7. Urutan Eksekusi yang Disarankan

```
Fase 0 (keputusan) → Fase 1 (migration) → Fase 2 (model/relasi)
→ Fase 3 (resource) → Fase 4 (service) → Fase 5 (route/permission)
→ Fase 6 (tests)
```

Estimasi: setelah Fase 0 disepakati, Fase 1–5 adalah pekerjaan inti; Fase 6
menyusul untuk mengunci kontrak.
