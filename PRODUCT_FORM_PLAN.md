# Product Form — Plan Perbaikan & API Spesifikasi/Varian per Kategori

Menutup gap form produk (FE create/edit) + menyediakan metadata field per kategori
Level-2, meniru kontrak referensi datascrip. Tiap fase berdiri sendiri & bisa
di-commit terpisah. Pola dipertahankan: validasi lokal, no-500, test per fase.

## Konteks & temuan (sudah diverifikasi di kode)

- `attributes(id, name, type[enum: sales|spec])` + `attribute_options`. **Tidak punya**
  `unit, as_variant, affect_sku, affect_price, order, is_visible, omnichannel_id, deleted_at`.
- `category_attributes(category_id, attribute_id, is_required)` — penghubung kategori↔atribut (ada).
- Dimensi (Berat/Panjang/Lebar/Tinggi) saat ini = kolom struktural `products.weight/length/width/height`.
- **`updateProduct` MENGABAIKAN**: `variation_types`, opsi varian (`variant_options`), dan
  `specifications` (cuma create yang menyimpan). → edit varian/spec tidak tersimpan.
- `UpdateProductRequest` tak punya rule `variants.*.options` / `specifications`.
- Enforcement maks-2 varian (Fase 1 lalu) hanya jalan di `createProduct`.

Mapping ke kontrak referensi: `type:"static"` = spesifikasi (`as_variant=false`),
`type:"variant"` = jenis varian (`as_variant=true`).

---

## FASE A — Perkaya `attributes` + katalog per kategori 🔴 fondasi

**Migration** `..._enrich_attributes_catalog`:
- Tambah ke `attributes`:
  - `unit` string nullable (mis. "gram", "cm")
  - `as_variant` boolean default false
  - `affect_sku` boolean default false
  - `affect_price` boolean default false
  - `order` integer default 0
  - `is_visible` boolean default true
  - `omnichannel_id` uuid/bigint nullable (relasi ke channel attribute; isi nanti)
  - `softDeletes()` → `deleted_at`
- Backfill: `as_variant = (type = 'sales')`. Set `unit` & `order` untuk dimensi via seeder.

**Model `Attribute`**: tambah `SoftDeletes`, fillable + cast kolom baru.

**Seeder/normalisasi** (idempoten):
- Pastikan ada atribut: Berat(unit=gram, affect_sku, affect_price), Panjang/Lebar/Tinggi
  (unit=cm) → `type=spec, as_variant=false`.
- Warna, Ukuran, Variasi → `type=sales, as_variant=true`.
- Hubungkan ke kategori Level-2 via `category_attributes` (atau perlakukan sebagai
  global bila belum dipetakan — lihat catatan endpoint).

**Selesai bila**: kolom ada, data lama ter-backfill, `attributes` carry flag lengkap.

---

## FASE B — Endpoint metadata per kategori (kontrak referensi) 🔴

Dua endpoint read-only yang dipakai FE saat memilih kategori Level-2.

**Route** (sanctum):
```
GET /api/v1/products/product-specifications?category_id={id}   # spesifikasi (static)
GET /api/v1/products/product-variants?category_id={id}         # jenis varian (variant)
```
> Path bisa diselaraskan dengan proxy FE (`/api/app/seller/products/...`). Yang penting
> bentuk respons identik referensi.

**Controller** `ProductAttributeCatalogController`:
- validasi `category_id` (required, exists categories).
- specifications: atribut applicable ke kategori dengan `as_variant=false`, `is_visible=true`, urut `order`.
- variants: idem dengan `as_variant=true`.
- "applicable": JOIN `category_attributes` by `category_id`; bila kategori belum punya
  pemetaan, fallback ke atribut global (kebijakan: tampilkan default dimensi/varian standar).

**Resource** `ProductAttributeResource` — output PERSIS referensi:
```json
{
  "id": 25, "name": "Berat", "unit": "gram",
  "type": "static",            // as_variant ? "variant" : "static"
  "as_variant": false, "affect_sku": true, "affect_price": true,
  "order": 25, "omnichannel_id": null, "is_visible": true,
  "deleted_at": null,
  "created_at": "...", "updated_at": "..."
}
```

**Test** `ProductAttributeCatalogTest`:
- spec endpoint → hanya `as_variant=false`, urut `order`, field lengkap & tipe benar.
- variant endpoint → hanya `as_variant=true`.
- `category_id` tak ada → 422; kategori tanpa atribut → fallback/empty sesuai kebijakan.

