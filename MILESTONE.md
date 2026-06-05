# WMS + Omnichannel + Business Intelligence — MILESTONE

**Target:** Feature parity Jubelio (442 endpoints) + cilupbah-ops (22 modules)  
**Durasi:** 6 minggu (1.5 bulan)  
**Tim:** Darel & Rasyid  
**Fokus:** Laravel Backend (API-first) menggantikan 2 sistem sekaligus  
**Referensi:**
- Jubelio WMS API (`dist (2).yaml`) — 254 endpoints  
- Jubelio API (`dist (3).yaml`) — 188 endpoints  
- cilupbah-ops tRPC routers (22 domain: auth, dashboard, hpp, po, stock, finance, store, supplier, sync, settings, product, cash, forecast, ai, loss, supplierPortal, supplierInvite, notifications, sales, superai, tax, marketplace, warranty)

---

## ✅ Sudah Dikerjakan (DONE) — Yang Ada di cilupbah-be

### Module: Auth
- [x] Login / Register (Sanctum token-based auth)

### Module: Product
- [x] CRUD Produk (`ProductController` + `ProductService`)
- [x] Model Product & ProductVariant (SKU, barcode, harga beli/jual, berat, dimensi)
- [x] Tabel: `categories`, `brands`, `attributes`, `attribute_options`, `products`, `product_variants`, `variant_options`, `product_specifications`, `product_media`, `product_variation_types`, `product_wholesale_prices`, `product_bundles`
- [x] Import Excel produk biasa + bundle via `maatwebsite/excel`
- [x] Download template Excel (GET endpoint)
- [x] Upload media / gambar produk (`MediaController`)
- [x] Channel Product Controller (CRUD produk per channel marketplace)

### Module: Channel (TikTok Shop — satu-satunya marketplace yang sudah jalan)
- [x] OAuth2 flow TikTok (redirect + callback + token refresh)
- [x] Multi-shop management (CRUD `channel_shops`)
- [x] Pull Orders dari TikTok (semua toko otomatis)
- [x] Pull Products dari TikTok (semua toko otomatis)
- [x] Push Product ke TikTok
- [x] Push Update Product ke TikTok
- [x] Sync Harga & Inventori ke TikTok
- [x] Bulk Push semua produk ke TikTok
- [x] Accept / Decline / Cancel order di TikTok
- [x] Order & Product Mapper (TikTok ↔ Internal format)
- [x] Channel identifier di tabel `products` (`channel_shop_id`, `source`)
- [x] Artisan Commands: `PullTikTokOrders`, `PullTikTokProducts`, `PushTikTokProduct`, `AcceptTikTokOrder`, `DeclineTikTokOrder`

### Module: Order
- [x] Tabel `orders` & `order_items` (shipping, payment, channel status)
- [x] Upsert order dari channel (`OrderService::upsertFromChannel`)
- [x] CRUD Orders (`OrderController` apiResource)

### Module: Warehouse
- [x] CRUD Lokasi/Gudang (`LocationController` + `LocationService`)
- [x] CRUD Bin/Rak (`LocationBinController` + `LocationBinService`)
- [x] Default bin per gudang
- [x] Channel Warehouse mapping (gudang ↔ marketplace)

### Module: Inventory
- [x] Stok: on_hand, on_order, reserved, available
- [x] Stock Adjustment, Transfer antar gudang, Putaway (bin → bin)
- [x] Reserve & Fulfill stock (untuk proses order)
- [x] Inventory movement history (audit trail)

### Module: Inbound
- [x] CRUD Inbound document (draft → partial → completed)
- [x] Receive items (parsial & penuh) dengan validasi qty
- [x] Auto-putaway ke default bin

### Infrastruktur
- [x] Cloudflare R2 (S3-compatible) file storage
- [x] Swagger / OpenAPI documentation
- [x] ApiResponse Trait, FuzzyFilter, Spatie QueryBuilder, Service-Repository Pattern

---

## 🔴 BELUM ADA — Gap vs Jubelio + cilupbah-ops

Berikut SEMUA yang harus dibuat agar 100% menggantikan kedua sistem:

---

### A. WMS CORE (dari Jubelio dist(2).yaml)

#### A1. Purchase Order & Billing
> Jubelio endpoints: `/purchase/orders/`, `/purchase/bills/`, `/purchase/payments/`, `/purchase/serial-number/`  
> cilupbah-ops: `po.router` (1863 baris), `supplierPortal.router`, `supplierInvite.router`

- [ ] **Supplier CRUD** — `GET/POST/PUT/DELETE /api/v1/suppliers`
  - Contact info, alamat, terms, mata uang, lead time default
  - _cilupbah-ops equivalent: `supplier.router.list/create/update/delete`_
- [ ] **Purchase Order CRUD** — `GET/POST/PUT/DELETE /api/v1/purchase-orders`
  - Draft → Approved → Partially Received → Received → Reviewed
  - Flag FAST/OK/SLOW berdasarkan lead time
  - PO Numbering otomatis
  - _Jubelio: `/purchase/orders/`, `/purchase/orders/{id}`, `/purchase/orders/progress`_
  - _cilupbah-ops: `po.create, po.update, po.list, po.getById, po.markReceived, po.updateReceivedQty`_
