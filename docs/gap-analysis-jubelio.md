# Gap Analysis: Superapp vs Jubelio API

Analisa perbandingan fitur project superapp terhadap Jubelio WMS API (berdasarkan `dist (2).yaml` dan `dist (3).yaml`).

*Updated: 2026-06-08*

---

## SUDAH ADA (Implemented)

| Domain | Fitur | Status |
|--------|-------|--------|
| **Auth** | Login, Users, Roles, Permissions | ✅ Lengkap |
| **Product** | CRUD Products, Variants, Categories, Brands, Attributes, Channel Mapping, Import | ✅ Lengkap |
| **Inventory** | Stock per location, Stock movements/history, Reserved stock | ✅ Lengkap |
| **Inventory Transfer** | Transfer out, Transit, Transfer in (receive) | ✅ Lengkap |
| **Stock Adjustment** | Document-based adjustment (create, approve, cancel) | ✅ Lengkap |
| **Stock Opname** | Create, items, filter, finalize, bins/floors/rows/columns | ✅ Lengkap |
| **Putaway** | WMS putaway flow (assign, start, process item, complete) | ✅ Lengkap |
| **Inbound** | Receiving (assign, start, receive items, close, putaway, auto-putaway) | ✅ Lengkap |
| **Outbound** | Pick/Pack/Ship, queue views (11 stages), failed-pick, empty-stock | ✅ Lengkap |
| **Couriers** | CRUD, list all active | ✅ Lengkap |
| **WMS Utilities** | Employee lookup, default bin per location | ✅ Lengkap |
| **Shipment** | Create, scan, add/remove orders, save AWB, hand-over, cancel | ✅ Lengkap |
| **Order (WMS)** | Change location, request cancel, ready-to-ship | ✅ Lengkap |
| **Purchase** | PO create, approve, receive, cancel | ✅ Cukup |
| **Sales Return** | Create, accept, reject, complete | ✅ Cukup |
| **Orders** | CRUD Sales Orders + status transitions | ✅ Basic |
| **Warehouse** | Locations, Zones, Bins, Channel Warehouse mapping | ✅ Lengkap |
| **Region** | Provinces, Cities, Districts, Villages | ✅ Lengkap |
| **Channel** | Channel CRUD, TikTok integration (auth, sync, webhook) | ✅ Partial |
| **Webhook** | CRUD webhook endpoints | ✅ Basic |
| **Supplier** | CRUD | ✅ Basic |
| **Tax** | CRUD | ✅ Basic |
| **Notification** | Basic | ⚠️ Skeleton only |
| **Report** | Basic CRUD | ⚠️ Skeleton only |

---

## BELUM ADA / KURANG (Missing Features)

### ~~1. WMS Fulfillment Flow~~ ✅ DONE
### ~~2. Stock Opname~~ ✅ DONE
### ~~6. Couriers~~ ✅ DONE

---

### 3. Sales Full Cycle

| Fitur | Jubelio Endpoint | Status |
|-------|-----------------|--------|
| **Invoice** - List/Create/Show | `/sales/invoices/` | ❌ |
| **Invoice** - Overdue/Unpaid/Summary | `/sales/invoices/overdue,unpaid,summary` | ❌ |
| **Invoice** - For return WMS | `/sales/invoices/for-return-wms/{contact_id}` | ❌ |
| **Payment** - Sales payments | `/sales/payments/` | ❌ |
| **Settlement** - Sales settlements | `/sales/settlements/` | ❌ |
| **Order** - Set as paid | `/sales/orders/set-as-paid` | ❌ |
| **Order** - Mark complete | `/sales/orders/mark-as-complete` | ❌ |
| **Order** - Returned list | `/sales/orders/returned-list/` | ❌ |
| **Order** - Request AWB | `/sales/request-awb-order/` | ❌ |
| **Return Settlement** | `/sales/return-settlements/` | ❌ |
| **Unfulfilled** | `/sales/unfullfilled/` | ❌ |
| ~~**Order** - Save AWB~~ | ~~`/sales/orders/save-airwaybill/`~~ | ✅ via outbound |
| ~~**Order** - Cancel/Complete/Failed~~ | ~~`/sales/orders/cancel,completed,failed`~~ | ✅ via outbound |
| ~~**Order** - Ready to pick~~ | ~~`/sales/orders/ready-to-pick/`~~ | ✅ via outbound |

---

### 4. Purchase Full Cycle

| Fitur | Jubelio Endpoint | Status |
|-------|-----------------|--------|
| **Bills** - CRUD | `/purchase/bills/` | ❌ |
| **Bills** - Overdue/Unpaid/For-return | `/purchase/bills/overdue,unpaid,for-return` | ❌ |
| **Payments** - Purchase payments | `/purchase/payments/` | ❌ |
| **Purchase Returns** | `/purchase/purchase-returns/` | ❌ |
| **Return Settlements** | `/purchase/return-settlements/` | ❌ |
| **Serial Number** - WMS | `/purchase/serial-number/wms/{bill_detail_id}` | ❌ |