**Selesai bila**: kedua endpoint mengembalikan bentuk identik referensi untuk `category_id` Level-2.

---

## FASE C — `updateProduct` simpan `specifications` (edit) 🟠

**Masalah**: edit produk tidak menyimpan `specifications[]` (hanya create).

- `updateProduct`: bila `array_key_exists('specifications', $data)` → upsert
  `product_specifications` (replace set milik produk: delete lalu insert, ATAU upsert per attribute_id).
- Dimensi (Berat/Panjang/Lebar/Tinggi) tetap ke kolom `products.weight/length/width/height`
  (sudah jalan di update) — endpoint katalog hanya metadata, nilai tetap ke kolom.
- `UpdateProductRequest`: tambah rule `specifications`, `specifications.*.attribute_id`,
  `specifications.*.attribute_option_id`, `specifications.*.text_value`.

**Test**: edit menambah/mengubah spesifikasi → tersimpan; spesifikasi lama tidak hilang bila tak dikirim (kebijakan upsert).

---

## FASE D — `updateProduct` varian + opsi dengan IMMUTABILITY 🟠 inti permintaan

**Aturan bisnis (dari Anda)**: setelah disimpan, **jenis varian & opsi tidak boleh dihapus**;
saat edit hanya boleh **menambah opsi** (yang melahirkan kombinasi/varian baru). Maks 2 jenis.

**`UpdateProductRequest`**: tambah `variants.*.options`, `variants.*.options.*.attribute_id`,
`variants.*.options.*.value` (variation_types sudah ada dari Fase 1).

**`ProductService::updateProduct`**:
1. Muat state lama: `product_variation_types` + `variant_options` per varian.
2. **Guard immutability**:
   - `variation_types` payload harus **superset** dari yang sudah ada → bila ada jenis lama
     hilang → `DomainException` "Jenis varian tidak boleh dihapus." (422)
   - Setiap (attribute_id,value) opsi lama harus tetap ada → bila hilang → `DomainException`
     "Opsi varian tidak boleh dihapus." (422)
3. **Tambah**: insert `variation_types` baru (≤ maks 2), insert varian baru untuk kombinasi
   baru (+ `variant_options`), insert nilai opsi baru.
4. `assertVariationConstraints` pada gabungan (maks 2 jenis, kombinasi unik) — reuse Fase 1.
5. Tetap upsert harga/stok varian existing (sudah jalan).

**Catatan**: enforcement immutability di BE = defense-in-depth atas FE (yang sudah
men-disable tombol hapus). Keduanya selaras.

**Test** `ProductVariantEditTest`:
- tambah nilai opsi → varian kombinasi baru terbentuk, opsi lama utuh.
- hapus jenis varian lama → 422.
- hapus opsi lama → 422.
- lebih dari 2 jenis saat edit → 422.

---

## FASE E — Penyelarasan FE (di repo cilupbah-fe) ⬜

- Service `productAttributeService`: `getSpecifications(categoryId)`, `getVariantTypes(categoryId)`
  → konsumsi Fase B; render section Spesifikasi & "Pilih Jenis Varian" dinamis per kategori.
- Form edit: kirim `specifications[]` + `variants[].options[]`; disable hapus jenis/opsi lama
  (sudah sesuai aturan), izinkan tambah opsi.
- Tangani 422 immutability dengan pesan jelas.

---

## Urutan & dependensi
```
A (skema+katalog)  ──►  B (endpoint metadata)  ──►  E (FE)
C (edit spec)        ┐
D (edit varian)      ┴► relatif independen dari A/B, bisa paralel
```
Rekomendasi: **A → B → D → C → E**. (D = inti permintaan varian; C kecil.)

## Kontrak respons (acuan, harus identik)
`GET /product-specifications?category_id=193` & `/product-variants?category_id=193`:
field `id,name,unit,type,as_variant,affect_sku,affect_price,order,omnichannel_id,
is_visible,deleted_at,created_at,updated_at`. `type` = `as_variant?"variant":"static"`.

## Catatan terbuka
- `omnichannel_id`: sementara `null`; nanti diisi dari pemetaan `attribute_channel_mappings`
  bila perlu sinkron metadata channel.
- **Kebijakan applicable (DIPUTUSKAN): per-kategori via `category_attributes` + fallback global.**
  Tampilkan atribut yang dipetakan ke kategori; bila kategori belum punya pemetaan,
  fallback ke set standar (dimensi Berat/Panjang/Lebar/Tinggi + Warna/Ukuran/Variasi).
