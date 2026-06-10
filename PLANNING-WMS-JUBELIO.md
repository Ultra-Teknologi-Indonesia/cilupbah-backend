# Planning Backend WMS — Cilupbah (Clone Jubelio API)

> **Disusun:** 2026-06-10 · **Branch aktif:** `27-product` · **Baseline acuan:** `dist (2).yaml` (254 endpoint, superset — termasuk WMS/Inbound/Outbound) & `dist (3).yaml` (188 endpoint, subset omnichannel)
> **Metode:** Scan kode aktual (routes, controllers, services, migrations, tests) per modul, lalu di-mapping terhadap cakupan spec Jubelio.
> **Tujuan dokumen:** Memberi gambaran jelas **apa yang sudah selesai, sedang berjalan, dan belum dikerjakan** — per modul & per fitur.

---

## 1. Executive Summary

Cilupbah-be adalah **Laravel modular monolith** (nwidart/laravel-modules) dengan **18 modul**. Target: meng-clone cakupan API Jubelio (WMS + Omnichannel + Accounting ringan).

**Status portfolio (RAG):** 🟡 **AMBER** — fondasi WMS inti & katalog produk sudah kuat, tetapi domain **Accounting (Journal, Cash & Bank, Purchasing penuh, Sales Invoice/Payment)** dan **multi-marketplace selain TikTok** masih kosong/stub.

| Dimensi | Skor | Catatan |
|---|---|---|
| Cakupan WMS Core (Inbound→Putaway→Pick→Pack→Ship) | 🟢 ~80% | Alur fulfillment end-to-end sudah jalan |
| Katalog Produk & Listing | 🟢 ~85% | Modul terkuat (38 migration, 19 test) |
| Omnichannel | 🟡 ~25% | Hanya TikTok yang jalan; Shopee/Tokopedia/Lazada/Blibli belum |
| Sales (Order→Invoice→Payment→Return) | 🟡 ~40% | Order & Return ada; Invoice/Payment/Settlement belum |
| Purchasing (PO→Bill→Payment→Return) | 🟡 ~30% | Hanya PO; Bill/Payment/Return belum |
| Accounting (Journal, Cash & Bank, Accounts) | 🔴 ~0% | Modul Finance masih stub (return blade view) |
| Reports | 🔴 ~5% | Modul Report stub; report WMS tersebar belum ada |

**Estimasi penyelesaian penuh vs Jubelio:** ± 6–8 minggu (lihat §6 Roadmap).

---

## 2. Status Legend

| Simbol | Arti | Kriteria objektif |
|---|---|---|
| ✅ DONE | Selesai & berfungsi | Controller riil + service + migration + (idealnya) test |
| 🔄 IN PROGRESS | Sebagian jalan | Sebagian endpoint/alur ada, sebagian gap besar |
| 🟥 STUB | Scaffold kosong | Hanya `apiResource`, controller return blade view, tanpa model/migration |
| ⬜ NOT STARTED | Belum ada | Tidak ada modul/endpoint sama sekali |

---

## 3. Inventaris Modul (hasil scan kode aktual)

| Modul | Controllers | Services | Migrations | Tests | Status |
|---|---:|---:|---:|---:|---|
| **Product** | 18 | 20 | 38 | 19 | ✅ DONE |
| **Inventory** | 6 | 5 | 14 | 0 | ✅ DONE |
| **Outbound** | 6 | 5 | 10 | 0 | ✅ DONE |
| **Channel** (TikTok) | 7 | 11 | 4 | 3 | ✅ DONE (TikTok only) |
| **Warehouse** | 4 | 3 | 7 | 5 | ✅ DONE |
| **Inbound** | 1 | 1 | 9 | 0 | ✅ DONE |
| **Auth** (RBAC) | 4 | 4 | 0¹ | 0 | ✅ DONE |
| **Sales** | 2 | 3 | 7 | 0 | 🔄 IN PROGRESS |
| **Purchase** | 1 | 1 | 2 | 0 | 🔄 IN PROGRESS |
| **Region** | 1 | 1 | 4 | 0 | ✅ DONE |
| **Supplier** | 1 | 1 | 2 | 0 | 🔄 IN PROGRESS |
| **Warranty** | 1 | 1 | 2 | 0 | 🔄 (di luar core Jubelio) |
| **Tax** | 1 | 0 | 0 | 0 | 🟥 STUB |
| **Finance** | 1 | 0 | 0 | 0 | 🟥 STUB |
| **Report** | 1 | 0 | 0 | 0 | 🟥 STUB |
| **Notification** | 1 | 0 | 0 | 0 | 🟥 STUB |
| **Webhook** | 1 | 0 | 0 | 0 | 🟥 STUB |
| **AI** | 1 | 0 | 0 | 0 | 🟥 STUB (di luar Jubelio) |

