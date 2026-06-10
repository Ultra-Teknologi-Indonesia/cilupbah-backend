# Planning: Tab "Download" (Progress & Hasil)

> Tujuan: melengkapi tab **Download** yang berisi 2 sub-tab —
> **Progress** (daftar transaksi download massal + detail progress) dan
> **Hasil** (`items/reviews` = produk hasil download untuk di-review). Memakai
> **UUID**, mengikuti `agents.md` & pola fitur Upload (Hasil/Draft) + Master Feed.
>
> Referensi Jubelio:
> - Progress: `core-api/...` daftar transaksi DWNLD + detail `catalog/v2/fetch-list-status/{storeId}?trxId=`
> - Hasil: `inventory/v2/items/reviews` (schema = `getProductsReviewResponse` di `dist (2).yaml`)

---

## 0. Struktur UI

```
Tab Download
├─ Progress  → daftar transaksi DWNLD-xxxx (download MASSAL) + progress bar
│              klik baris → detail (transaction + products[] + percent/state)
└─ Hasil     → items/reviews = produk hasil download menunggu review
               (struktur = Master Feed / example-master.json + qty)
```

Banner Progress: "Transaksi yang muncul di halaman Progres hanya untuk produk
yang didownload **massal**."

---

## 1. Kondisi Sekarang (audit)

| Komponen | Status |
|---|---|
| Aksi download `POST /v1/{channel}/download[/bulk]` (TikTok real, **sinkron**) | ✅ Ada |
| 1 `ProductSyncLog(action=download)` per operasi + per-item failure log | ✅ Ada |
| Inventory qty (`inventories.on_hand/on_order/available`) | ✅ Ada |
| `MasterItemResource` (struktur reviews) | ✅ Ada (reuse) |
| **Progress**: konsep transaksi (trx_no, total/all, progress %, on_process) | ❌ Belum |
| **Progress detail** (`fetch-list-status`) | ❌ Belum |
| **Hasil**: endpoint reviews (filter status download/review + qty) | ❌ Belum |
| Produk hasil download masuk status `download`? | ⚠️ Perlu verifikasi (default tabel `master`) |

---

## 2. Pemetaan Field

### 2.1 Progress — transaksi (Jubelio JSON)
| Field | Sumber/usulan |
|---|---|
| `trx_id` / `trx_no` | id (uuid) + nomor format `DWNLD-0000037` (sequence) |
| `executed_by` | user email |
| `store_name` / `store_id` | `channel_shops.shop_name` / `id` (uuid) |
| `channel_id` | `channels.id` (uuid) + `channel_code` |
| `created_date` | created_at |
| `is_downloaded` | selesai? |
| `on_download_process` | sedang berjalan? |
| `total_downloaded` / `all_product` | jumlah ter-download / total |

### 2.2 Progress detail (`fetch-list-status`)
`{ transaction:{progress_percent, trx_no, created_date, executed_by, date_from, date_to}, products:[...], count, percent, state }`

Tiap `products[]`: `channel_item_id, channel_group_id, item_name, img_url, item_code, variation_values[], store_id, channel_id, is_downloaded, is_master, on_process, is_error, error_message, status, master_item_code, master_item_name, master_thumbnail, item_id`.

### 2.3 Hasil (`items/reviews` = `getProductsReviewResponse`)
**Struktur identik Master Feed** (`item_group_id, item_name, last_modified, variations[], item_category_id, variants[], online_status[], thumbnail`) — **reuse `MasterItemResource`**, tambah qty per varian:
| Field reviews | Sumber |
|---|---|
| `variants[].end_qty` | `inventories.on_hand` (sum per varian) |
| `variants[].order_qty` | `inventories.on_order` |
| `variants[].available_qty` | `inventories.available` |

Filter: produk `status IN (download, in_review)`.

---

## 3. Rencana — dibagi 2 bagian

### BAGIAN A — Hasil sub-tab (lebih kecil, reuse Master Feed) — **dahulukan**

