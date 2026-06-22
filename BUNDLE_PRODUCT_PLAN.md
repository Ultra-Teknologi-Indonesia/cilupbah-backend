# Plan — Model Produk: Satuan / Varian / Bundle (selaras Jubelio)

Memastikan tiga konsep produk terpenuhi penuh + bundle bisa memilih produk & varian
komponen + stok bundle diturunkan dari komponen + komponen terpotong saat bundle terjual
(anti-oversell) + propagasi omnichannel benar.

## Model (acuan Jubelio)

| Konsep | Penanda | Stok | Bentuk |
|---|---|---|---|
| **Satuan** | `is_bundle=false`, **1 varian** | langsung di SKU | tunggal |
| **Varian** | `is_bundle=false`, **>1 varian** (variation_values) | per varian | parent–child |
| **Bundle** | `is_bundle=true` + `bundles[]` (komponen item_id+qty) | **diturunkan** dari komponen | 1 SKU virtual (BOM) |

Satuan vs Varian dibedakan **hanya oleh jumlah varian** (1 vs >1) — sesuai Jubelio; tidak
perlu flag terpisah. Bundle = produk dengan **SKU sendiri** yang isinya referensi varian lain.

---

## Status pengerjaan

- ✅ **Modul Dasar** — selesai (model produk Satuan/Varian/Bundle + `is_bundle`).
- ✅ **FASE B0** — selesai (commit `9be5fb7`): konsolidasi tabel komposisi ke
  `product_bundle_items`, migrasi data, `product_bundles` deprecated, `ProductResource`
  ekspos `product_type` + `total_variants`. 221 test Product+Inventory hijau.
- ✅ **FASE B1** — selesai: validasi komponen (varian aktif) + detail bundle ekspos komposisi
  (produk induk, variation_values, qty, stok komponen). 223 test hijau.
- ✅ **FASE B2** — selesai: guard bundle-in-bundle (23504) + transaction-lock (90003) di
  `ProductService::createOrUpdateBundle` (via repo `variantIdsFromBundleProducts`,
  `currentIsBundle`, `transactionLockReason`); `storeBundle` catch DomainException→422.
- ✅ **FASE B3** — selesai: derivasi stok bundle `MIN(floor(available_komponen/qty))` via
  `Support\BundleStock::derive`; diekspos `bundle_stock` di detail produk + `ProductStockResource`
  (endpoint all-stocks). Read-only, bundle tanpa ledger sendiri.
- ✅ **FASE B4** — selesai: `StockService` (reserve/pick/ship/cancel/restore) deteksi varian bundle
  via `ProductRepository::bundleComponentsForVariant` → kaskade ke komponen ×qty, atomik (1 transaksi),
  urutan lock deterministik (orderBy variant_id). Bundle tak menyentuh ledger sendiri. Transparan
  ke `SalesOrderService` (tanpa perubahan caller).
- ✅ **FASE B5** — selesai: SyncStockToChannelsJob propagasi ke bundle terdampak
  (ProductRepository::bundleProductIdsUsingComponent); cascade B4 dispatch sync per komponen.
- ⬜ B6 — belum.

## Kondisi saat ini (hasil audit)

| # | Area | Status |
|---|---|---|
| 1 | `is_bundle` flag (products) | ✅ ada (migration + model + di-set saat create bundle) |
| 2 | Penyimpanan komposisi | ✅ **DIKONSOLIDASI (B0)** — kanonik `product_bundle_items` (bundle_product_id↔component_variant_id+qty). Semua penulis (Inventory `BundleController`, `ProductImportService`, `saveBundle`) diarahkan ke sini. `product_bundles` (lama) `@deprecated`, belum di-drop. |
| 3 | API buat bundle | ✅ `StoreBundleRequest` (`components[].variant_id`+`qty`) → `createOrUpdateBundle` → `saveBundle`. Pilih komponen = kirim `variant_id` (varian spesifik). |
| 4 | Stok bundle (derivasi + potong komponen) | ❌ **TIDAK ADA** — `StockService` tak mengenali bundle; jual bundle TIDAK memotong komponen → **risiko oversell**. |
| 5 | Guard (bundle-in-bundle, transaction-lock) | ❌ tidak ada |
| 6 | Satuan vs Varian | ✅ implisit by-count (`total_variants`) — sesuai Jubelio. **B0**: diekspos eksplisit via `product_type` di `ProductResource`. |
| 7 | Omnichannel bundle | ⚠️ mapper push bundle sebagai **1 SKU sendiri** — lihat koreksi di bawah |

