# PLAN — Buat Master Produk (Produk Satuan) · Full Parity

Status: **Planning** · Scope: **BE (Modules/Product, Tax, Finance, Inventory) + FE form**
Tujuan: halaman "Buat Produk → Produk Satuan" dengan paritas penuh terhadap form
referensi (gaya Accurate/Jubelio): tab **Detail Produk**, **Informasi Penjualan &
Pembelian**, **Informasi Pengiriman**, **Gambar & Video** — dengan **business
logic benar, flow benar, dan TIDAK ada error 500** (semua kegagalan input → 422).

> Verifikasi skema sudah dilakukan terhadap migrasi asli (lihat referensi di tiap
> bagian). Tidak ada perubahan kode yang dilakukan saat menyusun rencana ini.

---

## 1. Pemetaan field form → BE (3 kategori)

### A. ✅ Sudah ada & sudah jalan di `POST /v1/products`
`name`, `brand_id`, `category_id`, deskripsi, `variants[].sku`, `variants[].sell_price`,
`is_bundle`, `is_consignment`, `weight/length/width/height`, media (image/video).

### B. ⚠️ Kolom SUDAH ADA, tapi `createProduct` belum mengisi → cukup di-wire
| Form | Kolom existing |
|---|---|
| Pre-Order | `products.order_type` (`REGULER\|PREORDER\|COD`) + `indent_days` |
| SKU level produk | `products.sku` (nullable unique) |
| Kondisi/COD (opsional) | `products.condition`, `is_cod_allowed` |
| Harga Beli | `product_variants.buy_price` (decimal 15,2) |
| Pajak (rate mentah) | `product_variants.tax_rate` (decimal 5,2) |
| Batas stok menipis | `product_variants.min_stock` (integer) |
| Barcode (opsional) | `product_variants.barcode` (unique nullable) |

### C. ❌ Butuh kolom/relasi BARU (migrasi) untuk paritas penuh
| Form | Rencana kolom baru |
|---|---|
| Disimpan / Dijual / Dibeli | `products.is_stored`, `is_sold`, `is_purchased` (bool) |
| Akun Penjualan | `products.sales_account_id` → `accounts.id` |
| Retur Penjualan | `products.sales_return_account_id` → `accounts.id` |
| Akun Persediaan | `products.inventory_account_id` → `accounts.id` |
| Akun HPP | `products.cogs_account_id` → `accounts.id` |
| Lama Pembelian | `products.purchase_lead_time` (integer, hari) |
| Isi Paket | `products.package_contents` (text) |
| Batas stok aman | `product_variants.safe_stock` (integer) |
| Pajak Penjualan | `product_variants.sales_tax_id` → `taxes.id` |
| Pajak Pembelian | `product_variants.purchase_tax_id` → `taxes.id` |
| Toko stok tak terbatas | **keputusan desain** — lihat §7 (default: `product_variants.is_unlimited_stock` bool) |
| Merek Lainnya (merek baru) | tanpa kolom — flow `POST /v1/brands` lalu pakai id (§4) |

---

## 2. Perubahan skema BE (migrasi)

> Semua kolom **nullable / ber-default** agar tidak memecah data lama → tidak ada
> error saat migrate. FK pakai `nullOnDelete` agar penghapusan akun/pajak tak
> menimbulkan 500.

### 2.1 `products` — `add_master_commerce_fields_to_products_table`
```php
Schema::table('products', function (Blueprint $t) {
    $t->boolean('is_stored')->default(true)->after('is_consignment');     // Disimpan
    $t->boolean('is_sold')->default(true)->after('is_stored');            // Dijual
    $t->boolean('is_purchased')->default(true)->after('is_sold');         // Dibeli
    $t->integer('purchase_lead_time')->default(0)->after('is_purchased'); // Lama Pembelian (hari)
    $t->text('package_contents')->nullable()->after('purchase_lead_time');// Isi Paket
    $t->uuid('sales_account_id')->nullable()->after('package_contents');
    $t->uuid('sales_return_account_id')->nullable();
    $t->uuid('inventory_account_id')->nullable();
    $t->uuid('cogs_account_id')->nullable();
    $t->foreign('sales_account_id')->references('id')->on('accounts')->nullOnDelete();
    $t->foreign('sales_return_account_id')->references('id')->on('accounts')->nullOnDelete();
    $t->foreign('inventory_account_id')->references('id')->on('accounts')->nullOnDelete();
    $t->foreign('cogs_account_id')->references('id')->on('accounts')->nullOnDelete();
});
```