- [ ] **PO Items** — qty_order, qty_pl (packing list), qty_received, unit_price
  - _cilupbah-ops: `po.hppList` (owner-only HPP per item)_
- [ ] **PO Receipt Photo** — upload bukti terima (good/damage/mixed + notes)
  - _cilupbah-ops: `po.uploadReceiptPhoto, po.listReceiptPhotos, po.deleteReceiptPhoto`_
- [ ] **PO Attachment** — shipping doc, packing list file (image/PDF/xlsx/csv)
  - _cilupbah-ops: `po.uploadShippingDoc, po.uploadPLFile`_
- [ ] **PO Import qty PL** — bulk import qty_pl + qty_received dari xlsx/csv
  - _cilupbah-ops: `po.importPLQty (preview/apply mode)`_
- [ ] **Purchase Bill** — terima barang → buat bill → otomatis buat Inbound
  - _Jubelio: `/purchase/bills/`, `/purchase/bills/{id}`, `/purchase/bills/overdue/`, `/purchase/bills/unpaid/`_
- [ ] **Purchase Payment** — pembayaran ke supplier
  - _Jubelio: `/purchase/payments/`, `/purchase/payments/{id}`_
  - _cilupbah-ops: `supplierPortal.requestPayment, cancelPaymentRequest, markPaymentDirectPaid` + `po.payment workflow (REQUESTED→APPROVED→PAID→REJECTED)`_
- [ ] **Serial/Batch Number** — tracking per item saat receive
  - _Jubelio: `/purchase/serial-number/wms/{bill_detail_id}`, `/purchase/serial-number/mark-printed`_

#### A2. Supplier Portal (dari cilupbah-ops)
> cilupbah-ops: `supplierPortal.router`, `supplierInvite.router`

- [ ] **Supplier Invite System** — owner generate invite link → supplier register
  - _cilupbah-ops: `supplierInvite.createInvite, listInvites, revokeInvite, getInvite, acceptInvite`_
- [ ] **Supplier PO View** — supplier lihat PO yang ditugaskan ke mereka
  - _cilupbah-ops: `supplierPortal.myPOs, myPODetail, myProfile`_
- [ ] **Supplier PL Input** — supplier update qty PL, submit packing list
  - _cilupbah-ops: `supplierPortal.updatePOItemPL, updateItemPrices, updatePOHeader, submitPL`_
- [ ] **Supplier Review** — post-delivery review (category: PAYMENT_DEDUCT/RESEND/MATCHED/OTHER)
  - _cilupbah-ops: `supplierPortal.submitReview`_
- [ ] **Supplier File Upload** — PL file, invoice file (dengan AI redaction)
  - _cilupbah-ops: `supplierPortal.uploadPLFile, deletePLFile, uploadInvoiceFile, deleteInvoiceFile`_
- [ ] **Supplier Chat** — komunikasi supplier ↔ buyer
  - _cilupbah-ops: `supplierPortal.chat`_

#### A3. Stock Opname
> Jubelio endpoints: `/inventory/stock-opname/`, `/inventory/stock-opname/{id}`, `/inventory/stock-opname/floors`, `/inventory/stock-opname/rows`, `/inventory/stock-opname/columns`, `/inventory/stock-opname/bins`, `/inventory/stock-opname/items`, `/inventory/stock-opname/finalize`, `/inventory/stock-opname/items/filtered`

- [ ] **Stock Opname CRUD** — buat sesi opname per lokasi/bin
- [ ] **Stock Opname Scan** — input qty fisik per item
- [ ] **Stock Opname Finalize** — otomatis adjustment selisih via InventoryService
- [ ] **Stock Opname Report** — `/reports/stock-opname`
- [ ] **Floor/Row/Column/Bin** — navigasi rak saat opname

#### A4. Outbound: Pick, Pack, Ship
> Jubelio endpoints: 40+ WMS endpoints (`/wms/sales/orders/`, `/wms/sales/picklists/`, `/wms/sales/packlist/`, `/wms/shipments/`, `/sales/shipments/`)

**Picking:**
- [ ] List Order Ready to Process — `GET /wms/sales/orders/ready-to-process/`
- [ ] Move to Ready to Pick — `POST /wms/sales/ready-to-pick`
- [ ] Assign Picker (Employee) — `GET /wms/employee/{NIKorEmail}`
- [ ] Create Picklist — `POST /wms/sales/picklists/`
- [ ] Get Items to Pick — `POST /sales/picklists/items-to-pick`
- [ ] Confirm Pick (update qty_picked, is_completed) — `POST /wms/sales/picklists` (update payload)
- [ ] Change Picker — `POST /wms/sales/picklists/change-picker/`
- [ ] Get Order by No — `POST /wms/order/getOrderByNo/`
- [ ] List: ready-to-pick, confirm-pick, finish-pick