### ⚠️ Koreksi atas audit (area 7)
Audit menyarankan "bundle harus didekomposisi jadi SKU komponen di marketplace". **Itu keliru**
menurut contoh Jubelio Anda: bundle `JELLY-TOSKA-2-IP-16` (item_id 32633) terdaftar di Lazada/
Shopee/Tokopedia sebagai **SKU tunggal miliknya sendiri** (punya `channel_item_id` sendiri),
BUKAN didekomposisi. Komposisi `bundles_variants` hanya untuk **potong stok internal**.
➡️ Mapper kita yang push bundle sebagai 1 SKU **sudah benar**. Yang kurang murni **sinkronisasi
stok** (stok bundle = turunan; saat terjual, potong komponen + re-sync stok produk terdampak).

---

## FASE B0 — Konsolidasi tabel komposisi + ekspos tipe ✅ SELESAI (commit 9be5fb7)

- ✅ Kanonikkan ke **`product_bundle_items`** (`bundle_product_id`, `component_variant_id`, `qty`).
  `product_bundles` (variant↔variant) → ditandai `@deprecated`; data dimigrasikan (migration
  idempoten); semua penulis dialihkan ke tabel kanonik (ProductImportService & BundleController
  Inventory tak lagi menulis ke tabel lama).
- ✅ `ProductResource` ekspos `product_type` turunan: `bundle` (is_bundle) | `variant` (>1 varian) |
  `single` (1 varian) + `total_variants` — agar FE jelas tanpa menebak.
- ✅ Test: resource mengembalikan product_type benar untuk 3 kasus + Inventory bundle store
  menulis ke tabel kanonik.
- Catatan: `product_bundles` belum di-drop (deprecate dulu; drop di fase terpisah saat aman).

## FASE B1 — Komposisi: pilih produk + varian + qty ✅ SELESAI

- ✅ `StoreBundleRequest`: `components[].variant_id` (uuid, exists) + `qty>=1`; validasi varian
  **aktif** (`Rule::exists(...is_active=true)`). Memilih varian spesifik dari produk multi-varian
  = FE kirim `variant_id` varian terpilih (sudah didukung skema product_bundle_items).
- ✅ Detail bundle (`GET /products/{id}`) ekspos `bundle_components`: per komponen → produk induk
  (id,name), varian (sku), variation_values, qty, stok komponen (on_hand/reserved/available).
- ✅ Test: bundle dari varian spesifik produk-A + produk-B (multi-varian) tersimpan & detail benar;
  varian non-aktif ditolak 422.
- Catatan: guard varian harus milik produk **non-bundle** (bundle-in-bundle) → ditegakkan di **B2**.

## FASE B2 — Guard bisnis (anti error 23504 & 90003) ✅ SELESAI

- ✅ **Bundle-in-bundle**: komponen tak boleh varian dari produk `is_bundle=true` → DomainException→422
  (padanan 23504). Repo `variantIdsFromBundleProducts(array): array`.
- ✅ **Transaction-lock**: produk non-bundle yang sudah punya transaksi (ledger `inventory_movements` /
  `sales_order_items` / mapping channel non-`deactivated`) tak boleh dikonversi jadi bundle → 422
  (padanan 90003). Repo `transactionLockReason(id): ?string`; transisi non-bundle→bundle saja yang
  dikunci (`currentIsBundle` true → edit bundle lama tetap diizinkan).
- ✅ `storeBundle` (`POST /inventory/items`) catch `\DomainException`→422.
- ✅ Test (BundleCompositionTest): bundle-in-bundle ditolak, produk dgn mapping aktif gagal dikonversi,
  produk bersih sukses jadi bundle (happy path).

