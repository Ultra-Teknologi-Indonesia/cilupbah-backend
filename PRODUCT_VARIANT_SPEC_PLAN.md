# Plan — Spesifikasi & Varian per-Kategori (Level-2) + Edit Immutability + Omnichannel

Tujuan: spesifikasi & jenis varian **digerakkan oleh kategori Level-2**, dengan
mapping akurat ke marketplace (omnichannel), dan **edit varian** yang aman
(immutability + ekspansi kombinasi). Mencakup BE **dan** FE.

Filosofi dipertahankan: validasi lokal sebelum kirim (no-500), soft-deprecate (jangan
hard-delete), kanonik internal tak tahu bentuk marketplace (terjemah hanya di mapper).

---

## 0. Kondisi saat ini (terverifikasi)

| Hal | Status nyata |
|---|---|
| `category_attributes` (kategori → atribut) | **KOSONG** (0 baris); `attributes`/`attribute_options` juga 0 |
| Endpoint atribut per-kategori (internal) | **Tidak ada** (hanya channel: `ChannelAttributeController`) |
| `AttributeController::index` filter `type` | **Tidak ada** (hanya all/paginate) |
| `createProduct` simpan `specifications`/`variation_types`/`options` | ✅ ada (+ enforcement maks-2, Fase 1) |
| `updateProduct` simpan `variation_types`/`options`/`specifications` | ❌ **Diabaikan total** (hanya field produk, media, channel_prices) |
| `UpdateProductRequest` rule `variants.*.options` | ❌ tidak ada |
| Omnichannel mapping (atribut→channel) | ✅ infra ada (`attribute_channel_mappings`, validator Fase 3, value-map Fase 4) |

Model kanonik (acuan):
`categories(id,parent_id,name)` (3 level) · `attributes(id,name,type[sales|spec])` ·
`attribute_options(attribute_id,value)` · `category_attributes(category_id,attribute_id,is_required)` ·
`product_specifications(product_id,attribute_id,attribute_option_id,text_value)` ·
`product_variation_types(product_id,attribute_id,sort_order)` (≤2) ·
`product_variants(id,product_id,sku,…,is_active)` · `variant_options(variant_id,attribute_id,value)`.
Channel: `category_channel_mappings` · `channel_attributes` · `attribute_channel_mappings` · `channel_attribute_options`.

---

## FASE A — Fondasi data: `category_attributes` + mapping omnichannel ✅ SELESAI

Diimplementasikan: migration `channel_attributes.is_sale_prop` (+ `syncCategoryAttributes` simpan
is_sale_prop); `CategoryAttributeSyncService::materializeFromChannels` — turunkan atribut internal
dari channel: sale-prop→`sales`(jenis varian), selain→`spec`(spesifikasi); kecualikan
SYSTEM_ATTRIBUTES; hormati pemetaan kurasi yang ada (tak duplikat); is_required = OR antar channel;
materialkan `attribute_options` dari opsi channel; idempoten. Command
`categories:materialize-attributes {category_id?}`. `ChannelListingValidator::SYSTEM_ATTRIBUTES`
dipublikkan (single-source). Test `CategoryAttributeMaterializeTest` 3/3 (sales/spec+skip system,
idempoten, hormati kurasi); Product 199/199, Channel 131/131 hijau.



**Masalah:** tabel kosong → form dinamis tak punya sumber. Dan harus **akurat ke marketplace**.

**Pendekatan (direkomendasikan): derive dari channel (omnichannel-driven).**
Saat kategori internal Level-2 dipetakan ke kategori channel (`category_channel_mappings`),
materialkan `category_attributes` dari **gabungan atribut channel** yang relevan:

1. `attributes` internal dibuat/di-reuse dari `channel_attributes.name` (mis. `brand`, `material`,
   `color_family`→Warna, dll), `type` = `sales` bila `is_sale_prop`, selain itu `spec`.
2. `attribute_options` internal dari `channel_attribute_options` (untuk nilai tertutup).
3. `category_attributes(category_id, attribute_id, is_required)` dari `channel_attributes.is_required`
   (union antar channel; **kecualikan SYSTEM_ATTRIBUTES** Fase 3: price/SellerSku/package_*/quantity).
4. `attribute_channel_mappings` + `channel_attribute_options` otomatis tersambung → akurasi terjaga.

Komponen:
- `Modules/Product/app/Services/CategoryAttributeSyncService::materializeFromChannels(int $categoryId): array`
- Command `categories:materialize-attributes {category_id?}` (semua kategori terpetakan bila kosong).
- Alternatif manual (fallback): seeder/CRUD `category_attributes` bila kategori belum punya channel map.