**Packing:**
- [ ] Scan Order to Pack — `GET /wms/sales/packlist/scan-order`
- [ ] Create Packlist — `POST /wms/sales/packlist`
- [ ] Verify Barcode — `POST /wms/sales/packlist/verify-barcode/`
- [ ] Update Qty Packed — `POST /wms/sales/packlist/update-qty-packed`
- [ ] Mark Pack Complete — `POST /wms/sales/packlist/mark-as-complete/`
- [ ] List: process, finish-pack

**Shipping:**
- [ ] Create Shipment Schedule (regular & instant courier) — `POST /wms/shipments/`, `POST /wms/shipments/instant-courier/`
- [ ] Add Order to Shipment — `POST /wms/shipment-detail/`
- [ ] Scan Shipment — `POST /wms/scan-shipment`
- [ ] Get AWB — `POST /wms/sales/shipments/orders/`
- [ ] Courier Pickup — `POST /wms/shipments/get-order/`
- [ ] Complete Shipment — `POST /sales/shipments/`
- [ ] Change Order Location — `POST /wms/sales/orders/change-location/`
- [ ] List: ready-to-ship, all shipments, by courier, instant, completed
- [ ] Shipped List — `GET /wms/sales/shipped/`

**Reports:**
- [ ] Print Picklist — `GET /reports/wms/pick-list`
- [ ] Print Shipping Label — `GET /reports/shipping-label/`
- [ ] Print Shipping Manifest (POD) — `GET /reports/wms/shipping-manifest`
- [ ] Print Invoice — `GET /reports/invoice` + `POST /sales/packlists/create-invoice`

#### A5. Retur (Sales Return & Purchase Return)
> Jubelio endpoints: `/sales/sales-returns/`, `/sales/returns/items/`, `/purchase/purchase-returns/`, `/purchase/return-settlements/`

**Sales Return:**
- [ ] Create Sales Return (with/without invoice) — `POST /sales/sales-returns/`
- [ ] Accept Return (otomatis receive items) — `POST /inventory/items/to-return/`
- [ ] Complete Return (mark not return) — `POST /inventory/items/complete-return/`
- [ ] Reject Return — `POST /inventory/items/reject-return/`
- [ ] Unprocessed WMS Returns — `GET /sales/returns/items/unprocessed/wms`
- [ ] Resolved/Rejected Returns — `GET /sales/returns/items/resolved/`, `rejected/`
- [ ] Return Settlements (invoices, refunds) — `/sales/return-settlements/`

**Purchase Return:**
- [ ] Create Purchase Return — `POST /purchase/purchase-returns/`
- [ ] List Purchase Returns — `GET /purchase/purchase-returns/`, `unpaid/`
- [ ] Purchase Return Settlements — `/purchase/return-settlements/`

#### A6. Inventory Extended
> Jubelio endpoints tambahan yang belum ada di cilupbah-be

- [ ] **Items to Buy** — `GET /inventory/items/to-buy` (restock recommendation)
- [ ] **Items to Adjust** — `GET /inventory/items/to-adjust/`
- [ ] **Items to Sell** — `GET /inventory/items/to-sell/{location_id}`
- [ ] **Items to Stock** — `GET /inventory/items/to-stock/`
- [ ] **Inventory Catalog** — `GET/POST /inventory/catalog/` + upload, set-master, merge-catalog
- [ ] **Inventory Reserved** — `GET /inventory/reserved/`, `reserved/{id}`
- [ ] **Need Restock** — `GET /inventory/need-restock/`
- [ ] **Out of Stock in Order** — `GET /inventory/out-of-stock-in-order/`
- [ ] **Price List** — `GET /inventory/price-list/`, `internal-price-list/`
- [ ] **Promotions** — `GET/POST /inventory/promotions/`, `promotions/{id}`
- [ ] **Revaluations** — `GET /inventory/revaluations/`
- [ ] **Item Archive/Restore** — `POST /inventory/items/archive/`, `restore/`
- [ ] **Item Reviews** — `GET /inventory/items/reviews/`
- [ ] **Item Errors** — `GET /inventory/items/errors/`
- [ ] **Item Split** — `POST /inventory/items/split-item`
- [ ] **Batch Number** — `GET /inventory/items/{id}/batch-number`
- [ ] **All Stocks per Item** — `GET /inventory/items/all-stocks/`
- [ ] **Item by SKU** — `GET /inventory/items/by-sku/{sku}`
- [ ] **Item by Bill** — `GET /inventory/items/by-bill/{doc_id}`
- [ ] **Inventory Transfer: mark printed, delivery** — `POST /inventory/transfer/mark-printed`, `delivery`

#### A7. Putaway Extended (Jubelio WMS)
> dist(2).yaml specific endpoints

- [ ] **Putaway Status Lists** — `GET /inventory/putaway/all`, `not-start`, `processed`, `completed`
- [ ] **Putaway Item Detail** — `GET /inventory/items/received/item/{putaway_id}`
- [ ] **Auto Putaway** — `POST /inventory/items/received/auto-putaway` ✅ (partial — ada di Inbound)
- [ ] **Manual Putaway** — `POST /inventory/items/received/putaway`
- [ ] **Finish Putaway** — `POST /inventory/items/received/finish-putaway`
- [ ] **Assign Staff for Putaway** — `POST /inventory/items/received/author`
- [ ] **Putaway Report** — `GET /reports/putaway`
- [ ] **Item Received Not Placed** — `GET /reports/item-receive-notplace`