¹ Auth memakai migration default Laravel/Sanctum di `database/migrations`, bukan per-modul.

---

## 4. Rincian Per Modul — DONE / IN PROGRESS / BELUM

### ✅ 4.1 Product & Product Listing — DONE (~85%)
**Sudah ada:** CRUD produk, kategori, brand, attribute, media upload; alur review (`submit-review`/`approve`/`reject`/`archive`/`restore`); **Master Feed / Review Feed / Archive Feed / Channel Product Listing**; **Product Merge** (auto, apply, bulk, unmerge, hide); **Channel Draft** + bulk upload ke channel; **Product Import** (single & bundle template); **Channel Monitor**; mapping kategori/atribut ke channel; **Raise Product**.
**Acuan Jubelio:** tag `Product`, `Product Listing` — *terpenuhi*.
**Gap:** `/variations` standalone, `/inventory/internal-price-list`, `/inventory/price-list`, `/inventory/promotions`, `/inventory/revaluations` (price list & promo belum).

### ✅ 4.2 Inventory — DONE (~80%)
**Sudah ada:** stok per item/lokasi, movements, history, items-to-stock; **Adjustment** (+ dokumen approve/cancel); **Putaway** (assign-staff, start, process, complete + not-started/in-progress/completed); **Stock Opname** lengkap (bins/floors/rows/columns, start, count, finalize, cancel, mark-printed); **Reserved Stock**; **Transfer** (out/in/transit/receive).
**Gap vs Jubelio dist(2):** `/inventory/item-bundles`, `/inventory/promotions`, `/inventory/revaluations`, `/inventory/need-restock`, `/inventory/out-of-stock-in-order`, `/inventory/catalog/*` (set-master/upload), `/inventory/items/batch-number`, `split-item`, `complete-return`/`reject-return`.

### ✅ 4.3 Warehouse / Location & Rack Plan — DONE
**Sudah ada:** CRUD location, zones, bins (+ preview generator, default-bin), channel-warehouse mapping.
**Acuan:** tag `Location & The Rack Plan` — *terpenuhi*. **Gap:** `/locations/pos`, `/locations/store`, `/store-locations`, `/marketplace/store`.

### ✅ 4.4 Inbound — DONE
**Sudah ada:** alur inbound penuh — create, assign, receive, close-receiving, putaway/auto-putaway, scan QR, scan-putaway, my-assignments, received-items, cancel.
**Acuan:** tag `Inbound Process` — *terpenuhi*.

### ✅ 4.5 Outbound & Couriers — DONE
**Sudah ada:** Picklist (assign-picker, start, pick-item, complete/fail/cancel); Packlist (assign-packer, pack-item, verify-barcode, complete); Shipment (scan, add/remove orders, save-awb, hand-over, cancel); Courier CRUD; WMS employee & default-bin; orders by stage, change-location, request-cancel.
**Acuan:** tag `Outbound Process`, `Couriers`, `WMS` — *sebagian besar terpenuhi*.
**Gap:** `/wms/sales/shipments/instant/all`, `/wms/sales/shipments/completed/...`, shipping manifest, instant-courier; integrasi label printing.

### ✅ 4.6 Auth (RBAC) — DONE
**Sudah ada:** login/logout (Sanctum), profile, roles, users (+export, histories, force-logout, bulk-force-logout), permissions.
**Acuan:** tag `Authentication`, `System Setting > users` — *terpenuhi*.

### ✅ 4.7 Region — DONE
provinces / cities / districts / villages. **Acuan:** tag `Region` — *terpenuhi* (Jubelio pakai subdistricts; penamaan villages perlu diselaraskan).

