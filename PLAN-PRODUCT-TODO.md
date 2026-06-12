# Plan — 8 Endpoint Product TODO (Variations, Price List, Promotions)

> **Disusun:** 2026-06-10 · **PIC:** Darriel · **Scope:** 8 item `todo` domain Product dari Dev Tracker (id 109,130,131,132,133,134,135,278).
> **Standar WAJIB:** `agents.md` — Controller tipis → **Service** → **Repository**; listing pakai **Spatie Query Builder** (per_page 10 + appends); output via **Eloquent Resource**; validasi via **FormRequest**.
> **Status verifikasi:** dicek langsung ke kode modul Product.

---

## 1. Hasil Verifikasi Kode

| Fitur | Kondisi kode | Kesimpulan |
|---|---|---|
| **Variations** (109, 278) | `ProductVariant`, `ProductVariationType`, `VariantOption` sudah ada | 🟢 reuse model — tinggal endpoint |
| **Price List** (130, 131) | `ProductVariant.sell_price` + tabel `product_wholesale_prices` ada (model belum) | 🟡 perlu model WholesalePrice + endpoint |
| **Promotions** (132–135) | **Tidak ada** model/tabel/kode sama sekali | 🔴 greenfield penuh |

---

## 2. Pengelompokan ke 3 Epik

| Epik | Endpoint | Effort | Sifat |
|---|---|---|---|
| **A. Variations** | 278 list, 109 delete | 🟢 S–M | reuse model variant |
| **B. Price List** | 130 list, 131 edit | 🟡 M | base + wholesale price |
| **C. Promotions** | 132 list, 133 create, 135 detail, 134 delete | 🔴 L | greenfield CRUD |

---

## 3. Detail Per Endpoint + Task (sesuai agents.md)

### 🟢 Epik A — Variations

#### 278 — `GET /variations` — Ambil semua varian produk
- **Sudah ada:** model `ProductVariant` (sku, sell_price), relasi `product`, `options`.
- **Interpretasi:** daftar varian SKU produk (paginated; Jubelio punya page/pageSize/sort). *Verifikasi field response vs `dist (2).yaml`.*
- **Task (lapis):**
  - **Repository** `ProductVariantRepository@paginate()` — `QueryBuilder::for(ProductVariant::class)` + `allowedSearch('sku')` + `allowedFilters(exact product_id, is_active)` + `allowedSorts('sku','created_at')` + `paginate(request('per_page',10))->appends(...)`.
  - **Service** `ProductVariantService@list()` → repo.
  - **Controller** `VariantController@index()` → `successPaginatedResponse(VariantResource::collection(...))`.
  - **Resource** `VariantResource` (id, sku, sell_price, product ringkas, stok bila perlu).
  - **Route** `GET variations`.
- **DoD:** listing terpaginasi + filter/sort; Resource konsisten.

#### 109 — `DELETE /inventory/items/item-variant/` — Hapus varian item
- **Sudah ada:** `ProductVariant` + relasi `inventories`, `channelMappings`.
- **Task (lapis):**
  - **Repository** `ProductVariantRepository@findById($id)`, `@delete($variant)`.
  - **Service** `ProductVariantService@deleteVariant($id)` — **guard logika bisnis:** tolak hapus bila varian masih punya stok (`inventories.sum(on_hand) > 0`) atau bila varian terakhir produk (opsional). Lempar `DomainException`.
  - **Controller** `VariantController@destroy(Request $id)` — terima id (query/body/param), panggil service, balas `successResponse(null, ...)`; tangkap `DomainException` → 422.
  - **Route** `DELETE inventory/items/item-variant` (id via `?variant_id=` atau body).
- **DoD:** varian terhapus bila aman; tertolak 422 bila masih ada stok; 404 bila tak ditemukan.

### 🟡 Epik B — Price List

#### 130 — `GET /inventory/internal-price-list/` — Ambil semua harga produk
- **Sudah ada:** `ProductVariant.sell_price`, `product_wholesale_prices` (tabel).
- **Task (lapis):**
  - **Model** `WholesalePrice` (table `product_wholesale_prices`: variant_id, customer_type, min_qty, max_qty, price) + relasi `ProductVariant@wholesalePrices()`.
  - **Repository** `PriceListRepository@paginate()` — `QueryBuilder::for(ProductVariant::class)` + `with('product:id,name','wholesalePrices')` + `allowedSearch('sku')` + `allowedFilters(exact product_id)` + `allowedSorts('sell_price','sku')` + `paginate(request('per_page',10))->appends(...)`.
  - **Service** `PriceListService@list()` → repo.
  - **Controller** `PriceListController@index()` → `PriceListResource::collection`.
  - **Resource** `PriceListResource` (variant_id, sku, sell_price, tax_rate, wholesale[]).
  - **Route** `GET inventory/internal-price-list`.
- **DoD:** daftar harga (jual + grosir) terpaginasi & terfilter.

#### 131 — `POST /inventory/price-list/` — Ubah harga produk
- **Task (lapis):**
  - **FormRequest** `UpdatePriceListRequest` — `items: [{variant_id (uuid, exists), sell_price (numeric≥0), tax_rate? }]`.
  - **Repository** `PriceListRepository@updatePrices(array $items)` — update massal `sell_price`/`tax_rate` per variant (transaksi).
  - **Service** `PriceListService@updatePrices($items)` → repo.
  - **Controller** `PriceListController@update(UpdatePriceListRequest)` → service → `successResponse`.
  - **Route** `POST inventory/price-list`.
- **DoD:** harga ≥1 varian terupdate atomik; validasi varian wajib ada.

### 🔴 Epik C — Promotions (greenfield)