**Selesai bila:** untuk kategori Level-2 terpetakan, `category_attributes` terisi + tiap atribut
punya `attribute_channel_mappings` (tervalidasi: tak ada atribut wajib tanpa mapping).

**Test:** materialize dari 1 channel_category (fake) → category_attributes + options + mapping terisi; idempoten.

---

## FASE B — Endpoint atribut per-kategori (Level-2) ✅ SELESAI

Diimplementasikan: `CategoryFormAttributeController::show` → `GET /api/v1/categories/{id}/form-attributes`
mengembalikan `specifications` (type=spec) + `variant_types` (type=sales) dari `category_attributes`,
tiap atribut: `attribute_id`, `name`, `is_required`, `options`, dan `channels{code:{mapped,required}}`
(dari `attribute_channel_mappings`→`channel_attributes`→`channels`). Non-leaf (punya sub-kategori)→422;
tidak ada→404. Test `CategoryFormAttributeTest` 3/3; Product 202/202 hijau.



FE butuh: pilih kategori Level-2 → tahu **spesifikasi** & **jenis varian** yang berlaku.

1. `GET /api/v1/categories/{categoryId}/form-attributes` → 
   ```json
   {
     "specifications": [ {attribute_id, name, input_type, is_required, options:[{id,value}],
                          channels:{lazada:{required:true,mapped:true}}} ],
     "variant_types":  [ {attribute_id, name, options:[{id,value}],
                          channels:{lazada:{mapped:true}}} ]
   }
   ```
   - `specifications` = `category_attributes` type `spec` (kecuali SYSTEM_ATTRIBUTES).
   - `variant_types` = `category_attributes` type `sales`.
   - `channels{}` = ringkasan dari `attribute_channel_mappings`/`channel_attributes` (wajib/terpetakan
     per channel terhubung) → FE bisa tandai "wajib untuk Lazada".
2. Validasi: hanya kategori **leaf/Level-2** punya atribut; non-leaf → 422 "pilih kategori paling spesifik".
3. (Minor) `AttributeController::index` tambah filter `?type=sales|spec`.

Controller: `Modules/Product/app/Http/Controllers/CategoryFormAttributeController.php`.

**Selesai bila:** GET kategori Level-2 mengembalikan spec + variant_types lengkap dgn status channel.
**Test:** kategori leaf terpetakan → struktur benar; non-leaf → 422.

---

## FASE C — CREATE: validasi spesifikasi & varian terhadap kategori

`createProduct` sudah menyimpan; tambah **validasi keterkaitan kategori** + akurasi.

1. `specifications.*.attribute_id` & `variation_types.*.attribute_id` **harus** ada di
   `category_attributes` untuk `category_id` produk → else 422 ("atribut tak berlaku untuk kategori ini").
2. Atribut **spec wajib** (`is_required`) untuk kategori harus terisi (kecuali SYSTEM_ATTRIBUTES) → 422.
3. `variation_types` hanya boleh atribut `type=sales` dari kategori; tetap maks 2 (Fase 1).
4. Tegakkan di `CreateProductRequest` (rule) + `ProductService::assertCategoryAttributes()` (closure DB).

**Selesai bila:** create menolak spec/varian di luar `category_attributes`; produk valid lolos.
**Test:** atribut asing → 422; spec wajib kosong → 422; happy path → 201.

---

## FASE D — EDIT: immutability varian + ekspansi kombinasi (inti) ✅ SELESAI

Diimplementasikan: migration `product_variants.superseded_at`; `ProductService::syncVariantStructure`
(+ `ancestorPrice`/`generateVariantSku`/`variantUpdatableFields`/`syncSpecifications`) dipanggil
saat payload memuat `variation_types`; immutability jenis/nilai-opsi (DomainException→422),
ekspansi cartesian by option-set, soft-deprecate varian lama, warisi harga (default) saja.
`UpdateProductRequest` + rule `variants.*.options`/`specifications`. `ProductController::update`
kini `catch(DomainException)→422`. Test `ProductVariantEditExpansionTest` 4/4 (skenario IP17:
1→2 jenis = 4 varian, 2 lama supersede tak terhapus; hapus jenis/nilai→422; warisi harga);
Product 196/196 hijau.



**Aturan (dari Anda):** setelah disimpan, **jenis varian & nilai-opsi tidak boleh dihapus**, hanya
boleh **tambah**. Menambah jenis/nilai → **regenerasi kombinasi (cartesian)**.