### 2.2 `product_variants` — `add_purchase_tax_safe_stock_to_product_variants_table`
```php
Schema::table('product_variants', function (Blueprint $t) {
    $t->integer('safe_stock')->default(0)->after('min_stock');     // Batas stok aman
    $t->boolean('is_unlimited_stock')->default(false)->after('safe_stock');
    $t->unsignedBigInteger('sales_tax_id')->nullable()->after('tax_rate');
    $t->unsignedBigInteger('purchase_tax_id')->nullable()->after('sales_tax_id');
    $t->foreign('sales_tax_id')->references('id')->on('taxes')->nullOnDelete();
    $t->foreign('purchase_tax_id')->references('id')->on('taxes')->nullOnDelete();
});
```
Catatan: `tax_rate` lama **dipertahankan** sebagai cache rate pajak jual (dibaca
`MasterItemResource`). Saat create: jika `sales_tax_id` diisi → `tax_rate` diisi
dari `taxes.rate` agar konsisten ke belakang.

### 2.3 Finance — tambah key default COGS
`AccountMappingKey`: tambah `COGS = 'cogs'` (label "Harga Pokok Penjualan", default
code `5-5000`). Dipakai untuk fallback `cogs_account_id` bila FE tak mengirim akun.
Seeder/migrasi data: isi mapping `cogs` → akun 5-5000 bila ada.

### 2.4 Model (`fillable`/`casts`/relasi)
- `Product`: tambah field di atas ke `$fillable`; relasi `salesAccount/salesReturnAccount/inventoryAccount/cogsAccount` (BelongsTo Account); cast bool.
- `ProductVariant`: tambah `safe_stock, is_unlimited_stock, sales_tax_id, purchase_tax_id, buy_price, barcode` ke `$fillable`; relasi `salesTax/purchaseTax` (BelongsTo Tax). (createProduct pakai raw insert, tapi fillable tetap dirapikan untuk update/edit.)

---

## 3. Validasi `CreateProductRequest` (anti-500 → semua 422)

Prinsip: **semua FK divalidasi `exists`**, semua angka `numeric/integer + min`,
enum `Rule::in`, sehingga input salah → **422**, bukan 500.

