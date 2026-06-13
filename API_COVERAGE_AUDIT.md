# Audit Cakupan API — WMS Omnichannel vs Jubelio

> Tanggal: 2026-06-13
> Referensi: `dist (3).yaml` (api.jubelio.com, 221 ops) + `dist (2).yaml` (api2.jubelio.com, 287 ops) → **294 operasi unik** Jubelio.
> Sistem kita: **515 operasi API** (`/api/v1/*`) terpasang.
> Metode: pemetaan berbasis **kapabilitas** (bukan kecocokan path), diverifikasi langsung ke route + controller. Catatan: spec Jubelio **tidak memuat semua** API (sebagian tidak dipublikasikan), jadi daftar ini = gap terhadap yang dipublikasikan + roadmap channel.

---

## Ringkasan Eksekutif

Cakupan kita **sangat tinggi (~90%+ kapabilitas)**. Domain inti **Purchase**, **Master Data/Finance**, **Sales + WMS Fulfillment**, **Category/Attribute**, **Reports**, dan **Webhook/Channel (TikTok+Lazada)** pada dasarnya **lengkap**.

Gap riil yang tersisa kecil dan terkelompok di: **stock activity log**, **beberapa system-setting**, **sebagian catalog-by-group**, dan **channel selain TikTok/Lazada** (Shopee/Tokopedia/Blibli — memang belum dibangun).

### Koreksi penting (agar tidak salah baca)
Beberapa endpoint Jubelio yang "kelihatan hilang" ternyata **SUDAH ADA** dengan path/bentuk berbeda — sudah saya verifikasi:

| Jubelio | Status nyata di kita |
|---|---|
| `/wms/sales/orders/{empty-stock,failed-pick,finish-pick,ready-to-ship,shipped}` | ✅ `GET /api/v1/outbound/orders/{stage}` — controller mendukung enum stage: `ready-to-process, ready-to-pick, on-picking, finish-pick, failed-pick, on-packing, finish-pack, ready-to-ship, shipped, empty-stock, request-cancel` |
| `POST /inventory/items/received/{putaway,auto-putaway,finish-putaway,author}` | ✅ Modul **Inbounds + Putaway** (`/api/v1/inbounds*`, `/api/v1/putaway*`) |
| `GET /lazada/get-shipment-providers/{storeId}` | ✅ `GET /api/v1/lazada/logistics` (+ `/lazada/get-document`) |
| `POST /webhooks/{event}` (9 tipe) | ✅ Langganan webhook via `POST /api/v1/systemsetting/webhook` + CRUD `/api/v1/webhooks` (event di body) |
| `/inventory/categories/{id}/attributes`, `/variations`, `channel-categories` | ✅ Sistem master `/categories` + `/attributes` + `map-channel` + `/{channel}/categories` |
| `/inventory/items/masters`, `group/merge-catalog` | ✅ `/api/v1/products/master*`, `/products/merge/catalog` |

---

## Cakupan per Domain

| Domain | Jubelio ops | Status | Catatan |
|---|---|---|---|
| Purchase (orders/bills/payments/returns/settlements/serial) | 30 | ✅ ~100% | Tidak ada gap |
| Master data & finance (contacts/region/locations/taxes/cashbank/journal/couriers/variations) | 34 | ✅ ~100% | Tidak ada gap |
| Sales + WMS fulfillment (orders/invoices/payments/picklist/packlist/shipment/return/settlement) | 96 | ✅ ~98% | Queue via `{stage}`; sisa hanya beda bentuk |
| Inventory — stok (opname/transfer/putaway/adjustment/promotion/reserved/revaluation) | 50 | 🟡 ~88% | Gap: **activity log**, view transfer berarah |
| Inventory — items/catalog/brand | 51 | 🟡 ~80% | Gap: sebagian **catalog-by-group**, `to-buy` |
| Integrasi/Settings/Reports/Channel | 33 | 🟡 ~75% | Gap: **systemsetting** tertentu, **channel non-TikTok/Lazada** |

---

## 🔴 Gap Prioritas TINGGI — ✅ SELESAI DIIMPLEMENTASI (2026-06-13)

### G-1. `GET /inventory/activity/` — Riwayat pergerakan stok — ✅ SELESAI
Ternyata kapabilitas sudah ada sebagai `GET /api/v1/inventory/movements` (tabel `inventory_movements`, ledger riil yang diisi tiap mutasi stok). Ditambahkan:
- Alias paritas Jubelio: **`GET /api/v1/inventory/activity`** → `InventoryController@movements` (filter Spatie QB: `item_id`, `location_id`, `source`, `transaction_number`, `date_from`, `date_to`).
- Polish best-practice: `InventoryMovementResource` (tidak lagi model mentah) + honor `per_page` (default 10) + `appends()`, backward-compatible dengan `limit`.
- Tes: `InventoryActivityTest` (auth, paginasi, filter item/source, per_page).

