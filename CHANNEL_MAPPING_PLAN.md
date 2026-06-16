# Channel Mapping & Variant Sync — Plan Perbaikan

Status awal: arsitektur mapping **relational** sudah ada & benar. Dokumen ini menutup
5 gap yang teridentifikasi, urut berdasarkan dampak/risiko. Setiap fase berdiri sendiri,
bisa di-commit & di-push terpisah.

Filosofi yang dipertahankan: **validasi lokal sebelum kirim** (no-500), **soft-deprecate
jangan hard-delete**, **kanonik internal tidak tahu bentuk marketplace** (terjemahan hanya
di Adapter/Mapper).

---

## Peta skema saat ini (acuan, sudah diverifikasi)

Kanonik (internal):
- `categories(id, parent_id, name, is_active)` — 3 level via kedalaman parent_id
- `attributes(id, name, type)` + `attribute_options(attribute_id, value)`
- `category_attributes(category_id, attribute_id, is_required)`
- `product_variants(...)`, `product_variation_types(product_id, attribute_id, sort_order)`,
  `variant_options(variant_id, attribute_id, value)`

Proyeksi channel (anti-corruption layer):
- `channel_categories(channel_id, external_id, parent_external_id, name, is_leaf)`
- `category_channel_mappings(category_id, channel_category_id)`
- `channel_attributes(channel_category_id, external_id, name, is_required, is_multiple)`
- `attribute_channel_mappings(attribute_id, channel_attribute_id)`
- `channel_attribute_options(channel_attribute_id, external_id, name)`

Sudah jalan: `LazadaProductService::syncCategoryTree`, `LazadaAdapter::pushProduct`,
`LazadaProductMapper`, push endpoint, webhook + polling status review.

---

## FASE 1 — Enforcement "maks. 2 jenis varian" (gap Q4) ✅ SELESAI

Diimplementasikan: FormRequest (`max:2` + `distinct`) di Create & Update; unique DB
`uniq_pvt_product_attribute` + `uniq_vo_variant_attribute` (migrasi dgn dedup data lama);
service guard `ProductService::assertVariationConstraints` (lempar `DomainException`)
+ `store()` tangkap `DomainException` → 422 (sekaligus menambal bug laten 500).
Test `ProductVariationConstraintTest` 5/5 hijau; suite Product 192/192 hijau.



**Masalah:** tidak di-enforce di lapis manapun.
- `CreateProductRequest:65` → `variation_types => nullable|array` (tanpa `max:2`)
- `product_variation_types` → tidak ada unique `(product_id, attribute_id)`
- `ProductService:364` → langsung insert tanpa cek jumlah

**Pekerjaan (3 lapis):**

1. FormRequest — `CreateProductRequest` + `UpdateProductRequest`:
   ```php
   'variation_types' => 'nullable|array|max:2',
   'variation_types.*.attribute_id' => 'required|integer|distinct|exists:attributes,id',
   ```
2. Migration baru `..._add_unique_to_product_variation_types`:
   - `unique(['product_id','attribute_id'])` di `product_variation_types`
   - `unique(['variant_id','attribute_id'])` di `variant_options`
   - (cek dulu data lama tidak melanggar sebelum apply; kalau ada, bersihkan/laporkan)
3. `ProductService` (createProduct + updateProduct), invariant domain:
   - `count(variation_types) > 2` → `ValidationException` (422), pesan ID
   - setiap `variant_options.attribute_id` HARUS ⊆ `variation_types` yang dideklarasikan
   - kombinasi varian wajib unik (tidak boleh 2 varian Merah/L)

**Catatan DB:** "maks 2 *distinct* attribute_id per produk" tidak bisa jadi CHECK
constraint sederhana di Postgres → enforcement sejati di FormRequest + Service; unique
di atas hanya jaring anti-duplikat-dimensi. Trigger DB = overkill, tidak dipakai.

**Test:** `ProductVariationConstraintTest`
- 3 variation_types → 422
- 2 dimensi attribute_id sama (duplikat) → 422
- variant pakai attribute_id di luar variation_types → 422
- 2 varian kombinasi identik → 422
- happy path 2 dimensi valid → 201

**Selesai bila:** test hijau + suite Product hijau + tidak ada regresi create/update.

---

## FASE 2 — Sinkron `channel_attributes` per kategori ✅ SELESAI

Diimplementasikan: `LazadaProductService::syncCategoryAttributes($shopId, $categoryExtId)`
(GET `/category/attributes/get` → upsert `channel_attributes` {external_id=name,
name=label, is_required=is_mandatory, is_multiple=input_type∈{multiSelect,...}} +
`channel_attribute_options`) & `syncAllMappedCategoryAttributes` (loop semua leaf
yang dipetakan). Endpoint `POST /v1/lazada/sync/category-attributes` + command
`lazada:sync-category-attributes {shop_id} {category_id?}`. Test
`LazadaSyncCategoryAttributesTest` 3/3 (persist+options, idempoten, kategori belum
sinkron→422); Channel 91/91 hijau.

> Perlu validasi LIVE: nama field response `/category/attributes/get` (mapping
> is_mandatory/input_type/options) — saat ini berdasarkan dokumentasi, defensif.



**Masalah:** `syncCategoryTree` hanya menarik pohon kategori; **spec atribut per leaf
belum ditarik**, jadi pre-flight validator (Fase 3) belum punya data acuan.