```php
return [
  // Detail
  'name'            => 'required|string|max:255',
  'sku'             => 'nullable|string|max:50|unique:products,sku',
  'category_id'     => 'required|integer|exists:categories,id',
  'brand_id'        => 'nullable|integer|exists:brands,id',
  'description'     => 'nullable|string|max:10000',
  'is_bundle'       => 'boolean',
  'is_consignment'  => 'boolean',
  'order_type'      => ['nullable', Rule::in(['REGULER','PREORDER','COD'])],
  'indent_days'     => 'nullable|integer|min:0|required_if:order_type,PREORDER',

  // Penjualan & Pembelian (header produk)
  'is_stored'       => 'boolean',
  'is_sold'         => 'boolean',
  'is_purchased'    => 'boolean',
  'purchase_lead_time' => 'nullable|integer|min:0',
  'sales_account_id'        => 'nullable|uuid|exists:accounts,id',
  'sales_return_account_id' => 'nullable|uuid|exists:accounts,id',
  'inventory_account_id'    => 'nullable|uuid|exists:accounts,id',
  'cogs_account_id'         => 'nullable|uuid|exists:accounts,id',

  // Pengiriman
  'weight'  => 'nullable|numeric|min:0',   // gram (FE) → simpan apa adanya
  'length'  => 'nullable|numeric|min:0',
  'width'   => 'nullable|numeric|min:0',
  'height'  => 'nullable|numeric|min:0',
  'package_contents' => 'nullable|string|max:2000',

  // Media
  'media'   => 'nullable|array|max:10',
  'media.*.url'        => 'required|string',
  'media.*.media_uuid' => 'nullable|uuid',
  'media.*.media_type' => 'nullable|in:image,video',
  'media.*.is_primary' => 'nullable|boolean',
  'media.*.sort_order' => 'nullable|integer|min:0',

  // Spesifikasi & variasi (opsional)
  'specifications'              => 'nullable|array',
  'specifications.*.attribute_id'        => 'required|integer|exists:attributes,id',
  'specifications.*.attribute_option_id' => 'nullable|integer|exists:attribute_options,id',
  'specifications.*.text_value'          => 'nullable|string',
  'variation_types'             => 'nullable|array',
  'variation_types.*.attribute_id' => 'required|integer|exists:attributes,id',
  'variation_types.*.sort_order'   => 'nullable|integer|min:0',

  // Varian (Produk Satuan = tepat 1 varian; produk variasi = banyak)
  'variants'                 => 'required|array|min:1',
  'variants.*.sku'           => 'required|string|max:50|distinct|unique:product_variants,sku',
  'variants.*.barcode'       => 'nullable|string|max:100|distinct|unique:product_variants,barcode',
  'variants.*.sell_price'    => 'required_if:is_sold,true|nullable|numeric|min:0',
  'variants.*.buy_price'     => 'required_if:is_purchased,true|nullable|numeric|min:0',
  'variants.*.sales_tax_id'  => 'nullable|integer|exists:taxes,id',
  'variants.*.purchase_tax_id'=> 'nullable|integer|exists:taxes,id',
  'variants.*.min_stock'     => 'nullable|integer|min:0',   // batas stok menipis
  'variants.*.safe_stock'    => 'nullable|integer|min:0',   // batas stok aman
  'variants.*.is_unlimited_stock' => 'nullable|boolean',
  'variants.*.is_active'     => 'nullable|boolean',
  'variants.*.options'       => 'nullable|array',
  'variants.*.options.*.attribute_id' => 'required|integer|exists:attributes,id',
  'variants.*.options.*.value'         => 'required|string',
  'variants.*.media'         => 'nullable|array',
  'variants.*.media.*.url'   => 'required|string',
  'variants.*.wholesale_prices' => 'nullable|array',
  'variants.*.wholesale_prices.*.min_qty' => 'required|integer|min:1',
  'variants.*.wholesale_prices.*.price'   => 'required|numeric|min:0',
  'variants.*.channel_prices'  => 'nullable|array',
  'variants.*.channel_prices.*.channel_shop_id' => 'required|uuid',
  'variants.*.channel_prices.*.price'           => 'required|numeric|min:0',
];
```

**Aturan lintas-field (`withValidator` / custom):**
1. Minimal salah satu `is_sold` / `is_purchased` = true (kalau dua-duanya false → 422).
2. (Opsional, soft) validasi tipe akun: `sales_account_id`→revenue, `sales_return_account_id`→revenue, `inventory_account_id`→asset, `cogs_account_id`→expense. Implementasi via `Rule::exists('accounts','id')->where('account_type', …)` agar tetap 422.
3. `safe_stock >= min_stock` (peringatan/validasi lembut).
4. Mirror semua aturan ini ke `UpdateProductRequest` (edit paritas penuh).

`UpdateProductRequest`: SKU unik harus `unique:…,sku,{variant_id}` (ignore diri sendiri) supaya edit tak 422 palsu.

---

## 4. Flow & business logic (`ProductService`)

### 4.1 Lookups untuk form (FE memanggil di awal)
- `GET /v1/categories?all=1` (nested via parent_id) — picker kategori.
- `GET /v1/brands?all=1` + **POST /v1/brands** (Merek Lainnya: buat merek baru → pakai id).
- `GET /v1/attributes?all=1` (variasi & spesifikasi).
- `GET /v1/taxes` — Pajak Penjualan/Pembelian.
- `GET /v1/accounts/lookup/all` — Akun (lihat §6: tambah `account_type` + filter).
- `GET /v1/systemsetting/account-mapping` — prefill akun default.
- `GET /v1/locations` — (jika model unlimited per-toko).

