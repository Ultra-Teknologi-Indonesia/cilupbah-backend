# Development Summary — Superapp WMS

*Updated: 2026-06-08*

---

## Status Overview

| Module | Routes | Status | Keterangan |
|--------|--------|--------|------------|
| **Auth** | 4 | ✅ Complete | Login, RBAC, Users, Roles, Permissions |
| **Product** | 25 | ✅ Complete | CRUD, Variants, Categories, Brands, Attributes, Import |
| **Warehouse** | 3 | ✅ Complete | Locations, Zones, Bins, Channel Mapping |
| **Region** | 4 | ✅ Complete | Province, City, District, Village |
| **Inventory** | 39 | ✅ Complete | Stock, Transfer, Adjustment, Putaway, Stock Opname |
| **Inbound** | 16 | ✅ Complete | Receiving, Auto-Putaway |
| **Outbound** | 44 | ✅ Complete | Pick/Pack/Ship, Couriers, WMS Utilities |
| **Order** | 20 | ⚠️ Partial | CRUD + status transitions, belum ada invoice/payment |
| **Purchase** | 9 | ⚠️ Partial | PO CRUD, belum ada Bills/Payments |
| **Channel** | 23 | ⚠️ Partial | TikTok only, marketplace lain belum |
| **Sales** | 7 | ⚠️ Partial | Sales Return saja |
| **Supplier** | 5 | ✅ Basic | CRUD |
| **Tax** | 5 | ✅ Basic | CRUD |
| **Webhook** | 6 | ⚠️ Basic | CRUD, belum ada event dispatching |
| **Warranty** | 3 | ⚠️ Basic | CRUD |
| **Finance** | 5 | ❌ Skeleton | Belum ada implementasi |
| **Report** | 5 | ❌ Skeleton | Belum ada report spesifik |
| **Notification** | 5 | ❌ Skeleton | Belum ada implementasi |
| **Total** | **~286** | | |

---

## Yang Sudah Selesai (Sprint Terakhir)

### 1. Stock Opname — `362ae2b`
- Model: StockOpname (DRAFT → IN_PROGRESS → FINALIZED → CANCELLED)
- Items dengan qty_system, qty_actual, qty_difference
- Filter by zone, floor, row, column
- Finalize via background job → auto-adjust inventory + movement log
- 14 endpoints dengan Swagger

### 2. Outbound Fulfillment (Pick/Pack/Ship) — `3c95a70`
- **Picklist**: Create dari multiple orders, assign picker, pick items per bin, complete → triggers `Order.status = 'picked'`
- **Packlist**: Create per order (1:1), assign packer, pack items, verify barcode, complete → triggers `Order.status = 'packed'`
- **Shipment**: Schedule, add/remove orders, hand-over → triggers `Order.status = 'shipped'`
- **Fulfillment Queue**: 8 stage views (ready-to-process → shipped)
- Background jobs on queue `stock-critical` untuk status transitions via OrderService
- 30 endpoints

### 3. Legacy Parity Features — `1b593b9`
- **Courier CRUD** (model, repo, service, controller — 6 endpoints)
- **Failed pick** status + endpoint
- **Empty stock** order view
- **Request cancel** flow dengan reason tracking
- **Change location** per order
- **Scan shipment** by barcode/shipment_no/tracking_number
- **Save AWB** (airwaybill/tracking number) per order
- **WMS Employee** lookup by NIK/email
- **Default bin** per location (get/set)
- Total 44 outbound routes

---

## Yang Belum — Prioritas Development

### Priority 1 — Sales Full Cycle (Module: Sales + Order)
Ini yang paling kritis setelah WMS selesai. Tanpa ini, tidak ada monetization flow.

| Fitur | Estimasi | Detail |
|-------|----------|--------|
| **Sales Invoice** | Medium | CRUD, generate dari order, overdue/unpaid views, summary |
| **Sales Payment** | Medium | Payment recording, multiple payment methods |
| **Sales Settlement** | Small | Settlement reconciliation |
| **Order Enhancements** | Small | set-as-paid, mark-complete, cancel/failed views, returned-list, unfulfilled |
| **Return Settlement** | Small | Penyelesaian dari sales return |

**Catatan**: Module Sales sudah ada `SalesReturn` (7 routes). Tinggal tambah Invoice, Payment, Settlement.

### Priority 2 — Purchase Full Cycle (Module: Purchase)
Purchase sudah ada PO. Yang kurang:

| Fitur | Estimasi | Detail |
|-------|----------|--------|
| **Purchase Bills** | Medium | CRUD, overdue/unpaid/for-return views |
| **Purchase Payments** | Medium | Payment recording |
| **Purchase Returns** | Medium | Return flow dari supplier |
| **Return Settlements** | Small | Penyelesaian return |