---

### B. OMNICHANNEL INTEGRATION (dari Jubelio API dist(3).yaml)

#### B1. Shopee
> cilupbah-ops: `marketplace.router` (pattern sama dengan TikTok)

- [ ] `ShopeeClient` — HTTP client, signature HMAC-SHA256
- [ ] `ShopeeAuthService` — OAuth flow (redirect, callback, token refresh)
- [ ] `ShopeeProductService` — Pull/Push/Sync produk
- [ ] `ShopeeOrderService` — Pull/Accept/Ship order
- [ ] `ShopeeProductMapper` & `ShopeeOrderMapper`
- [ ] Shopee Logistics — `GET /shopee/logistics`
- [ ] Routes prefix `shopee/`

#### B2. Tokopedia
- [ ] `TokopediaClient`, `TokopediaAuthService`, `TokopediaAuthController`
- [ ] `TokopediaProductService`, `TokopediaOrderService`
- [ ] `TokopediaProductMapper`, `TokopediaOrderMapper`
- [ ] Tokopedia Showcases — `GET /tokopedia/showcases`
- [ ] Routes prefix `tokopedia/`

#### B3. Lazada
- [ ] `LazadaClient`, `LazadaAuthService`, `LazadaAuthController`
- [ ] `LazadaProductService`, `LazadaOrderService`
- [ ] `LazadaProductMapper`, `LazadaOrderMapper`
- [ ] Lazada Get Document — `GET /lazada/get-document/`
- [ ] Lazada Shipment Providers — `GET /lazada/get-shipment-providers/{storeId}/`
- [ ] Routes prefix `lazada/`

#### B4. Blibli
- [ ] `BlibliClient`, `BlibliAuthService`, `BlibliAuthController`
- [ ] `BlibliProductService`, `BlibliOrderService`
- [ ] `BlibliProductMapper`, `BlibliOrderMapper`
- [ ] Blibli Pickup Points — `GET /blibli/pickupPoints`
- [ ] Routes prefix `blibli/`

#### B5. Marketplace Store Management
> Jubelio: `/marketplace/store/`, `/store-locations/`  
> cilupbah-ops: `store.router`

- [ ] **Store CRUD** — list/connect/disconnect toko marketplace
- [ ] **Store Locations** — mapping lokasi per toko
- [ ] **Store per Channel** — list toko per marketplace

---

### C. BUSINESS INTELLIGENCE (dari cilupbah-ops — TIDAK ADA DI JUBELIO)

#### C1. Dashboard & KPIs
> cilupbah-ops: `dashboard.router` (378 baris)

- [ ] **Dashboard KPIs** — stock status counts (URGENT/WARNING/OK/OVERSTOCK/DEAD/NO_SALES), omzet 30d, profit 30d, margin %, PO on progress, cancel/return rate, last sync
- [ ] **Omzet COMPLETED vs ALL** — versi konservatif (hanya order COMPLETED)
- [ ] **Marketplace Fees Estimation** — per channel × per month
- [ ] **Net Profit** — omzet - COGS - marketplace fees
- [ ] **Quick Wins** — rule-based recommendations (SETUP_HPP, URGENT_PO, STOP_SLOW, RENEGO_HPP)
- [ ] **Sync Status** — last sync per endpoint

#### C2. HPP (Harga Pokok Penjualan)
> cilupbah-ops: `hpp.router` (complex — SKU-level, Product-level, Legacy cascading resolver)

- [ ] **HPP Config CRUD** — `hppRmb`, `feeKirim`, per produk/SKU
- [ ] **HPP Cascading Resolver** — SKU override → Product master → Legacy config
- [ ] **HPP Product Level** — aggregate multi-variant
- [ ] **HPP SKU Level** — per-variant override
- [ ] **HPP Bulk Import** — import HPP dari xlsx/csv
- [ ] **HPP Suggest** — auto-suggest dari supplier PO price
- [ ] **Effective Batch** — get effective HPP untuk batch SKUs
- [ ] **Supplier List (HPP context)** — supplier names dari HPP data

#### C3. Finance & Profitability
> cilupbah-ops: `finance.router` (469 baris — heavy SQL CTE)

- [ ] **Profitability per Product** — omzet, COGS, margin Rp, margin %, status finance
- [ ] **Fee Breakdown** — admin, service, shipping, voucher, tax, other (per unit & total)
- [ ] **Fee from Settlement vs Estimated** — real settlement data vs schedule estimation
- [ ] **Modal Stok** — stok × unit COGS
- [ ] **Hidden Products** — hide dari finance view
- [ ] **Product Merge** — grup variant ke master product
- [ ] **Summary KPIs** — omzet Nd, settlement, HPP coverage

#### C4. Sales Analytics
> cilupbah-ops: `sales.router`