### 4.2 Upload media (2 langkah, sudah ada)
1. Tiap file → `POST /v1/media/upload` (multipart `file`, ≤50MB) → `{uuid,url}`.
2. Kirim `media[]` berisi `media_uuid`+`url` saat create. **FE** memvalidasi: gambar
   ≤9, video MP4 ≤1 menit & ≤30MB (BE tak cek durasi).

### 4.3 `createProduct` (perluasan, tetap 1 `DB::transaction`)
Urutan insert (atomic, rollback bila gagal):
1. `products` — `Arr::only` diperluas: tambah `sku, order_type, indent_days,
   is_stored, is_sold, is_purchased, purchase_lead_time, package_contents,
   sales_account_id, sales_return_account_id, inventory_account_id, cogs_account_id`.
   - **Default akun**: bila id null → resolve dari `account_mappings`
     (sales_revenue→sales_account, sales_return→sales_return_account,
     inventory→inventory_account, cogs→cogs_account). Jika mapping juga kosong →
     biarkan null (tidak error).
   - `status`: default DB = `master`. **Keputusan §7**: terima `status` opsional
     (`download|in_review|master`) untuk dukung alur review; default tetap `master`.
2. `specifications`, `product_media` (produk), `product_variation_types` — seperti sekarang.
3. Tiap `variants` → `product_variants`: `Arr::only` diperluas: tambah `buy_price,
   barcode, sales_tax_id, purchase_tax_id, min_stock, safe_stock, is_unlimited_stock`.
   - **Sinkronisasi pajak**: jika `sales_tax_id` ada → `tax_rate = taxes.rate`.
   - lalu `variant_options`, `product_media` (varian), `product_wholesale_prices`,
     `channel_prices` (buat `product_channel_mappings` + `product_variant_channel_mappings`).
4. **Persediaan (is_stored)**: TIDAK membuat baris `inventories` di sini —
   inventori dibuat on-demand saat ada transaksi stok (sesuai desain existing).
   `min_stock`/`safe_stock` cukup tersimpan di varian. (Stok awal opsional bisa
   jadi enhancement via modul Inventory, di luar scope create.)
5. return `product_id`.

### 4.4 Lifecycle status (tetap)
download → `submit-review` → in_review → `approve` → master (syarat: nama, ≥1
varian ber-SKU & harga, ≥1 gambar). FE bisa langsung "Simpan" (master) atau
"Simpan sebagai draft" (jika kita pakai `status=download`).

---

## 5. Endpoint baru/diubah (BE)
| Endpoint | Perubahan |
|---|---|
| `POST /v1/products` | request + service diperluas (§3, §4.3) |
| `PUT /v1/products/{id}` | mirror create (edit paritas penuh) |
| `GET /v1/products/{id}` | `ProductResource` tambah field baru (akun, pajak, flags, stok) |
| `GET /v1/accounts/lookup/all` | resource tambah `account_type`; dukung `?type=` (filter dropdown akun) |
| `GET /v1/products/form-options` *(opsional)* | bootstrap 1x: brands, categories, attributes, taxes, accounts(by type), locations, default mappings — kurangi round-trip FE |

`ProductResource` (detail) tambahkan: `sku, order_type, indent_days, is_stored,
is_sold, is_purchased, purchase_lead_time, package_contents, accounts{...}`, dan
per-varian: `buy_price, sales_tax{id,rate}, purchase_tax{id,rate}, min_stock,
safe_stock, is_unlimited_stock, barcode`.

---

## 6. Strategi anti error 500 (eksplisit)
1. **Semua FK & enum divalidasi di FormRequest** → input invalid = 422.
2. **`unique`/`distinct` SKU & barcode** di request → duplikat = 422 (bukan 500
   dari constraint DB). Tetap bungkus race-condition di transaksi.
3. **Transaksi**: seluruh `createProduct` dalam `DB::transaction` → gagal = rollback
   penuh, tak ada data setengah jadi.
4. Controller `store()`: pertahankan `try/catch` sebagai jaring terakhir, tapi
   karena validasi sudah ketat, 500 hanya untuk error server asli (DB down, dll).
   Pertimbangkan log + pesan generik (jangan bocorkan `$e->getMessage()` mentah di
   produksi).
