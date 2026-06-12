# Plan — Menyelesaikan Semua Item "In Progress" (45 item)

> **Disusun:** 2026-06-10 · **Sumber:** Dev Tracker (`/dev/tracking`, status=in_progress) · **Diverifikasi langsung ke kode** (routes/controllers tiap modul).
> **Pertanyaan kunci yang dijawab:** *"Apakah memang belum?"* → **Sebagian besar TIDAK murni belum** — fungsinya sudah ada, hanya belum cocok 1:1 dengan kontrak Jubelio.

---

## 1. Hasil Verifikasi (ringkas)

Dari 45 item in_progress, setelah dicek ke kode, terbagi 3 kategori:

| Kat | Arti | Jumlah | Effort |
|---|---|---:|---|
| **A** | **Fungsi sudah ADA** — tinggal alias route Jubelio + samakan response | 8 | S (≤2 jam/item) |
| **B** | **Parsial** — ada yang mirip, kurang varian/filter/listing/kontrak | 27 | M (½–1 hari/item) |
| **C** | **Memang BELUM** — tidak ada implementasinya | 10 | L (butuh logic baru) |

**Kesimpulan:** ~78% item in_progress (A+B = 35 item) adalah **pekerjaan adapter/last-mile**, bukan fitur baru. Hanya **10 item (C)** yang benar-benar perlu dibangun dari awal.

> Penyebab status "in_progress": modul **Outbound** (picklist/packlist/shipment) & **Sales/Inventory** sudah mengimplementasikan alur WMS, tapi dengan URL RESTful Cilupbah — bukan path Jubelio (`/wms/sales/...`, `/sales/picklists/...`).

---

## 2. Strategi Penyelesaian

Untuk semua item A & B, pola kerjanya sama (3 langkah):
1. **Compatibility route** — daftarkan path persis Jubelio → arahkan ke controller Cilupbah yang sudah ada.
2. **Response transformer** — bungkus output agar field-nya menyamai Jubelio (drop-in replacement).
3. **Validasi kontrak** — uji response terhadap schema `dist (2).yaml`.

Untuk item C — implementasi logic baru (service + controller method + migration bila perlu).

**Urutan kerja:** Kategori **A** (quick win, naikkan progress cepat) → **B** → **C**.

---

## 3. Daftar Lengkap 45 Item + Hasil Verifikasi & Task

### 🟩 Kategori A — Fungsi sudah ada, tinggal alias + samakan response (8)

| ID | Endpoint Jubelio | Fungsi sudah ada di | Task | PIC |
|---|---|---|---|---|
| 223 | `GET /sales/picklists/{picklist_id}` | `PicklistController@items` | alias + response | Rasyid |
| 229 | `GET /sales/packlists/` | `PacklistController@index` | alias + response | Rasyid |
| 231 | `GET /sales/packlists/{id}` | `PacklistController@show` | alias + response | Rasyid |
| 230 | `GET /sales/shipments/{shipment_header_id}` | `ShipmentController@show` | alias + response | Rasyid |
| 240 | `DELETE /sales/picklists/to-ship/` | `PicklistController@destroy` | alias | Rasyid |
| 261 | `POST /sales/shipments/orders/` | `ShipmentController@addOrders` | alias + response | Rasyid |
| 272 | `POST /sales/shipments/` | `ShipmentController@store` | alias + response | Rasyid |
| 28 | `GET /wms/sales/packlist/scan-order` | `PacklistController@items` | alias + response | Rasyid |

### 🟨 Kategori B — Parsial, perlu tambahan kecil (25)

**Sales / WMS fulfillment (modul Outbound) — 10**
| ID | Endpoint | Yang ada | Yang kurang | PIC |
|---|---|---|---|---|
| 234 | `GET /sales/packlists/shipped/` | `ordersByStage('shipped')` | samakan filter "shipped" | Rasyid |
| 239 | `POST /sales/picklists/items-to-pick` | `PicklistController@items` | kontrak by-POST | Rasyid |
| 273 | `POST /sales/picklists/items-to-pick/` | sda (duplikat slash) | sda | Rasyid |
| 250 | `GET /sales/returns/items/` | `SalesReturnController@index` | listing level-item | Rasyid |
| 252 | `GET /sales/returns/items/rejected/` | action `reject` ada | listing status rejected | Rasyid |
| 253 | `GET /sales/returns/items/resolved/` | action `complete` ada | listing status resolved | Rasyid |
| 13 | `GET /wms/sales/shipments/{courier_new_id}` | `ShipmentController@index` | filter per kurir | Rasyid |
| 24 | `GET /wms/sales/picklists/confirm-pick/` | flow picklist start/items | listing "on picking" | Rasyid |
| 27 | `GET /wms/sales/packlists/finish-pack/` | `ordersByStage` | listing "finish pack" | Rasyid |
| 40 | `GET /wms/sales/packlists/process/` | `ordersByStage` | listing "on packing" | Rasyid |

**Product / Product Listing — 6**
| ID | Endpoint | Yang ada | Yang kurang | PIC |
|---|---|---|---|---|
| 82 | `GET /inventory/categories/category-map/{id}` | `POST .../map-channel` (reverse) | endpoint GET mapping | Darriel |
| 78 | `GET .../{channel_id}/store-categories/{store_id}` | `ChannelCategoryController` | filter per store | Darriel |
| 102 | `POST /inventory/items/all-stocks/` | `InventoryController@stockProducts` | varian by-ids POST | Darriel |
| 106 | `GET /inventory/items/channel-category-attributes/` | `ChannelAttributeController` (per cat) | listing global | Darriel |
| 76 | `POST /inventory/catalog/listing` | `ProductChannelDraftController@store` | samakan kontrak | Darriel |
| 110 | `GET /inventory/items/errors/` | `ProductSyncLogController@uploadHistories` | filter error | Darriel |