### 🔄 4.8 Sales — IN PROGRESS (~40%)
**Sudah ada:** Sales Order (index, store, show, destroy); **Sales Return** lengkap (unprocessed, accept, reject, complete).
**BELUM (gap besar vs Jubelio):**
- `/sales/invoices/*` (invoice, unpaid, overdue, summary, for-return-wms) ⬜
- `/sales/payments/*` ⬜
- `/sales/settlements/*` & `/sales/return-settlements/*` (refunds) ⬜
- `/sales/orders/*` lanjutan: cancel, completed, failed, mark-as-complete, save-airwaybill, set-as-paid, request-awb ⬜
- `/sales/packlists/create-invoice`, `create-invoice-payment` ⬜
- `/sales/unfullfilled` ⬜

### 🔄 4.9 Purchase / Purchasing — IN PROGRESS (~30%)
**Sudah ada:** Purchase Order (index, receivable, show, store, approve, receive, cancel, destroy).
**BELUM (gap besar):**
- `/purchase/bills/*` (bill, unpaid, overdue, for-return) ⬜
- `/purchase/payments/*` ⬜
- `/purchase/purchase-returns/*` ⬜
- `/purchase/return-settlements/*` (bills, refunds) ⬜
- `/purchase/serial-number/*` (wms, mark-printed) ⬜

### 🔄 4.10 Supplier — IN PROGRESS
Model + controller (155 baris) ada, tapi Jubelio menempatkan supplier di bawah `/contacts/suppliers` & `/contacts/customers-suppliers`. **Perlu:** modul **Contact** terpadu (category, customers, suppliers, customers-suppliers).

### 🟥 4.11 Finance (Journal + Cash & Bank + Accounts) — STUB (~0%)
Controller hanya `return view('finance::index')`, `store(){}` kosong, **tanpa model/migration**.
**BELUM (semua):**
- `/journal/`, `/journal/manual-journal`, `/journal/{id}` ⬜
- `/cashbank/payments`, `/cashbank/receives` ⬜
- `/accounts/lookup/all` ⬜
- `/taxes/` (modul Tax juga masih thin) ⬜
**Acuan:** tag `Journal`, `Cash & Bank` — *belum terpenuhi sama sekali*.

### 🟥 4.12 Report — STUB (~5%)
Controller stub. Beberapa report tersebar di Jubelio:
`/reports/adjustment`, `/reports/putaway`, `/reports/receive`, `/reports/stock-opname`, `/reports/purchaseorder`, `/reports/invoice`, `/reports/consign`, `/reports/wms/pick-list`, `/reports/wms/shipping-manifest`, `/reports/shipping-label`, `/reports/lable/print`, `/reports/item-receive-notplace` — **semua ⬜**.

### 🟥 4.13 Webhook & System Setting — STUB
Webhook modul stub (return view). TikTok webhook ada di modul Channel, tapi webhook **outbound Jubelio-style** belum:
`/webhooks/invoice`, `/payment`, `/price`, `/product`, `/purchaseorder`, `/salesorder`, `/salesreturn`, `/stock`, `/stocktransfer` — **⬜**.
System Setting: `/systemsetting/account-mapping`, `/sales-return-setting`, `/webhook` — **⬜**.

---

## 5. Gap Analysis Omnichannel (acuan `dist (3).yaml`)

| Channel | Status | Bukti di kode |
|---|---|---|
| **TikTok Shop** | ✅ DONE | 7 controller, OAuth, webhook, pull/push order & product, auto-sync, bulk-push |
| **Shopee** | ⬜ NOT STARTED | spec: `/shopee/logistics` |
| **Tokopedia** | ⬜ NOT STARTED | spec: `/tokopedia/showcases` |
| **Lazada** | ⬜ NOT STARTED | spec: `/lazada/get-document`, `/get-shipment-providers` |
| **Blibli** | ⬜ NOT STARTED | spec: `/blibli/pickupPoints` |
| **Generic Channel** | 🔄 | `ChannelController` CRUD + download-transactions ada |

---

## 6. Roadmap (Prioritized — WSJF-style)

Prioritas = (Nilai bisnis + Urgensi + Pengurangan risiko) ÷ Ukuran. Disusun agar modul yang **memblokir alur uang & operasional** didahulukan.

### 🥇 Sprint 1–2 — Tutup alur transaksi inti (HIGH)
1. **Sales: Invoice + Payment + Settlement** — tanpa ini alur jualan tidak nyambung ke uang. *(WSJF tinggi)*
2. **Purchase: Bill + Payment + Purchase Return** — pasangan dari PO yang sudah ada.
3. **Contact module** (customers/suppliers/customers-suppliers) — dependency Sales & Purchase.