5. **`exists` untuk akun bertipe** pakai `Rule::exists(...)->where('account_type',…)`
   → akun salah tipe = 422 berpesan jelas.
6. **FK `nullOnDelete`** mencegah error saat akun/pajak terhapus.
7. Uji negatif (lihat §9) memastikan tiap jalur invalid mengembalikan 422.

---

## 7. Keputusan desain (perlu konfirmasi)
1. **"Toko stok tidak terbatas"**
   - **Opsi A (rekomendasi v1):** `product_variants.is_unlimited_stock` (boolean) —
     sederhana, cukup untuk mayoritas kasus.
   - **Opsi B (paritas literal):** pivot `variant_unlimited_locations(variant_id,
     location_id)` agar bisa per-toko/lokasi. Lebih kompleks; tambah endpoint.
   → Default rencana ini: **Opsi A**.
2. **Status awal saat "Simpan"**: default `master` (langsung aktif, ala Accurate)
   atau `download` (masuk alur review)? Rencana: terima `status` opsional, default
   `master`; sediakan tombol "Simpan sebagai draft" → `download`.
3. **Pajak**: model FK ke `taxes` (rekomendasi) — `taxes` tak punya tipe jual/beli,
   jadi satu daftar pajak dipakai untuk dua dropdown. OK?
4. **Satuan berat**: form pakai gram; kolom `weight` decimal. Simpan gram apa adanya
   atau konversi ke kg? Rencana: simpan apa adanya (gram) + dokumentasikan.
5. **Validasi tipe akun**: ketat (revenue/asset/expense) atau cukup `exists`?
   Rekomendasi: ketat tapi lewat `exists+where` (tetap 422).

---

## 8. Rencana FE (setelah BE siap)
- `types/product/product.types.ts` — payload create + tipe lookup.
- `services/product/product.service.ts` — `createProduct`, `updateProduct`,
  `uploadMedia`, `getFormOptions` (atau lookups terpisah), `createBrand`.
- hooks TanStack Query: `useProductFormOptions`, `useCreateProduct`, `useUploadMedia`.
- Halaman `app/dashboard/master-produk/buat/page.tsx` — **react-hook-form + zod**,
  4 tab persis referensi:
  1. Detail Produk (nama, merek+merek baru, kategori picker nested, SKU, deskripsi
     rich-text, tipe: bundle/konsinyasi/pre-order(+lama indent)).
  2. Informasi Penjualan & Pembelian (toggle Disimpan/Dijual/Dibeli; harga default,
     harga beli, pajak jual/beli, akun-akun, batas stok menipis/aman, lama beli).
  3. Informasi Pengiriman (berat gram, P/L/T, isi paket).
  4. Gambar & Video (upload ≤9 gambar + 1 video MP4 ≤1mnt/30MB).
- Validasi zod mencerminkan §3 (dengan pesan ID), submit → upload media → `POST`.
- Tombol "Tambah Produk" di explorer → route ke halaman ini.
- (Konsisten dengan komponen glass/Dialog/picker kategori yang sudah ada.)

---

## 9. Checklist pengujian (Feature tests BE)
- ✅ Create minimal valid (nama, kategori, 1 varian sku+harga) → 201 + product_id.
- ✅ Create lengkap (semua field B & C) → tersimpan benar di semua tabel.
- ✅ Pre-Order tanpa `indent_days` → 422.
- ✅ `is_sold=true` tanpa `sell_price` → 422; `is_purchased=true` tanpa `buy_price` → 422.
- ✅ `is_sold=false` & `is_purchased=false` → 422.
- ✅ `sales_account_id` tipe bukan revenue → 422.
- ✅ SKU/barcode duplikat (existing & antar-varian) → 422.
- ✅ `category_id/brand_id/tax_id/account_id` tak ada → 422 (bukan 500).
- ✅ `sales_tax_id` diisi → `tax_rate` ikut terisi dari `taxes.rate`.
- ✅ Akun kosong → fallback ke `account_mappings` (jika ada) atau null (tak error).
- ✅ Transaksi rollback saat 1 sub-insert gagal (tidak ada produk yatim).
- ✅ Approve→master tetap menerapkan `assertReadyForMaster`.

---