**Pekerjaan:**
1. `LazadaProductService::syncCategoryAttributes(string $shopId, string $categoryExtId): int`
   - GET endpoint atribut kategori Lazada (`/category/attributes/get`)
   - upsert `channel_attributes` (channel_category_id, external_id, name, is_required, is_multiple)
   - upsert `channel_attribute_options` untuk atribut bernilai tertutup
2. Command `lazada:sync-category-attributes {shop_id} {category_id?}`
   - tanpa category_id → loop semua leaf yang sudah dipetakan di `category_channel_mappings`
3. (TikTok paritas, opsional) `TikTokProductService::syncCategoryAttributes` —
   tunda sampai dibutuhkan.

**Test:** `LazadaSyncCategoryAttributesTest` (Http::fake) → channel_attributes +
channel_attribute_options terisi, idempoten saat re-run.

**Selesai bila:** untuk 1 kategori yang dipetakan, `channel_attributes` terisi lengkap
dengan flag `is_required`.

---

## FASE 3 — Pre-flight `ChannelListingValidator` (gap Q2) 🟠

**Masalah:** kegagalan baru ketahuan setelah ditolak marketplace. Validasi harus lokal.

**Pekerjaan:**
1. `Modules/Channel/app/Services/ChannelListingValidator.php`
   - input: `Product` + channel code (+ shop)
   - resolve channel category (Fase 1 mapping) → ambil `channel_attributes` required
   - untuk tiap required: cek produk punya nilai (via attribute_channel_mappings →
     variant_options/specifications); cek nilai tertutup sudah ada padanan di
     `channel_attribute_options`
   - return daftar error terstruktur (kosong = lolos)
2. Integrasi:
   - panggil di `pushProduct` (LazadaSyncApiController) SEBELUM adapter → kalau ada
     error, balas 422 dgn daftar error (bukan kirim lalu ditolak)
   - panggil sebelum `SyncProductToChannelJob` di-dispatch
3. Endpoint opsional `POST /v1/{channel}/listing/validate` untuk FE menampilkan
   checklist kesiapan produk sebelum user klik "Naikkan".

**Test:** `ChannelListingValidatorTest`
- atribut wajib kosong → error
- nilai tertutup belum dipetakan → error
- kategori belum dipetakan → error
- semua lengkap → lolos

**Selesai bila:** push produk yang belum lengkap → 422 dgn alasan jelas, TANPA memanggil
API marketplace.

---

## FASE 4 — Value mapping varian di Mapper (gap Q3) 🟡

**Masalah:** atribut varian bernilai tertutup (mis. Warna) dikirim apa adanya; harus
dipetakan ke `channel_attribute_options.external_id`.

**Pekerjaan:**
1. `LazadaProductMapper` — saat membangun `Skus[].SaleProp`, resolve nilai internal →
   `channel_attribute_options` (fallback pass-through utk free-text seperti Ukuran)
2. Helper bersama `resolveChannelOptionValue($attributeId, $value, $channelCategory)`
   agar dipakai ulang TikTok/Shopee nanti
3. Pastikan ≤2 dimensi kanonik termap benar ke format channel (Lazada SaleProp,
   Shopee tier_variation, TikTok sales_attributes)

**Test:** `LazadaVariantMappingTest` — varian Warna(tertutup)+Ukuran(bebas) →
payload SaleProp benar (Warna pakai external_id, Ukuran pass-through).

**Selesai bila:** payload varian membawa external_id untuk nilai tertutup.

---

## FASE 5 — Edge case kategori berubah/hilang (gap Q5) 🟡

**Pekerjaan:**
1. Migration: tambah `deprecated_at` di `channel_categories`; `is_stale` +
   `last_verified_at` di `category_channel_mappings`
2. `syncCategoryTree` revisi: kategori yang hilang dari response → set `deprecated_at`
   (JANGAN delete); mapping yang menunjuk ke sana → `is_stale=true`
3. Guard di pre-flight (Fase 3): kategori deprecated/belum-dipetakan → blokir + pesan
4. Inbound pull: kategori marketplace tanpa padanan internal → kategori default
   "Belum Dikategorikan" + flag `needs_categorization` (jangan buang data)

**Test:** `CategorySyncEdgeCaseTest`
- kategori hilang saat re-sync → deprecated_at terisi, mapping is_stale, baris tidak terhapus
- push ke kategori deprecated → 422
- pull produk kategori tak dikenal → masuk "Belum Dikategorikan", tidak hilang

**Selesai bila:** re-sync tidak pernah memutus mapping; push selalu ke kategori valid.

---

## Urutan eksekusi & dependensi

```
Fase 1 (mandiri, mulai sekarang)
Fase 2 ──► Fase 3 ──► Fase 4
Fase 5 (mandiri, bisa paralel; sentuh syncCategoryTree → idealnya setelah Fase 2)
```

Tiap fase: implement → test → `php artisan test Modules/...` hijau → commit (pesan ID,
`Co-Authored-By: Claude Opus 4.8`) → push. Lalu lanjut fase berikutnya.

## Catatan validasi LIVE (di luar scope kode, butuh Anda)
- Format payload Lazada `/product/create`: **JSON vs XML** — konfirmasi dari respons live
- Endpoint & bentuk response `/category/attributes/get` Lazada (untuk Fase 2)
- Daftar atribut wajib aktual per kategori target (untuk uji Fase 3)