### 🥈 Sprint 3–4 — Accounting & Reports (MEDIUM-HIGH)
4. **Finance: Journal + Cash & Bank + Accounts lookup** — naikkan dari STUB ke fungsional.
5. **Tax** — fungsionalkan untuk perhitungan invoice/bill.
6. **Reports WMS** (pick-list, putaway, receive, stock-opname, shipping-manifest, adjustment).

### 🥉 Sprint 5–6 — Omnichannel expansion (MEDIUM)
7. **Shopee** → **Tokopedia** (volume pasar terbesar setelah TikTok).
8. **Lazada** → **Blibli**.
9. **Webhook outbound** Jubelio-style (stock, salesorder, invoice, payment, dst).

### Backlog — Polish (LOW)
10. Inventory extended: item-bundles, promotions, revaluations, need-restock, price-list.
11. System Setting (account-mapping, sales-return-setting).
12. Notification fungsional (saat ini stub).

---

## 7. Risiko Utama

| Risiko | Kategori | Dampak | Mitigasi |
|---|---|---|---|
| Modul Finance/Journal kosong total → tidak bisa rekonsiliasi keuangan | Financial | 🔴 Tinggi | Prioritaskan Sprint 3; definisikan chart-of-accounts dulu |
| Sales/Purchase setengah jalan → data invoice/bill menggantung | Schedule | 🟠 Sedang | Selesaikan pasangan Order→Invoice→Payment per modul |
| Hanya 3 modul punya test (Product, Channel, Warehouse) | Technical/Quality | 🟠 Sedang | Tambah test untuk Inventory, Outbound, Inbound, Sales |
| Multi-marketplace tiap channel beda API & rate limit | Technical | 🟠 Sedang | Pola adapter dari Channel/TikTok yang sudah ada |
| Migrasi UUIDv7 sedang berjalan (lihat `PLAN-UUID-MIGRATION.md`) | Technical | 🟡 Rendah | Selesaikan sebelum ekspansi modul baru |

---

## 8. Progress Tracker

| Domain Jubelio | Target | Selesai | % | Status |
|---|---|---|---|---|
| Product & Listing | ✓ | ✓ | 85% | 🟢 |
| Inventory | ✓ | ✓ | 80% | 🟢 |
| WMS Inbound | ✓ | ✓ | 95% | 🟢 |
| WMS Outbound + Couriers | ✓ | ✓ | 80% | 🟢 |
| Location & Rack Plan | ✓ | ✓ | 85% | 🟢 |
| Stock Adjustment & Opname | ✓ | ✓ | 90% | 🟢 |
| Auth / Users | ✓ | ✓ | 90% | 🟢 |
| Region | ✓ | ✓ | 90% | 🟢 |
| Sales (full cycle) | ✓ | partial | 40% | 🟡 |
| Purchasing (full cycle) | ✓ | partial | 30% | 🟡 |
| Contact | ✓ | partial | 20% | 🟡 |
| Channels — TikTok | ✓ | ✓ | 90% | 🟢 |
| Channels — Shopee/Tokopedia/Lazada/Blibli | ✓ | ✗ | 0% | 🔴 |
| Journal | ✓ | ✗ | 0% | 🔴 |
| Cash & Bank | ✓ | ✗ | 0% | 🔴 |
| Tax | ✓ | stub | 5% | 🔴 |
| Reports | ✓ | ✗ | 5% | 🔴 |
| Webhooks (outbound) | ✓ | ✗ | 10% | 🔴 |
| System Setting | ✓ | ✗ | 5% | 🔴 |

**Overall vs Jubelio dist(2):** ± **55–60%** cakupan endpoint inti.

---

## 9. Catatan Metodologi
- Angka % adalah estimasi berbasis rasio endpoint terimplementasi vs endpoint spec per domain, dikonfirmasi terhadap kedalaman kode (controller/service/migration).
- `dist (2).yaml` dipakai sebagai **baseline utama** karena superset (mencakup WMS yang tidak ada di dist(3)).
- Dokumen lama terkait: `MILESTONE.md`, `plan-wms-enhancement.md`, `PLAN-PRODUCT-MERGE.md`, `PLAN-UUID-MIGRATION.md`.
- Modul **AI** & **Warranty** berada di luar cakupan Jubelio (fitur tambahan Cilupbah) — tidak dihitung dalam % kesetaraan.