### Skenario acuan (IP17)
- Create: 1 jenis **Warna** → varian `IP17-BLUE` (Warna=Blue), `IP17-RED` (Warna=Red).
- Edit: tambah jenis **Ukuran** {256/8, 512/16} → menjadi **4** varian:
  `IP17-BLUE-256`, `IP17-BLUE-512`, `IP17-RED-256`, `IP17-RED-512`.
- SKU lama `IP17-BLUE`/`IP17-RED` **hilang dari daftar aktif** tapi **TIDAK di-hard-delete**.

### Model immutability — di tingkat (jenis, nilai-opsi), BUKAN baris varian
- **Jenis varian**: boleh tambah (≤2 total); **tak boleh hapus** jenis lama.
- **Nilai opsi per jenis**: boleh tambah (Blue,Red → +Green); **tak boleh hapus** Blue/Red.
- **Baris `product_variants`**: di-**regenerasi** sebagai cartesian semua (jenis→nilai).
  Baris lama yang bukan kombinasi sah → **soft-deprecate** (`is_active=false`, simpan).

### Algoritma `updateProduct` (varian)
```
1. Muat existing: variation_types + nilai-opsi (dari variant_options varian aktif).
2. Immutability guard:
   - tiap jenis lama HARUS masih ada; tiap nilai-opsi lama HARUS masih ada (else 422 "tak boleh hapus").
   - total jenis ≤ 2; jenis ∈ category_attributes type=sales.
3. desired = cartesian(nilai-opsi per jenis, urut sort_order).   // Blue,Red × 256,512 = 4
4. untuk tiap kombinasi desired:
   - cari varian AKTIF dgn option-set sama → update (harga/stok/sku).
   - else cari "leluhur subset" (mis. Blue-only → Blue+256) → varian baru WARISI **HARGA**
     dari leluhur sebagai default (bisa diedit). TIDAK mewarisi stok (mulai 0) maupun
     channel-mapping (listing lama tak valid → di-regenerasi, lihat Fase E).
   - else create varian baru (SKU dari payload, atau generate base+suffix).
5. varian aktif yang option-set-nya BUKAN desired (mis. Blue-only, Red-only) →
   is_active=false + superseded_at=now (JANGAN delete → jaga channel-mapping & histori order).
6. upsert product_variation_types (tambah jenis baru; tak pernah hapus).
7. assertVariationConstraints (≤2, kombinasi unik) pada hasil.
8. trigger omnichannel sync (Fase E).
```

### Keputusan desain yang DITETAPKAN (dikonfirmasi)
- **Stok**: **TIDAK diwarisi → mulai 0**. Menyalin stok leluhur ke 256 & 512 = double-count →
  oversell + ledger salah. Stok varian lama **dibekukan** di baris supersede + **ditampilkan** ke
  user untuk diredistribusi via stock opname/penyesuaian.
- **Channel-mapping**: **TIDAK diwarisi**. Listing SKU lama tak valid → dideaktivasi; SKU baru push
  segar (Fase E). Mewarisi mapping = menunjuk listing usang.
- **Harga**: **diwarisi sebagai DEFAULT yang bisa diedit** (titik awal; 512 ≠ harga 256 → user sesuaikan).
- **SKU**: **FE kirim (otoritatif)**; BE sediakan saran `base + '-' + kodeOpsi` **ter-sanitasi**
  (nilai opsi bisa berisi `/`, mis. `256/8` → `256-8`) + validasi keunikan/format. Generate hanya fallback.
- **Soft-deprecate**: kolom baru `product_variants.superseded_at` (timestamp, nullable) — membedakan
  "digantikan ekspansi" dari "dinonaktifkan manual"; feed/daftar default filter `is_active=true`.

### Perubahan request/service
- `UpdateProductRequest`: tambah `variants.*.options.*`, `variation_types` (sudah ada), `specifications`.
- `ProductService::updateProduct`: implement algoritma di atas + `assertVariationConstraints` +
  immutability guard (DomainException → 422). Tangani `specifications` (upsert; spec tak diatur immutability).
- `ProductController` sudah `catch(DomainException)→422` (Fase 1).

**Migration:** `product_variants.superseded_at`.

**Selesai bila:** skenario IP17 menghasilkan tepat 4 varian aktif + 2 lama soft-deprecated; upaya hapus
jenis/nilai → 422; tambah nilai → ekspansi benar.