**A1. Resource**: `ReviewItemResource` (extend/komposisi `MasterItemResource`) +
`end_qty/order_qty/available_qty` per varian dari Inventory.
**A2. Repository**: `ReviewFeedRepository` — `QueryBuilder::for(Product)` filter
`status IN (download,in_review)`, eager-load (relasi master feed + inventory),
`allowedSearch('name','sku')`, default 10, appends.
**A3. Service + Controller + Route**: `GET /v1/products/reviews`.
**A4. Pastikan** produk hasil download diberi `status='download'` (lihat §6 Q2).
**A5. Tests**: struktur reviews + qty + filter status.

### BAGIAN B — Progress sub-tab (lebih besar: tabel + async)

**B1. Migration** `download_transactions`:
`id (uuid), trx_no (unik, DWNLD-xxxx), channel_shop_id (uuid), executed_by (uuid),
total_downloaded (int), all_product (int), state (enum: queued/downloading/done/failed),
is_downloaded (bool), on_download_process (bool), progress_percent (int),
date_from, date_to (nullable), error_message, timestamps`.
(Opsional `download_transaction_items` bila perlu jejak produk per transaksi untuk detail.)

**B2. Download jadi ASYNC**: ubah `download/bulk` agar membuat `DownloadTransaction`
(state=queued) lalu dispatch `DownloadProductsJob` yang memanggil
`pullProducts` sambil **meng-update** transaksi (all_product, total_downloaded,
progress_percent, state) — supaya progress bar UI nyata.

**B3. Repository + Service + Resource**:
- List Progress `GET /v1/download-transactions` (Spatie QueryBuilder, default 10).
- Detail `GET /v1/download-transactions/{id}` (`fetch-list-status`): transaksi +
  products[] (dari `download_transaction_items` atau derive via channel mappings).

**B4. Controller + Route + Tests.**

---

## 4. Endpoint (ringkas)

| Method & Path | Sub-tab | Fungsi |
|---|---|---|
| `GET /v1/products/reviews` | Hasil | Daftar produk hasil download (struktur master + qty) |
| `GET /v1/download-transactions` | Progress | Daftar transaksi DWNLD |
| `GET /v1/download-transactions/{id}` | Progress | Detail progress + produk per transaksi |
| `POST /v1/{channel}/download[/bulk]` | (aksi) | **Refactor** → async + buat transaksi |

---

## 5. Keputusan Desain (usulan)

1. **Progress → tabel baru `download_transactions`** (bukan perluasan
   `product_sync_logs`), karena butuh progress tracking + state + nomor transaksi.
2. **Download → ASYNC** (job update transaksi) agar progress bar nyata; saat ini
   sinkron.
3. **Hasil → reuse `MasterItemResource`** + qty inventory; filter status review.
4. **`channel_id`/`store_id` → UUID** + `channel_code`/`channel_name`.
5. **Param → Spatie/agents.md** (`search`, `per_page`=10, `sort=-field`, `filter[]`).
6. **agents.md**: Service-Repository, Spatie QueryBuilder, allowedSearch,
   default 10 + appends, ApiResponse, Resource.

---

## 6. Keputusan Final (terkunci)

1. **Urutan → Hasil (Bagian A) dulu**, lalu Progress (Bagian B).
2. **Status produk hasil download → `download`.** Set di mapper/`upsertFromChannel`
   agar masuk tab Hasil untuk di-review/merge ke master (lifecycle
   `download → in_review → master`). Perlu verifikasi & perbaiki jika sekarang
   default `master`.
3. **Download → ASYNC (job + progress).** Keharusan teknis: bulk download ribuan
   produk (5k–9k/toko) pasti timeout bila sinkron. `download/bulk` membuat
   `DownloadTransaction` lalu dispatch job yang update progress.

### Open Questions tersisa (Bagian B, diputuskan saat mulai Bagian B)
- **Detail Progress produk**: `download_transaction_items` (jejak eksplisit) vs
  derive dari produk/mapping yang dibuat saat transaksi.
- **`is_master`/`master_item_code`** di detail: pakai overlay dari fitur Merge
  yang sudah ada.

---

## 7. Urutan Eksekusi

```
Bagian A (Hasil): A1 resource → A2 repo → A3 service/controller/route → A4 status → A5 tests
Bagian B (Progress): B1 migration → B2 async job → B3 repo/service/resource → B4 controller/route/tests
```