## 10. Urutan kerja (fase)
1. **Migrasi** §2 (products, product_variants, AccountMappingKey COGS + seeder).
2. **Model** update fillable/casts/relasi.
3. **CreateProductRequest/UpdateProductRequest** §3 + aturan lintas-field.
4. **ProductService::createProduct/updateProduct** §4.3 + default akun + sync pajak.
5. **ProductResource** + **AccountLookup** (type filter) §5.
6. **Feature tests** §9 (jalankan, pastikan hijau, tak ada 500).
7. **FE**: types → services → hooks → halaman 4-tab → wire tombol.
8. **QA manual** end-to-end (upload → create → tampil di list/detail).

> Setelah disetujui, eksekusi per fase. Migrasi & service ada di BE production —
> butuh konfirmasi sebelum mulai Fase 1.

---

## 11. Keputusan final (mengunci §7 — override bagian terkait)

### 11.1 "Toko stok tidak terbatas" = MULTI-TOKO marketplace (bukan boolean)
Mengganti rencana boolean. Per-toko channel marketplace, bisa pilih >1 toko.
- **Verifikasi:** `channel_shops.id` = **UUID** (setelah migrasi `change_channel_ids_to_uuid`); `shop_name` ada → dipakai label dropdown.
- **Migrasi baru** `create_variant_unlimited_shops_table`:
  ```php
  Schema::create('variant_unlimited_shops', function (Blueprint $t) {
      $t->uuid('id')->primary();
      $t->uuid('variant_id');
      $t->uuid('channel_shop_id');
      $t->timestamps();
      $t->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
      $t->foreign('channel_shop_id')->references('id')->on('channel_shops')->cascadeOnDelete();
      $t->unique(['variant_id','channel_shop_id']);
  });
  ```
- **Batal**: kolom `product_variants.is_unlimited_stock` di §2.2 **tidak jadi** dibuat.
- **Validasi** (ganti baris is_unlimited_stock):
  ```php
  'variants.*.unlimited_shop_ids'   => 'nullable|array',
  'variants.*.unlimited_shop_ids.*' => 'uuid|distinct|exists:channel_shops,id',
  ```
- **Service**: setelah insert varian, insert baris pivot untuk tiap `unlimited_shop_ids`.
- **Lookup FE**: daftar toko dari `GET /v1/channel-monitor` atau endpoint channel-shops aktif (perlu konfirmasi endpoint; kandidat: `Modules/Channel` shops list). Tandai sbg item kerja BE bila belum ada endpoint list `channel_shops`.
- **Resource**: per-varian kembalikan `unlimited_shops: [{ channel_shop_id, shop_name }]`.

### 11.2 Status awal saat "Simpan" = `in_review` (default), draft = `download`
`master` **TIDAK** bisa lewat create — hanya via `approve` (lifecycle tetap dihormati).
- **Validasi**: `'status' => ['nullable', Rule::in(['download','in_review'])]`.
- **Service**: default `in_review` bila tak dikirim; FE: tombol **"Simpan"** → `in_review`, **"Simpan sebagai draft"** → `download`.
- Override §4.3 langkah 1 & default DB `master`.
- Konsekuensi: produk baru muncul di antrean Review, bukan langsung Master — sesuai harapan bisnis.

### 11.3 Validasi tipe akun = KETAT
`sales_account_id`→`revenue`, `sales_return_account_id`→`revenue`,
`inventory_account_id`→`asset`, `cogs_account_id`→`expense`, via
`Rule::exists('accounts','id')->where('account_type', <type>)` → salah tipe = **422**.

### 11.4 Item kerja tambahan akibat keputusan
- [ ] Migrasi pivot `variant_unlimited_shops`.
- [ ] Endpoint list toko marketplace aktif (jika belum ada) untuk dropdown unlimited-stock.
- [ ] `CreateProductRequest`: `status` enum + `unlimited_shop_ids` + tipe akun ketat.
- [ ] `ProductService`: set status (in_review/download), insert pivot unlimited shops.
- [ ] `ProductResource`: `unlimited_shops` per varian.
- [ ] Feature test: status hasil create = in_review/download (bukan master); unlimited_shops tersimpan; shop_id invalid → 422.
