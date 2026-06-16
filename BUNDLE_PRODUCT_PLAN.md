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

## Kondisi saat ini (hasil audit)

| # | Area | Status |
|---|---|---|
| 1 | `is_bundle` flag (products) | ✅ ada (migration + model + di-set saat create bundle) |
| 2 | Penyimpanan komposisi | ⚠️ **DUA tabel** rancu: `product_bundles` (variant↔variant, lama) **dan** `product_bundle_items` (bundle_product_id↔component_variant_id+qty, baru). `saveBundle` pakai yang baru. |
| 3 | API buat bundle | ✅ `StoreBundleRequest` (`components[].variant_id`+`qty`) → `createOrUpdateBundle` → `saveBundle`. Pilih komponen = kirim `variant_id` (varian spesifik). |
| 4 | Stok bundle (derivasi + potong komponen) | ❌ **TIDAK ADA** — `StockService` tak mengenali bundle; jual bundle TIDAK memotong komponen → **risiko oversell**. |
| 5 | Guard (bundle-in-bundle, transaction-lock) | ❌ tidak ada |
| 6 | Satuan vs Varian | ✅ implisit by-count (`total_variants`) — sesuai Jubelio |
| 7 | Omnichannel bundle | ⚠️ mapper push bundle sebagai **1 SKU sendiri** — lihat koreksi di bawah |

### ⚠️ Koreksi atas audit (area 7)
Audit menyarankan "bundle harus didekomposisi jadi SKU komponen di marketplace". **Itu keliru**
menurut contoh Jubelio Anda: bundle `JELLY-TOSKA-2-IP-16` (item_id 32633) terdaftar di Lazada/
Shopee/Tokopedia sebagai **SKU tunggal miliknya sendiri** (punya `channel_item_id` sendiri),
BUKAN didekomposisi. Komposisi `bundles_variants` hanya untuk **potong stok internal**.
➡️ Mapper kita yang push bundle sebagai 1 SKU **sudah benar**. Yang kurang murni **sinkronisasi
stok** (stok bundle = turunan; saat terjual, potong komponen + re-sync stok produk terdampak).

---

## FASE B0 — Konsolidasi tabel komposisi + ekspos tipe

- Kanonikkan ke **`product_bundle_items`** (`bundle_product_id`, `component_variant_id`, `qty`).
  `product_bundles` (variant↔variant) → tandai deprecated; migrasikan data bila ada; arahkan
  semua penulis ke tabel kanonik (audit: ProductImportService & BundleController Inventory masih
  pakai tabel lama).
- `ProductResource` ekspos `product_type` turunan: `bundle` (is_bundle) | `variant` (>1 varian) |
  `single` (1 varian) + `total_variants` — agar FE jelas tanpa menebak.
- Test: resource mengembalikan product_type benar untuk 3 kasus.

## FASE B1 — Komposisi: pilih produk + varian + qty

- `StoreBundleRequest`/`UpdateBundle`: `components[].variant_id` (uuid, exists) + `qty>=1`;
  validasi varian **aktif** & milik produk non-bundle (lihat B2). Dukung memilih **varian
  spesifik** dari produk multi-varian (FE kirim variant_id varian terpilih).
- Detail bundle (`GET /products/{id}`) ekspos komposisi: per komponen tampilkan produk induk,
  varian (variation_values), qty, stok komponen — selaras `bundles_variants.compositions` Jubelio.
- Test: buat bundle dari 1 varian produk-A + 1 varian produk-B (multi-varian) → tersimpan benar.

## FASE B2 — Guard bisnis (anti error 23504 & 90003) 🔴

- **Bundle-in-bundle**: komponen tak boleh varian dari produk `is_bundle=true` → DomainException→422
  (padanan 23504).
- **Transaction-lock**: produk yang sudah punya transaksi (inventory ledger / order line / channel
  mapping aktif) tak boleh diubah jadi bundle → 422 (padanan 90003).
- Test: kedua guard menolak; happy path lolos.

## FASE B3 — Derivasi stok bundle 🔴

- `available_bundle = MIN atas komponen( floor(available_komponen / qty) )`.
  (Rumus komposit umum; spec Jubelio hanya definisikan komposisi, jadi rumus ini keputusan kita —
  didokumentasikan.)
- Ekspos di Inventory/stock resource + detail produk (read-only; bundle tak punya ledger sendiri).
- Test: komponen {A:10/1, B:3/1} → bundle=3; komponen qty>1 dihitung benar.

## FASE B4 — Potong stok komponen saat bundle terjual 🔴 (anti-oversell)

- `StockService` (reserve/pick/ship/cancel/restore): bila item adalah **varian bundle**, lakukan
  kaskade ke komponen × qty (reserve/pick/ship komponen; cancel/restore mengembalikan). Bundle
  sendiri TIDAK punya ledger fisik.
- Idempoten + transaksional (semua komponen atau none).
- Test: jual 2 bundle → tiap komponen berkurang qty×2; cancel mengembalikan; stok komponen kurang
  dari kebutuhan → ditolak/short sesuai kebijakan reserve.

## FASE B5 — Propagasi stok omnichannel

- Bundle listing = **1 SKU sendiri** (sudah benar) dengan stok = derivasi (B3).
- Saat stok komponen berubah (mis. bundle/komponen terjual), re-sync stok ke channel untuk:
  (a) produk komponen, (b) **semua bundle** yang memakai komponen itu. Pakai `SyncStockToChannelsJob`
  / `sync_price_stock` yang sudah ada; tambahkan resolusi "bundle terdampak".
- Test (mock adapter): jual bundle → dispatch sync stok untuk bundle + komponen terdampak.

## FASE B6 — Frontend

- Builder bundle: pilih **produk** → bila multi-varian, pilih **varian** mana → set qty (UI seperti
  contoh `compositions`). Tampilkan stok turunan bundle (read-only) + peringatan bila komponen habis.
- Tampilkan badge tipe produk (Satuan/Varian/Bundle) dari `product_type`.

---

## Urutan & prioritas
```
B0 (konsolidasi) → B1 (komposisi) → B2 (guard) → B3 (derivasi stok) → B4 (potong stok) → B5 (omnichannel) → B6 (FE)
```
Paling kritis bisnis: **B2 + B4** (anti error & anti-oversell). Tiap fase: implement → test → push.

## Catatan
- Mapper outbound bundle = SKU tunggal (JANGAN dekomposisi) — sesuai Jubelio.
- Stok bundle tak pernah ditulis langsung; selalu turunan → hindari sumber kebenaran ganda.