**Test (`ProductVariantEditExpansionTest`):**
- 1 jenis→2 jenis = 4 varian; 2 lama `is_active=false` + `superseded_at`, tidak terhapus.
- hapus jenis lama → 422; hapus nilai-opsi lama → 422.
- tambah nilai (Blue,Red→+Green, 2 ukuran) = 6 varian; leluhur warisi harga.
- spesifikasi bisa diedit.

---

## FASE E — Propagasi omnichannel saat varian berubah ✅ SELESAI

Diimplementasikan: `LazadaProductMapper` melewati varian `is_active=false` (supersede tak di-listing);
`ProductService::propagateVariantChangeToChannels` dispatch `SyncProductToChannelJob('update')` ke
tiap channel terhubung (skip `pending`) **setelah** transaksi commit (varian baru → update listing →
markInReview via job; SKU lama hilang dari payload). Value-mapping Fase 4 + pre-flight Fase 3 tetap
berlaku. Test `ProductVariantChannelPropagationTest` 3/3 (mapper skip supersede, dispatch update,
no-dispatch tanpa channel); Product 196/196, Channel 131/131 hijau.

> Catatan: filter varian aktif & dispatch saat ini fokus Lazada (channel terintegrasi). Paritas
> TikTok (filter is_active di payload-nya) bisa menyusul saat dibutuhkan.



Regenerasi varian harus tersinkron ke marketplace (pakai infra yang ada).

1. Varian **soft-deprecated** yang punya `ProductChannelMapping` → jadwalkan **deaktivasi/penghapusan**
   di channel (`AdapterFactory`→`deleteProduct`/`deactivateProduct`; catatan: Lazada tak dukung
   deactivate → pakai `/product/remove` SKU, atau biarkan + tandai). Mapping → `sync_status=deactivated`.
2. Varian **baru** → ikut `SyncProductToChannelJob('push'/'update')` → `markInReview` (Fase status review).
3. `variant_options` (Warna=Blue, Ukuran=256) → **SaleProp channel** via `LazadaProductMapper` (Fase 4):
   nilai tertutup → `channel_attribute_options.external_id`; pre-flight `ChannelListingValidator` (Fase 3)
   menjaga atribut wajib lengkap sebelum push.
4. Idempoten + tak 500 bila toko belum terhubung (skip dengan log).

**Selesai bila:** setelah edit-ekspansi, SKU lama ter-deaktivasi di channel & 4 SKU baru masuk antrean push (in_review).
**Test:** mock adapter → assert deactivate dipanggil utk SKU lama, push utk SKU baru.

---

## FASE F — Frontend (`cilupbah-fe`)

1. **Spesifikasi dinamis**: saat kategori Level-2 dipilih → `GET …/form-attributes` →
   render field `specifications` (input sesuai `input_type`, tandai wajib + "wajib untuk Lazada").
   Dimensi statis (Berat/Panjang/Lebar/Tinggi) tetap selalu tampil (struktural).
2. **Builder varian**: `variant_types` dari endpoint (bukan hardcode); maks 2 jenis; opsi >1.
3. **Immutability UX (edit)**: jenis & nilai-opsi tersimpan → **disable hapus** (hanya tambah);
   tombol hapus hanya untuk yang belum tersimpan.
4. **Preview ekspansi**: saat menambah jenis/nilai, tampilkan **tabel kombinasi** (mis. 4 baris IP17)
   agar user isi SKU/harga/stok per kombinasi sebelum simpan. Kirim `variation_types` + `variants[]`
   (lengkap dgn `options[]`) ke `PUT /products/{id}`.
5. Tampilkan badge varian lama "digantikan" (superseded) di detail (read-only), tidak di daftar utama.

---

## Urutan & dependensi

```
A (data) → B (endpoint) → C (create) ─┐
                          D (edit) ────┼→ E (omnichannel) → F (FE)
```
A & B prasyarat semua. D inti & paling berisiko. E memakai infra Fase 1–5 yang sudah ada.
Tiap fase: implement → test → suite hijau → commit (ID, Co-Authored-By) → push.

## Catatan akurasi & live
- Pemetaan field channel sudah **tervalidasi live** (tree + attributes): `name/label/is_mandatory/`
  `input_type/is_sale_prop/options{name,en_name,id}`. **Belum** tervalidasi: `/product/create`
  mau option **`name`** atau **`id`** — butuh 1 push uji (Fase E akan memunculkannya).
- SYSTEM_ATTRIBUTES (price/SellerSku/package_*) dikecualikan dari spec/variant wajib (sudah di validator).