---

### 5. Contacts / CRM

| Fitur | Jubelio Endpoint | Status |
|-------|-----------------|--------|
| Contact list | `/contacts/` | ❌ |
| Contact by ID | `/contacts/{id}` | ❌ |
| Customers only | `/contacts/customers/` | ❌ |
| Suppliers only | `/contacts/suppliers/` | ❌ |
| Combined list | `/contacts/customers-suppliers/` | ❌ |
| Contact categories | `/contact/category/` | ❌ |

---

### 7. Accounting / Journal

Module Finance masih skeleton, belum ada implementasi.

| Fitur | Jubelio Endpoint | Status |
|-------|-----------------|--------|
| Journal entries | `/journal/` | ❌ |
| Manual journal | `/journal/manual-journal/` | ❌ |
| Journal by ID | `/journal/{id}` | ❌ |
| Cash & Bank payments | `/cashbank/payments/` | ❌ |
| Cash & Bank receives | `/cashbank/receives` | ❌ |
| Account mapping | `/systemsetting/account-mapping` | ❌ |

---

### 8. Reports

Module Report masih skeleton generic, belum ada report spesifik.

| Fitur | Jubelio Endpoint | Status |
|-------|-----------------|--------|
| Report adjustment | `/reports/adjustment` | ❌ |
| Report invoice | `/reports/invoice` | ❌ |
| Report receive | `/reports/receive` | ❌ |
| Report putaway | `/reports/putaway` | ❌ |
| Report PO | `/reports/purchaseorder/` | ❌ |
| Report consignment | `/reports/consign` | ❌ |
| Shipping label | `/reports/shipping-label/` | ❌ |
| WMS picklist report | `/reports/wms/pick-list` | ❌ |
| WMS shipping manifest | `/reports/wms/shipping-manifest` | ❌ |
| Label printing | `/reports/lable/print/` | ❌ |
| Report stock opname | `/reports/stock-opname` | ❌ |

---

### 9. Marketplace Integrations

Baru TikTok yang terintegrasi. 4 marketplace lain belum ada.

| Marketplace | Fitur Jubelio | Status |
|-------------|--------------|--------|
| **Shopee** | Logistics | ❌ |
| **Tokopedia** | Showcases | ❌ |
| **Lazada** | Get document, Shipment providers | ❌ |
| **Blibli** | Pickup points | ❌ |

---

### 10. Webhook Events

Webhook CRUD sudah ada, tapi belum ada event dispatching untuk event-event berikut:

| Event | Status |
|-------|--------|
| `webhooks/salesorder` | ❌ |
| `webhooks/stock` | ❌ |
| `webhooks/stocktransfer` | ❌ |
| `webhooks/invoice` | ❌ |
| `webhooks/payment` | ❌ |
| `webhooks/product` | ❌ |
| `webhooks/price` | ❌ |
| `webhooks/purchaseorder` | ❌ |
| `webhooks/salesreturn` | ❌ |

---

### 11. Fitur Lain-lain

| Fitur | Status |
|-------|--------|
| **Inventory Catalog** (product grouping/merge) | ❌ |
| **Item Bundles** | ❌ |
| **Price List** (internal & promotions) | ❌ |
| **Inventory Revaluation** | ❌ |
| **Need Restock** alerts | ❌ |
| **Batch Number** management | ❌ |
| **Serial Number** tracking | ❌ |
| **Store Locations** (POS) | ❌ |
| **System Settings** (account mapping, webhook config) | ❌ |
| ~~**Out of Stock in Order** detection~~ | ✅ via outbound empty-stock |
| ~~**WMS Employee**~~ | ✅ |
| ~~**Default Bin** per location~~ | ✅ |

---

## PRIORITAS PENGEMBANGAN

### ~~Priority 1 — Core WMS~~ ✅ DONE
~~1. Pick → Pack → Ship flow~~ ✅
~~2. Stock Opname~~ ✅
~~3. Couriers management~~ ✅

### Priority 2 — Complete Sales & Purchase Cycle
4. **Sales Invoice & Payment** — monetization flow
5. **Purchase Bills & Payment** — procurement completion
6. **Contacts/CRM** — customer & supplier unified management

### Priority 3 — Accounting
7. **Journal & Cash Bank** — Finance module implementation
8. **Account Mapping** — CoA integration

### Priority 4 — Operations & Reporting
9. **Specific reports** (stock opname, shipping manifest, picklist)
10. **Webhook event dispatching**
11. **Marketplace integrations** (Shopee, Tokopedia, Lazada, Blibli)

---

*Generated: 2026-06-08*
*Reference: Jubelio API dist (2).yaml & dist (3).yaml*