- [ ] **Sales List** — paginated, filter by status/channel/store/shipper/month/date range
- [ ] **Sales KPIs** — aggregated metrics per filter
- [ ] **Sales Detail** — per order detail
- [ ] **Sales Profitability** — margin per order
- [ ] **Filter Options** — dynamic status/channel/store/shipper options

#### C5. Stock Replenishment (SR)
> cilupbah-ops: `stock.router`

- [ ] **SR Produk** — per-product: stok, avg hari, status, rec PO, supplier, keb hari
- [ ] **SR SKU** — per-SKU level breakdown
- [ ] **Bulk Auto Fill Product Supplier** — auto-assign supplier ke produk
- [ ] **Bulk Update SKU kebHari/Supplier/Bin** — mass update config
- [ ] **Update Product/SKU Config** — supplier override, MOQ, lead time, keb hari

#### C6. Forecast (Proyeksi Stok)
> cilupbah-ops: `forecast.router` (175 baris)

- [ ] **Proyeksi 90 Hari** — stockout forecast per SKU, bucketed (Week 1-2, Week 3-4, Bulan 2, Bulan 3, Aman)
- [ ] **Rec PO per Bucket** — recommended PO quantity per time bucket
- [ ] **Summary** — count SKU habis dalam 30/60/90 hari

#### C7. Loss Control (Failed Delivery / Returns)
> cilupbah-ops: `loss.router` (massive — scan, match, timeline, recap)

- [ ] **Loss Summary** — overview kerugian dari failed delivery/returns
- [ ] **Loss List** — paginated, filter by status/shipper/channel/date/month
- [ ] **Loss Order Timeline** — tracking per order
- [ ] **Loss Received List** — items yang sudah diterima kembali
- [ ] **Loss Received Detail** — detail per item
- [ ] **Loss Scan** — scan barcode untuk match
- [ ] **Loss Match** — match received items ke order
- [ ] **Loss Update State** — update status (pending/reviewed/resolved)
- [ ] **Loss Bulk Update State** — mass update
- [ ] **Monthly/Per-Shipper Recap** — agregasi per bulan / per kurir
- [ ] **Status Overview & Counts** — dashboard-level overview
- [ ] **List Shippers & Channels** — dynamic filter options

#### C8. Cash Position
> cilupbah-ops: `cash.router`

- [ ] **Cash Position** — saldo kas (owner-only)

#### C9. Tax Management
> cilupbah-ops: `tax.router`

- [ ] **Tax Entity CRUD** — badan usaha, NPWP, etc
- [ ] **Tax Employee CRUD** — karyawan per badan usaha
- [ ] **Tax Expense** — input pengeluaran untuk perhitungan pajak
- [ ] **Tax Dashboard** — ringkasan kewajiban pajak
- [ ] **Tax Simulation** — simulasi pajak

#### C10. Warranty System
> cilupbah-ops: `warranty.router`

- [ ] **Warranty Claim (Public)** — customer lookup order → submit claim → check status
- [ ] **Warranty Admin** — list claims, approve, ship replacement, reject, mark completed
- [ ] **Warranty Status Counts** — overview per status
- [ ] **Warranty Category Rules** — auto-approval rules per category

#### C11. AI & Super AI
> cilupbah-ops: `ai.router`, `superai.router`

- [ ] **AI Chat** — OpenAI-powered business assistant (chat, conversation history, delete)
- [ ] **Super AI Config** — intents, skills, slots, scenarios, guardrails
- [ ] **Super AI Playground** — test AI responses
- [ ] **Super AI Channels** — deploy AI ke channels (marketplace chat)
- [ ] **Super AI Knowledge** — custom knowledge base
- [ ] **Super AI Memory** — conversation memory/context
- [ ] **AI Redact** — auto-redact supplier contact info dari dokumen

#### C12. Product Management Extended (dari cilupbah-ops)
> cilupbah-ops: `product.router`

- [ ] **Product Catalog** — enriched catalog view
- [ ] **Product Merge Suggestions** — AI-powered duplicate detection
- [ ] **Product Merge/Unmerge** — manual & auto merge variants ke master
- [ ] **Product Hide/Unhide** — bulk hide dari dashboard/finance
- [ ] **Bulk Merge** — mass merge products

---

### D. ACCOUNTING & CONTACTS (dari Jubelio API)

#### D1. Contacts
> Jubelio: `/contacts/`, `/contacts/{id}`, `/contacts/customers/`, `/contacts/suppliers/`, `/contacts/customers-suppliers/`, `/contact/category/`

- [ ] **Contacts CRUD** — customer & supplier management
- [ ] **Contact Categories** — kategori kontak
- [ ] **Customers & Suppliers** — list terpisah

#### D2. Cash Bank
> Jubelio: `/cashbank/payments/`, `/cashbank/receives`

- [ ] **Cash Bank Payments** — catat pembayaran keluar
- [ ] **Cash Bank Receives** — catat penerimaan

#### D3. Journal & Accounting
> Jubelio: `/journal/`, `/journal/{id}`, `/journal/manual-journal/`, `/accounts/lookup/all`

- [ ] **Journal CRUD** — entri jurnal akuntansi
- [ ] **Manual Journal** — input manual
- [ ] **Account Lookup** — chart of accounts