## FASE B3 — Derivasi stok bundle ✅ SELESAI

- ✅ `available_bundle = MIN atas komponen( floor(available_komponen / qty) )` di
  `Modules/Product/app/Support/BundleStock.php`. `on_hand` pakai rumus sama (jumlah bundle yang bisa
  dirakit); `reserved`/`on_order` = 0 (hidup di komponen).
- ✅ Ekspos `bundle_stock` di `ProductResource` (detail) + `ProductStockResource` (all-stocks);
  repo `getByIdsWithStock` eager-load `bundleItems.component.inventories`. Read-only.
- ✅ Test: {A:10/1, B:3/1} → 3; qty>1 ({A:10/3=3, B:8/2=4} → 3); via endpoint all-stocks.
- FE menampilkan stok bundle → digarap di **B6**.

## FASE B4 — Potong stok komponen saat bundle terjual ✅ SELESAI (anti-oversell)

- ✅ `StockService` (reserve/pick/ship/cancel/restore): bila item adalah **varian bundle**, kaskade
  ke komponen × qty (`cascadeBundle` membungkus seluruh komponen dalam 1 `DB::transaction`). Bundle
  sendiri TIDAK menyentuh ledger (tak punya inventory/movement).
- ✅ Transaksional (semua komponen atau none) — komponen kurang → `InsufficientStockException` →
  rollback total. Urutan lock deterministik (`bundleComponentsForVariant` orderBy `component_variant_id`)
  untuk hindari deadlock antar order bundle yang berbagi komponen.
- ✅ Transparan ke `SalesOrderService`/`ReleaseExpiredReservationsJob` (caller tak berubah).
- ✅ Test (BundleStockCascadeTest): reserve ×qty, cancel mengembalikan, pick potong on_hand,
  insufficient atomik, item non-bundle tak terpengaruh.

## FASE B5 — Propagasi stok omnichannel ✅ SELESAI

- Bundle listing = **1 SKU sendiri** (sudah benar) dengan stok = derivasi (B3).
- Saat stok komponen berubah (mis. bundle/komponen terjual), re-sync stok ke channel untuk:
  (a) produk komponen, (b) **semua bundle** yang memakai komponen itu. Pakai `SyncStockToChannelsJob`
  / `sync_price_stock` yang sudah ada; tambahkan resolusi "bundle terdampak".
- Test (mock adapter): jual bundle → dispatch sync stok untuk bundle + komponen terdampak.

## FASE B6 — Frontend ✅ SELESAI

- ✅ Builder bundle (`bundle-builder.tsx`): cari **produk** (exclude bundle) → bila multi-varian pilih
  **varian** → set qty (stepper). Terintegrasi di form Detail saat toggle Bundle aktif; submit via
  endpoint `storeBundle` (`POST /inventory/items`) → `useCreateBundle`.
- ✅ Tab Komposisi (detail): stok turunan bundle (read-only, dari `bundle_stock`) + peringatan bila
  komponen kurang dari kebutuhan 1 bundle (baris merah + banner).
- ✅ Badge tipe produk (Satuan/Varian/Bundle) dari `product_type` — `ProductTypeBadge` di detail header.
- ✅ Hidrasi edit bundle: `detailToFormValues` memuat `bundleComponents` dari detail; `EditProdukForm`
  branch submit ke `storeBundle` (dengan `id`) untuk update komposisi. Create + edit bundle end-to-end.

---

## Urutan & prioritas
```
B0 (konsolidasi) → B1 (komposisi) → B2 (guard) → B3 (derivasi stok) → B4 (potong stok) → B5 (omnichannel) → B6 (FE)
```
Paling kritis bisnis: **B2 + B4** (anti error & anti-oversell). Tiap fase: implement → test → push.

## Catatan
- Mapper outbound bundle = SKU tunggal (JANGAN dekomposisi) — sesuai Jubelio.
- Stok bundle tak pernah ditulis langsung; selalu turunan → hindari sumber kebenaran ganda.