**Inventory — 6**
| ID | Endpoint | Yang ada | Yang kurang | PIC |
|---|---|---|---|---|
| 74 | `GET /inventory/catalog/{group_id}` | `ProductMergeController@catalog` | alias + kontrak | Rasyid |
| 86 | `POST /inventory/catalog/set-master` | `ProductController@approve` | samakan aksi | Rasyid |
| 91 | `GET /inventory/items/by-transfer/{id}` | `InventoryTransactionController@transferShow` | alias | Rasyid |
| 116 | `POST /inventory/items/to-adjust/` | `InventoryController@itemsToStock` | varian cost+stok | Rasyid |
| 136 | `GET /inventory/stock-opname/items/filtered` | `StockOpnameController@items` | param filter rak | Rasyid |
| 152 | `GET /inventory/items/item-on-stock` | `InventoryController@itemsToStock` | alias | Rasyid |

**Contact / Setting / Channel / Purchase — 5**
| ID | Endpoint | Yang ada | Yang kurang | PIC |
|---|---|---|---|---|
| 56 | `GET /contacts/suppliers/` | `SupplierController@index` | alias (→ modul Contact) | Rasyid |
| 53 | `GET /contacts/{id}` | `SupplierController@show` | alias + model Contact | Rasyid |
| 166 | `GET /marketplace/store/` | `ChannelController@index` + `TikTokStore@index` | agregator multi-channel | Darriel |
| 164 | `GET /locations/store/` | `ChannelWarehouseController` | endpoint mapping lokasi↔store | Darriel |
| 179 | `GET /purchase/orders/progress` | `PurchaseOrderController@receivable` | samakan kontrak "progress" | Rasyid |

### 🟥 Kategori C — Memang belum ada, perlu implementasi baru (12)

| ID | Endpoint | Fungsi | Task | PIC |
|---|---|---|---|---|
| 29 | `POST /wms/sales/ready-to-process` | pindah order → ready to process | action transisi + service | Rasyid |
| 31 | `POST /wms/sales/ready-to-pick` | pindah order → ready to pick | action transisi + service | Rasyid |
| 270 | `POST /wms/order/getOrderByNo/` | lookup SO untuk dipick | endpoint lookup | Rasyid |
| 141 | `DELETE /inventory/transfers/` | hapus transfer stok | `@transferDestroy` | Rasyid |
| 146 | `GET /inventory/transfers/out-finished` | transfer selesai/diterima | filter + listing | Rasyid |
| 92 | `GET /inventory/item-bundles/` | list bundle produk | endpoint + model bundle | Darriel |
| 95 | `POST /inventory/items/` | buat/ubah bundle produk | service bundle | Darriel |
| 105 | `GET /inventory/items/by-sku/{sku}` | ambil produk per SKU | `@showBySku` | Darriel |
| 112 | `POST /inventory/items/prices/` | harga produk per banyak ID | price service | Darriel |
| 276 | `GET /taxes/` | daftar pajak | isi TaxController (kini stub) | Darriel |

> Catatan: 92 & 95 saling terkait (bundle), bisa 1 paket. 29 & 31 satu paket (transisi status order).

---

## 4. Rekap Beban Kerja per PIC

| PIC | A | B | C | Total | Estimasi |
|---|---:|---:|---:|---:|---|
| 🟢 **Rasyid** (Outbound/Sales/Inventory/Purchase/Contact) | 8 | 19 | 5 | 32 | ~6–8 hari |
| 🔵 **Darriel** (Product/Channel/Setting/Tax) | 0 | 8 | 5 | 13 | ~4–5 hari |

> Rasyid memikul mayoritas karena alur fulfillment (Outbound) banyak menyentuh kontrak `/wms/sales/...` & `/sales/...` Jubelio.

---

## 5. Rencana Sprint (saran)

**Sprint A — Quick wins alias (½ minggu)**
- Selesaikan 8 item Kategori A (Rasyid) + siapkan **compatibility route file** `routes/jubelio-compat.php` yang memetakan path Jubelio → controller eksisting.
- Target: progress in_progress turun 45 → 37.

**Sprint B — Adapter parsial (1–1.5 minggu)**
- 25 item Kategori B: tambah filter/listing/varian + response transformer.
- Paralel: Rasyid (fulfillment & inventory), Darriel (product & channel).
- Target: in_progress 37 → 10.

**Sprint C — Implementasi baru (1 minggu)**
- 10 item Kategori C: transisi status order (29,31), getOrderByNo (270), transfer delete/out-finished (141,146), bundle (92,95), by-sku (105), prices (112), taxes (276).
- Target: in_progress 10 → 0.

**Total: ~3 minggu** untuk menuntaskan seluruh in_progress menjadi done.

---

## 6. Definition of Done (per item)
1. Route Jubelio terdaftar (alias atau baru) & mengembalikan 200.
2. Response **menyamai struktur Jubelio** (field & nesting).
3. Validasi kontrak terhadap `dist (2).yaml` lulus.
4. Status di Dev Tracker (`/dev/tracking`) di-set **done** + notes berisi ringkasan implementasi.
5. Minimal 1 feature test (happy path).

---

## 7. Langkah Pertama yang Disarankan
Mulai dari **Kategori A** karena ROI tertinggi (fungsi sudah jadi). Buat satu file `routes/jubelio-compat.php` lalu daftarkan 8 alias pertama. Ini langsung menaikkan angka "done" di tracker tanpa logic baru.