#### D4. Invoicing
> Jubelio: `/sales/invoices/`, `/sales/invoices/{id}`, `/sales/invoices/overdue/`, `/sales/invoices/unpaid/`, `/sales/invoices/summary/`

- [ ] **Sales Invoices CRUD** — buat & kelola invoice
- [ ] **Invoice Reports** — overdue, unpaid, summary
- [ ] **Create Invoice from Packlist** — `POST /sales/packlists/create-invoice`
- [ ] **Invoice Payment** — `POST /sales/packlists/create-invoice-payment`

#### D5. Sales Orders Extended
> Jubelio: `/sales/orders/`, `/sales/orders/{id}`, `/sales/orders/cancel/`, `/sales/orders/failed/`, etc.

- [ ] **Cancel Order** — `POST /sales/orders/cancel/`
- [ ] **Delete Canceled** — `POST /sales/orders/delete-canceled`
- [ ] **Mark as Complete** — `POST /sales/orders/mark-as-complete`
- [ ] **Set as Paid** — `POST /sales/orders/set-as-paid`
- [ ] **Save AWB** — `POST /sales/orders/save-airwaybill/`
- [ ] **Save Received Date** — `POST /sales/orders/save-received-date`
- [ ] **Request AWB** — `POST /sales/request-awb-order/`
- [ ] **Returned List** — `GET /sales/orders/returned-list/`
- [ ] **Failed Orders** — `GET /sales/orders/failed/`
- [ ] **Unfulfilled** — `GET /sales/unfullfilled/`

#### D6. Sales Settlements
> Jubelio: `/sales/settlements/`, `/sales/settlements/{id}`

- [ ] **Settlements CRUD** — marketplace settlement tracking

#### D7. Couriers
> Jubelio: `/couriers`, `/couriers/{id}`, `/couriers/tenant/{id}`, `/wms/couriers`

- [ ] **Courier CRUD** — manage kurir
- [ ] **Tenant Couriers** — kurir per tenant

#### D8. Taxes
> Jubelio: `/taxes/`

- [ ] **Tax Rates** — manage tarif pajak

#### D9. Variations
> Jubelio: `/variations`

- [ ] **Variation Templates** — manage variasi produk (size, color, etc.)

---

### E. SYSTEM & SYNC (dari cilupbah-ops)

#### E1. Data Sync Engine
> cilupbah-ops: `sync.router`, `scheduler.ts` (601 baris), `jobs.ts`

- [ ] **Jubelio Sync** — products, stock, sales, salesItems, settlements, stores, PO
- [ ] **TikTok Sync** — orders (setiap 30 menit)
- [ ] **Sync Scheduler** — cron jobs:
  - PO mirror (every 2h)
  - Stock (every 4h)
  - Sales headers (every 2h, window 7d)
  - Sales items backfill (hourly, gated)
  - Products+Stores+Racks (daily)
  - Settlements (daily)
  - Loss returns (every 6h)
  - Auto-backfill historic (hourly, mundur 7d/firing)
  - Gap detect & repair (every 6h)
  - Watchdog cancel stale queries (every 5min)
- [ ] **Sync History & Status** — log setiap sync run
- [ ] **Manual Trigger** — trigger sync individual via API
- [ ] **Gap Report** — detect & auto-repair missing data months
- [ ] **Monthly Coverage** — DB vs Jubelio count per month
- [ ] **Focus Mode** — prioritize sync tertentu

#### E2. Settings
> cilupbah-ops: `settings.router`

- [ ] **Settings CRUD** — key-value config store
  - `kurs_rmb_idr`, `default_fee_kirim`, `default_keb_hari`, `default_moq`, `default_lead_time_days`
- [ ] **Marketplace Fees Config** — fee schedule per channel per period
- [ ] **Marketplace Fee History** — track fee changes over time

#### E3. Notifications
> cilupbah-ops: `notifications.router`

- [ ] **Notification List** — paginated, per user
- [ ] **Unread Count** — badge count
- [ ] **Mark Read / Mark All Read**
- [ ] **Auto-notify** — PO assigned, PO received, payment status change

#### E4. Webhooks
> Jubelio: `/webhooks/invoice`, `/webhooks/payment`, `/webhooks/price`, `/webhooks/product`, `/webhooks/purchaseorder`, `/webhooks/salesorder`, `/webhooks/salesreturn`, `/webhooks/stock`, `/webhooks/stocktransfer`  
> Jubelio: `/systemsetting/webhook`

- [ ] **Webhook Listener per Event** — invoice, payment, price, product, PO, SO, return, stock, transfer
- [ ] **Webhook Listener per Marketplace** — TikTok, Shopee, Tokopedia, Lazada, Blibli
- [ ] **Webhook Settings** — configure webhook URLs
- [ ] **Signature Verification** per marketplace

#### E5. User & Role Management
> Jubelio: `/systemsetting/users/`  
> cilupbah-ops: `auth.router` (4 role: owner, admin, viewer, supplier)

- [ ] **User CRUD** — manage users
- [ ] **Role-based Access** — owner, admin, viewer, supplier
- [ ] **Account Mapping** — `/systemsetting/account-mapping`