### Priority 3 — Contacts / CRM (Module Baru)
Unified contact management — saat ini customer di Order, supplier di Supplier. Sistem lama menggabungkan ke satu module.

| Fitur | Estimasi | Detail |
|-------|----------|--------|
| **Contact CRUD** | Medium | Unified customer + supplier |
| **Contact Categories** | Small | Grouping contacts |
| **Customer/Supplier filters** | Small | View by type |

### Priority 4 — Finance / Accounting (Module: Finance)
Module Finance masih skeleton.

| Fitur | Estimasi | Detail |
|-------|----------|--------|
| **Chart of Accounts** | Medium | CoA master data |
| **Journal Entries** | Large | Auto-journal dari invoice/payment + manual journal |
| **Cash & Bank** | Medium | Payment & receive transactions |
| **Account Mapping** | Small | System setting untuk auto-journal |

### Priority 5 — Reports (Module: Report)
Module Report masih skeleton. Perlu report spesifik:

| Fitur | Estimasi | Detail |
|-------|----------|--------|
| **Stock Opname Report** | Small | Export dari stock opname data |
| **Picklist Report** | Small | Print picklist per picker |
| **Shipping Manifest** | Small | Manifest per shipment/courier |
| **Shipping Label** | Medium | Label generation (PDF) |
| **Invoice Report** | Small | AR aging, sales summary |
| **PO Report** | Small | Purchase summary |
| **Adjustment Report** | Small | Stock adjustment history |

### Priority 6 — Webhook Event Dispatching
CRUD sudah ada, tapi event belum di-dispatch saat terjadi perubahan data.

| Event | Trigger |
|-------|---------|
| `salesorder` | Order created/updated/cancelled |
| `stock` | Inventory changed (adjustment, opname, transfer) |
| `stocktransfer` | Transfer out/in |
| `invoice` | Invoice created/paid |
| `payment` | Payment recorded |
| `product` | Product created/updated |
| `purchaseorder` | PO created/approved/received |
| `salesreturn` | Return created/completed |

**Implementasi**: Laravel Events + Listeners → HTTP POST ke registered webhook URLs.

### Priority 7 — Marketplace Integrations
Baru TikTok. Yang belum:

| Marketplace | Scope |
|-------------|-------|
| **Shopee** | Auth, product sync, order sync, logistics |
| **Tokopedia** | Auth, product sync, order sync, showcases |
| **Lazada** | Auth, product sync, order sync, shipment providers |
| **Blibli** | Auth, product sync, order sync, pickup points |

**Catatan**: Setiap marketplace butuh OAuth flow, product mapping, order import, dan shipping integration. Paling besar effort-nya.

### Priority 8 — Fitur Tambahan

| Fitur | Estimasi | Detail |
|-------|----------|--------|
| **Item Bundles** | Medium | Bundle product dari multiple variants |
| **Price List** | Medium | Harga per channel/customer group |
| **Batch Number** | Medium | Tracking batch pada inventory |
| **Serial Number** | Medium | Per-unit tracking |
| **Inventory Revaluation** | Small | Revalue stock value |
| **Need Restock Alerts** | Small | Notification saat stock di bawah threshold |

---

## Architecture Notes

### Pattern yang Digunakan
```
Model (HasUuid7) → Repository (Spatie QueryBuilder) → Service → Controller (Swagger OA Attributes)
```

### Cross-Module Integration
- Outbound → OrderService (via queued jobs on `stock-critical`)
- OrderService → StockService (Redis distributed lock via `StockLockable` trait)
- Inbound → PutawayService → InventoryRepository

### Key Conventions
- UUID v7 hex (no dashes): `Uuid::uuid7()->getHex()->toString()`
- Document numbers: `PREFIX-YYYYMMDD-0001` (auto-increment per day)
- Background jobs: `$tries = 3, $backoff = [3, 10, 30]`, queue: `stock-critical`
- Status flow: document lifecycle (DRAFT → IN_PROGRESS → COMPLETED → CANCELLED)
- Order flow: `pending → reserved → picked → packed → shipped | cancelled`

---

## Rekomendasi Urutan Sprint

### Sprint Berikutnya
1. **Sales Invoice + Payment** — unlock revenue tracking
2. **Order Enhancements** — set-as-paid, mark-complete, cancel views

### Sprint Setelahnya
3. **Purchase Bills + Payment** — complete procurement cycle
4. **Contacts/CRM** — unified customer/supplier

### Sprint 3+
5. **Reports** — operational reports (picklist, manifest, stock opname)
6. **Finance** — journal, cash & bank
7. **Webhook events** — real-time integration
8. **Marketplace** — Shopee/Tokopedia (highest demand first)

---

*Branch: `feat/persediaan`*
*Total commits session ini: 3 (stock opname + outbound fulfillment + legacy parity)*