> Definisikan dulu model promo. Skema minimal: **promotions**(id uuid, name, type [percent|fixed], value, start_at, end_at, is_active) + **promotion_items**(promotion_id, product_id atau variant_id) untuk target. (Sesuaikan field ke `dist (2).yaml`.)

#### 133 — `POST /inventory/promotions/` — Buat promosi
- **Migration** `promotions` (+ `promotion_items` bila perlu target).
- **Model** `Promotion` (HasUuid7) + relasi `items()`.
- **FormRequest** `StorePromotionRequest` (name, type in:percent,fixed, value, start_at, end_at, items[]).
- **Repository** `PromotionRepository@create(array)` (transaksi: promo + items).
- **Service** `PromotionService@create($data)`.
- **Controller** `PromotionController@store(StorePromotionRequest)` → `successResponse(new PromotionResource(...), 201)`.
- **Route** `POST inventory/promotions`.

#### 132 — `GET /inventory/promotions/` — Ambil semua promosi
- **Repository** `PromotionRepository@paginate()` — Spatie: `allowedSearch('name')`, `allowedFilters(exact is_active, AllowedFilter::scope('active_on'))`, `allowedSorts('start_at','created_at')`, `paginate(request('per_page',10))->appends(...)`.
- **Service** `@list()` → **Controller** `@index` → `PromotionResource::collection`.
- **Route** `GET inventory/promotions`.

#### 135 — `GET /inventory/promotions/{id}` — Detail promosi
- **Repository** `@findById($id)` (Eloquent biasa, lookup tunggal) + `with('items')`.
- **Service** `@find($id)` → **Controller** `@show` → `PromotionResource`; 404 bila tak ada.
- **Route** `GET inventory/promotions/{id}`.

#### 134 — `DELETE /inventory/promotions/` — Hapus promosi
- **Repository** `@delete($promotion)`.
- **Service** `@delete($id)`.
- **Controller** `@destroy` (id via `?id=`/body/param) → `successResponse(null, ...)`; 404 bila tak ada.
- **Route** `DELETE inventory/promotions/{id}` (atau body sesuai Jubelio).
- **Resource** `PromotionResource` (dipakai 132/133/135).

---

## 4. Berkas Baru/Diubah (ringkas)

```
# Variations
Modules/Product/app/Http/Controllers/VariantController.php          (baru)
Modules/Product/app/Services/ProductVariantService.php             (baru)
Modules/Product/app/Repositories/ProductVariantRepository.php      (baru)
Modules/Product/app/Http/Resources/VariantResource.php             (baru)

# Price List
Modules/Product/app/Models/WholesalePrice.php                      (baru)
Modules/Product/app/Http/Controllers/PriceListController.php       (baru)
Modules/Product/app/Services/PriceListService.php                  (baru)
Modules/Product/app/Repositories/PriceListRepository.php           (baru)
Modules/Product/app/Http/Requests/UpdatePriceListRequest.php       (baru)
Modules/Product/app/Http/Resources/PriceListResource.php           (baru)
Modules/Product/app/Models/ProductVariant.php                      (+ wholesalePrices relation)

# Promotions
Modules/Product/database/migrations/xxxx_create_promotions_table.php          (baru)
Modules/Product/database/migrations/xxxx_create_promotion_items_table.php     (baru, opsional)
Modules/Product/app/Models/Promotion.php                           (baru)
Modules/Product/app/Http/Controllers/PromotionController.php       (baru)
Modules/Product/app/Services/PromotionService.php                  (baru)
Modules/Product/app/Repositories/PromotionRepository.php           (baru)
Modules/Product/app/Http/Requests/StorePromotionRequest.php        (baru)
Modules/Product/app/Http/Resources/PromotionResource.php           (baru)

# Routes
Modules/Product/routes/api.php   (+ variations, item-variant, internal-price-list, price-list, promotions/*)
```

---

## 5. Urutan Kerja & Estimasi

| Hari | Fokus | Endpoint | Est. |
|---|---|---|---|
| **1** | Epik A — Variations | 278, 109 | S–M (~½ hari) |
| **1–2** | Epik B — Price List | 130, 131 (+ model WholesalePrice) | M (~1 hari) |
| **2–4** | Epik C — Promotions | 132,133,134,135 (migration→CRUD) | L (~1.5–2 hari) |

**Total: ~3–3.5 hari.** Target: 8 item `todo` → `done` (Product done 26→34/34, **100%**).

---

## 6. Definition of Done (per endpoint)
1. Lapisan benar: Controller tipis → Service → Repository (tanpa query/logika di controller).
2. Listing pakai Spatie (`per_page` 10 + `appends`); lookup tunggal/derived pakai Eloquent di repository.
3. Output via Resource; validasi via FormRequest.
4. Response menyamai struktur Jubelio (`dist (2).yaml`).
5. Min. 1 feature test (happy path + 1 error path).
6. Status di `/dev/tracking` → **done** + notes.

---

## 7. Catatan / Risiko
- **278 `/variations`:** konfirmasi apakah Jubelio memaksudkan daftar **varian SKU** (interpretasi dipilih) atau **definisi variasi master** (Color/Size). Cek `getAllVariations` di `dist (2).yaml` sebelum mulai.
- **Promotions:** skema field belum pasti — selaraskan `type/value/target` dengan `dist (2).yaml` agar drop-in. Mulai dari migration yang benar agar tak rework.
- **131 price-list:** putuskan apakah hanya `sell_price` atau termasuk grosir (`wholesale`). Default plan: keduanya (sell_price wajib, wholesale opsional).
- **109 delete variant:** wajib guard stok (konsisten dgn `ProductService@deleteProduct` yang menolak hapus produk bila masih ada stok).