#### E6. Region Data
> Jubelio WMS: `/region/provinces`, `/region/cities/`, `/region/districts/`, `/region/subdistricts/`

- [ ] **Province/City/District/Subdistrict** — master data wilayah Indonesia

---

### F. REPORTS (dari Jubelio)

- [ ] **Receive Report** — `GET /reports/receive`
- [ ] **Consign Report** — `GET /reports/consign`
- [ ] **Invoice Report** — `GET /reports/invoice`
- [ ] **Label Print** — `GET /reports/lable/print/`
- [ ] **PO Report** — `GET /reports/purchaseorder/`
- [ ] **Stock Opname Report** — `GET /reports/stock-opname`
- [ ] **Adjustment Report** — `GET /reports/adjustment`
- [ ] **Receive Report** — `GET /reports/receive`
- [ ] **Export Excel** — semua report support download xlsx/csv

---

## 🚀 Milestone Timeline (6 Minggu)

### Milestone 1 — WMS Core: PO + Outbound (Minggu 1-2)

**Darel (Outbound — Pick, Pack, Ship):**
- [ ] Migration: `outbounds`, `outbound_items`, `picklists`, `picklist_items`, `packlists`, `shipments`, `shipment_items`, `employees`
- [ ] Models + Repositories + Services
- [ ] OutboundService: createFromOrder, createPicklist, confirmPick
- [ ] PackingService: scanAndPack, updateQtyPacked, markComplete
- [ ] ShipmentService: createSchedule, addOrder, confirmPickup
- [ ] Controllers + Routes (15+ endpoints)
- [ ] Integrasi InventoryService::fulfillStock() saat picking selesai

**Rasyid (Purchase Order + Supplier):**
- [ ] Migration: `suppliers`, `purchase_orders`, `po_items`, `po_receipt_photos`, `po_attachments`, `supplier_invites`
- [ ] Models + Repositories + Services (replicate cilupbah-ops po.router logic)
- [ ] PO full workflow: create → update → receive → review → payment
- [ ] PO attachment system (receipt photo, shipping doc, PL file, import)
- [ ] Supplier CRUD + Invite system
- [ ] Supplier Portal endpoints (view PO, update PL, submit review, file upload)

### Milestone 2 — Stock Opname, Retur, Extended Inventory (Minggu 2-3)

**Darel (Stock Opname + Inventory Extended):**
- [ ] Migration: `stock_opnames`, `stock_opname_items`
- [ ] StockOpnameService: create, scan, finalize
- [ ] Inventory Extended endpoints (need-restock, out-of-stock, price-list, archive/restore, etc.)
- [ ] Putaway Extended (status lists, manual putaway, finish, assign staff)

**Rasyid (Returns + Webhooks + Notifications):**
- [ ] Migration: `returns`, `return_items`, `return_settlements`
- [ ] SalesReturnService: createFromOrder, accept, reject, receive
- [ ] PurchaseReturnService: createFromPO, ship
- [ ] Webhook foundation (per event type + per marketplace)
- [ ] Notification system (in-app + auto-trigger)

### Milestone 3 — Business Intelligence (Minggu 3-4)

**Darel (Dashboard + Finance + HPP):**
- [ ] Migration: `hpp_configs`, `product_merges`, `product_hidden`, `settings`, `sync_logs`
- [ ] DashboardService: KPIs, quickWins, syncStatus (replicate dashboard.router)
- [ ] HppService: cascading resolver (SKU → Product → Legacy), bulk import
- [ ] FinanceService: profitability per product, fee breakdown, settlement vs estimated
- [ ] CashService: cash position

**Rasyid (Sales Analytics + Stock Replenishment + Forecast + Loss):**
- [ ] SalesAnalyticsService: list, KPIs, detail, profitability, filter options
- [ ] StockReplenishmentService: SR produk, SR SKU, bulk update config
- [ ] ForecastService: proyeksi 90 hari, rec PO per bucket
- [ ] LossControlService: summary, list, scan, match, timeline, recap

### Milestone 4 — Shopee + Tokopedia (Minggu 4-5)

**Darel (Shopee):**
- [ ] ShopeeClient, ShopeeAuthService, ShopeeProductService, ShopeeOrderService
- [ ] Mappers + Controller + Routes

**Rasyid (Tokopedia):**
- [ ] TokopediaClient, TokopediaAuthService, TokopediaProductService, TokopediaOrderService
- [ ] Mappers + Controller + Routes

### Milestone 5 — Lazada, Blibli, Real-time Sync (Minggu 5-6)

**Darel (Lazada + Real-time Sync):**
- [ ] LazadaClient + Services + Mappers
- [ ] Event-driven stock sync: `StockUpdated` → push ke semua channel
- [ ] ChannelStockSyncService
- [ ] Auto-fulfill: order PROCESSING → Outbound → reserve → ship → update marketplace