### G-2. `GET/POST /systemsetting/account-mapping` — Pemetaan akun akuntansi — ✅ SELESAI
Akun GL yang tadinya **hardcoded** di `AutoJournalService` kini dapat dikonfigurasi:
- Tabel `account_mappings` + model + `AccountMappingKey` (4 slot: `sales_revenue`, `accounts_receivable`, `inventory`, `accounts_payable` dengan default 4-4000/1-1100/1-1200/2-2000).
- `GET/POST /api/v1/systemsetting/account-mapping` (Repository + Service + Resource + Request validation → 422, bukan 500).
- **Integrasi**: `AutoJournalService` resolve akun via mapping → fallback ke kode default (perilaku/tes lama tetap hijau), tetap **fail-open** (tak pernah 500 di domain).
- Tes: `AccountMappingApiTest` (auth, default, validasi, persist, jurnal otomatis memakai akun ter-mapping).

> Catatan: pemetaan kas/bank per `payment_method` masih via `config/finance` (cashbank) — bisa ditarik ke tabel mapping menyusul bila diperlukan.

---

## 🟡 Gap Prioritas MENENGAH — ✅ SELESAI DIIMPLEMENTASI (2026-06-13)

### G-3. `GET/POST /systemsetting/sales-return-setting` — ✅ SELESAI
Tabel singleton `sales_return_settings` (auto_accept, default_restock_location_id, allowed_conditions, allowed_refund_methods, return_validity_days) + Service/Repository/Resource/Request, route GET/POST. **Di-wire ke flow retur**: validasi `condition` & `refund_method` dinamis dari setting (→422), lokasi restock default di `accept()`, auto-accept (fail-open), batas hari retur. **Plus jurnal retur**: key mapping `sales_return` (default 4-4200), akun `4-4200 Retur Penjualan` di-seed, `SalesReturnRefundJournalObserver` → Dr Retur Penjualan / Cr Kas-Bank (idempoten, fail-open). Tes: `SalesReturnSettingTest`.

### G-4. Catalog-by-group — ✅ SELESAI
- `GET /inventory/catalog/{group_id}` (item dalam grup `ProductMerge.master_name`, Spatie QB, paginate 10) — group tak dikenal → 200 kosong.
- `GET /inventory/catalog/for-listing/{id}` & `GET /inventory/items/group/{id}` (alias) — detail produk+varian untuk listing (whereUuid → 404).
- `POST /inventory/catalog/upload` → **alias** `bulkUpload` yang sudah ada.
- `POST /inventory/catalog/` (create) — **dilewati** (sudah tercakup endpoint create produk eksisting; keputusan owner). Tes: `CatalogReadTest`.

### G-5. View transfer berarah — ✅ SELESAI
`GET /inventory/transfers/{in,out,all-transit}` dengan reuse Spatie QB `InventoryTransferRepository`. `in`/`out` butuh `location_id` (uuid, `bail|exists` → 422 bukan 500). `all-transit` = semua IN_TRANSIT. Tes: `TransferDirectionTest`.

---

## 🟢 Gap RIIL — Prioritas RENDAH

- `GET /inventory/items/to-buy` — saran pembelian/reorder. Mirip `inventory/need-restock` (sudah ada) — verifikasi apakah perlu varian terpisah.
- `GET /inventory/search-brands/` — kemungkinan tercakup `GET /api/v1/brands?search=`. Verifikasi.
- `GET /systemsetting/users/` — alias dari `GET /api/v1/users` (sudah ada). Trivial.
- `POST /wms/shipment-detail/` — kemungkinan tercakup `POST /api/v1/outbound/shipments/scan` / `get-by-no`. **Verifikasi** semantik.

---

## 🧭 ROADMAP — Channel selain TikTok/Lazada (di luar scope saat ini)

Sistem baru mengimplementasi adapter **TikTok** & **Lazada**. Untuk omnichannel penuh ala Jubelio, belum ada:

- **Shopee** — `GET /shopee/logistics` (+ adapter, OAuth, webhook, sync order/produk/stok).
- **Tokopedia** — `GET /tokopedia/showcases` (+ adapter penuh).
- **Blibli** — `GET /blibli/pickupPoints` (+ adapter penuh).

> Sesuai arahan "fokus TikTok & Lazada", ini ditandai sebagai roadmap, bukan gap mendesak. Pola `MarketplaceAdapterInterface` + `AdapterFactory` yang sudah ada memudahkan penambahan.

---

## Catatan: spec Jubelio tidak lengkap
Karena sebagian API Jubelio tidak dipublikasikan di kedua YAML, beberapa kapabilitas WMS-omnichannel yang **perlu diverifikasi keberadaannya** (kemungkinan dibutuhkan walau tak ada di spec):
1. **Penerima webhook masuk per channel** — TikTok ✅ & Lazada ✅ sudah ada; Shopee/Tokopedia/Blibli mengikuti saat adapter dibangun.
2. **Bulk stock/price sync per channel** — sebagian ada (`tiktok/sync/*`, `lazada/sync/*`); pastikan paritas antar channel saat ekspansi.
3. **Endpoint cetak dokumen** (label/manifest/surat jalan) — sudah ada di `/reports/*` (12 endpoint, termasuk `wms/pick-list`, `wms/shipping-manifest`, `shipping-label`).

---

## Lampiran — Metodologi
- 294 op Jubelio diekstrak dari kedua YAML (`paths` × method), dibagi 6 domain, dipetakan paralel ke 515 route kita, lalu **diverifikasi manual** untuk menghindari false-positive (lihat tabel Koreksi).
- File kerja: daftar Jubelio per domain & daftar route kita disusun saat audit (tidak di-commit).
- Tidak ada perubahan kode pada audit ini.