**Rasyid (Blibli + Sync Engine + Warranty + AI):**
- [ ] BlibliClient + Services + Mappers
- [ ] Sync Engine (scheduler, gap detection, backfill — replicate cilupbah-ops scheduler.ts)
- [ ] WarrantyService: claim, approve, ship, reject, complete, category rules
- [ ] AI Chat foundation (OpenAI integration)
- [ ] Tax management, Settings, User roles

### Milestone 6 — Accounting, Reports, Polish (Minggu 6)

**Darel:**
- [ ] Journal, Invoicing, Cash Bank
- [ ] All Reports with Excel export
- [ ] Region master data
- [ ] Product merge/hide
- [ ] Final integration testing

**Rasyid:**
- [ ] Contacts CRUD, Couriers, Variations
- [ ] Sales Orders extended (cancel, AWB, mark complete, etc.)
- [ ] Settlements tracking
- [ ] Supplier portal fine-tuning
- [ ] Final API documentation update

---

## 📊 Progress Tracker

| Area | Endpoints | Status | PIC |
|------|-----------|--------|-----|
| **Auth & User** | 5 | ✅ Done | Darel |
| **Product (CRUD/Import/Media)** | 15 | ✅ 95% | Darel |
| **Channel: TikTok** | 20 | ✅ 90% | Darel |
| **Channel: Shopee** | ~15 | 🔴 0% | Darel (M4) |
| **Channel: Tokopedia** | ~15 | 🔴 0% | Rasyid (M4) |
| **Channel: Lazada** | ~15 | 🔴 0% | Darel (M5) |
| **Channel: Blibli** | ~15 | 🔴 0% | Rasyid (M5) |
| **Warehouse** | 8 | ✅ Done | — |
| **Inventory Core** | 6 | ✅ Done | — |
| **Inventory Extended** | ~25 | 🔴 0% | Darel (M2) |
| **Inbound** | 6 | ✅ 90% | — |
| **Purchase Order** | ~25 | 🔴 0% | Rasyid (M1) |
| **Supplier Portal** | ~15 | 🔴 0% | Rasyid (M1) |
| **Outbound (Pick/Pack/Ship)** | ~30 | 🟡 5% skeleton | Darel (M1) |
| **Stock Opname** | ~10 | 🔴 0% | Darel (M2) |
| **Retur (Sales+Purchase)** | ~15 | 🔴 0% | Rasyid (M2) |
| **Dashboard & KPIs** | ~5 | 🔴 0% | Darel (M3) |
| **HPP** | ~15 | 🔴 0% | Darel (M3) |
| **Finance/Profitability** | ~5 | 🔴 0% | Darel (M3) |
| **Sales Analytics** | ~8 | 🔴 0% | Rasyid (M3) |
| **Stock Replenishment** | ~10 | 🔴 0% | Rasyid (M3) |
| **Forecast** | ~3 | 🔴 0% | Rasyid (M3) |
| **Loss Control** | ~15 | 🔴 0% | Rasyid (M3) |
| **Cash/Tax** | ~10 | 🔴 0% | Darel (M3) |
| **Warranty** | ~12 | 🔴 0% | Rasyid (M5) |
| **AI/SuperAI** | ~10 | 🔴 0% | Rasyid (M5) |
| **Sync Engine** | ~15 | 🔴 0% | Rasyid (M5) |
| **Webhook** | ~12 | 🟡 5% skeleton | Rasyid (M2) |
| **Notification** | ~4 | 🟡 5% skeleton | Rasyid (M2) |
| **Report** | ~12 | 🟡 5% skeleton | Darel (M6) |
| **Accounting (Journal/Invoice/CashBank)** | ~15 | 🔴 0% | Darel (M6) |
| **Contacts/Couriers** | ~10 | 🔴 0% | Rasyid (M6) |
| **Sales Orders Extended** | ~10 | 🔴 0% | Rasyid (M6) |
| **Settings/User Roles** | ~8 | 🔴 0% | Rasyid (M5) |
| **Region Data** | 4 | 🔴 0% | Darel (M6) |
| **Real-time Sync** | ~5 | 🔴 0% | Darel (M5) |

**Total endpoints target: ~500+**  
**Currently done: ~60 endpoints (~12%)**  

---

## 📝 Catatan Arsitektur

1. **Pattern per Marketplace** — ikuti arsitektur TikTok:
   - `{Channel}Client` → `{Channel}AuthService` → `{Channel}ProductService` → `{Channel}OrderService` → `{Channel}ProductMapper` → `{Channel}OrderMapper`

2. **Pattern dari cilupbah-ops yang HARUS direplikasi:**
   - HPP Cascading Resolver (3-tier: SKU → Product → Legacy)
   - Product Merge/Unmerge (group variants ke master product)
   - Stock Replenishment Calculator (avg hari, status, rec PO, keb hari)
   - Finance profitability (CTE-based SQL, per-SKU fee allocation)
   - Loss Control scan/match workflow
   - Sync Engine (cron scheduler, gated execution, watchdog, gap detection)
   - Supplier Portal (multi-tenant role separation, AI file redaction)

3. **Standar Kode:** Service-Repository Pattern, ApiResponse trait, Spatie QueryBuilder, no logic in Controllers

4. **Role System:** owner, admin, viewer, supplier (matching cilupbah-ops)
