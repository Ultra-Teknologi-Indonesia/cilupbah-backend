# WMS Project Milestone
**Target:** Feature parity dengan Jubelio (WMS + Omnichannel)  
**Durasi:** 1 bulan (4 minggu)  
**Tim:** Darel & Rasyid  
**Fokus:** Laravel Backend (API-first)  
**Referensi:** Jubelio API v1.0 (`dist (2).yaml` & `dist (3).yaml`)

---

## ✅ Sudah Dikerjakan (DONE)

### Module: Auth
- [x] Login / Register (Sanctum token-based auth)

### Module: Product
- [x] CRUD Produk (`ProductController` + `ProductService`)
- [x] Model Product & ProductVariant (SKU, barcode, harga beli/jual, berat, dimensi)
- [x] Tabel: `categories`, `brands`, `attributes`, `attribute_options`, `products`, `product_variants`, `variant_options`, `product_specifications`, `product_media`, `product_variation_types`, `product_wholesale_prices`, `product_bundles`
- [x] Import Excel produk biasa (`template_import_productv2.xlsx`) via `maatwebsite/excel`
- [x] Import Excel bundle (`template_import_bundle.xlsx`)
- [x] Download template Excel (GET endpoint)
- [x] Upload media / gambar produk (`MediaController`)
- [x] Channel Product Controller (CRUD produk per channel marketplace)
- [x] Seeder: Category, Brand, Attribute

### Module: Channel (TikTok Shop)
- [x] OAuth2 flow TikTok (redirect + callback + token refresh)
- [x] Multi-shop management (CRUD `channel_shops`)
- [x] Pull Orders dari TikTok (semua toko otomatis)
- [x] Pull Products dari TikTok (semua toko otomatis)
- [x] Push Product ke TikTok
- [x] Push Update Product ke TikTok
- [x] Sync Harga & Inventori ke TikTok
- [x] Bulk Push semua produk ke TikTok
- [x] Accept / Decline / Cancel order di TikTok
- [x] Order Mapper (TikTok → Internal format)
- [x] Product Mapper (TikTok → Internal format, Internal → TikTok format)
- [x] Channel identifier di tabel `products` (`channel_shop_id`, `source`)
- [x] Artisan Commands: `PullTikTokOrders`, `PullTikTokProducts`, `PushTikTokProduct`, `AcceptTikTokOrder`, `DeclineTikTokOrder`

### Module: Order
- [x] Tabel `orders` & `order_items` (dengan field lengkap: shipping, payment, channel status)
- [x] Upsert order dari channel (`OrderService::upsertFromChannel`)
- [x] CRUD Orders (`OrderController` apiResource)

### Module: Warehouse
- [x] CRUD Lokasi/Gudang (`LocationController` + `LocationService` + `LocationRepository`)
- [x] CRUD Bin/Rak (`LocationBinController` + `LocationBinService`)
- [x] Default bin per gudang
- [x] Channel Warehouse mapping (gudang ↔ marketplace)
- [x] Tabel: `locations`, `location_bins`, `channel_warehouses`

### Module: Inventory
- [x] Tabel: `inventories` (on_hand, on_order, reserved, available), `inventory_movements`, `inventory_transfers`
- [x] Stock Adjustment (tambah/kurang stok dengan validasi minus)
- [x] Stock Transfer antar gudang (dengan movement log)
- [x] Putaway (pindah dari bin inbound ke bin rak tujuan)
- [x] Reserve & Fulfill stock (untuk proses order)
- [x] Inventory movement history (audit trail)
- [x] API: `GET stocks`, `GET stocks/{itemId}`, `GET movements`, `POST adjustments`, `POST transfers`, `POST putaway`

### Module: Inbound
- [x] CRUD Inbound document (draft → partial → completed)
- [x] Receive items (parsial & penuh) dengan validasi qty
- [x] Auto-putaway ke default bin
- [x] Tabel: `inbounds`, `inbound_items`, `inbound_receipts`
- [x] Integrasi otomatis dengan Inventory (adjustment saat receive)

### Module: Outbound ⚠️ SKELETON ONLY
- [x] Controller dengan Swagger annotations (schema sudah didefinisikan)
- [ ] **Belum ada implementasi logic** (semua method `store()`, `update()`, `destroy()` kosong)

### Module: Report ⚠️ SKELETON ONLY
- [x] Controller dengan Swagger annotations
- [ ] **Belum ada implementasi logic** (semua method kosong, return `view()`)

### Module: Webhook ⚠️ SKELETON ONLY
- [x] Controller dengan Swagger annotations
- [ ] **Belum ada implementasi logic** (semua method kosong, return `view()`)

### Module: Notification ⚠️ SKELETON ONLY
- [x] Controller saja
- [ ] **Belum ada implementasi logic**

### Infrastruktur
- [x] Cloudflare R2 (S3-compatible) untuk file storage
- [x] Swagger / OpenAPI documentation (`l5-swagger`)
- [x] ApiResponse Trait untuk standarisasi response
- [x] FuzzyFilter untuk pencarian
- [x] Spatie QueryBuilder untuk pagination/sorting/filtering
- [x] Service-Repository Pattern (diterapkan di Channel, Warehouse, Inventory, Inbound)
- [x] `agents.md` coding standard guide

---

## 🔴 Yang Belum Ada Sama Sekali (TODO)

### WMS Core
- [ ] Purchase Order (PO) & Receiving (terpisah dari Inbound generik — perlu supplier, billing)
- [ ] Stock Opname (scan fisik rak → bandingkan dengan sistem → finalize)
- [ ] Outbound: Picking (buat picklist, assign picker, scan per item)
- [ ] Outbound: Packing (scan barcode, update qty packed, mark complete)
- [ ] Outbound: Shipping (buat jadwal shipment, assign kurir, cetak AWB, proof of delivery)
- [ ] Retur Inbound (terima retur dari customer, marketplace return)
- [ ] Retur Outbound (kirim balik ke supplier / purchase return)
- [ ] Sales Order internal (penjualan non-marketplace)
- [ ] Laporan: Stok harian, pergerakan barang, adjustment, putaway, picking, shipping, retur

### Omnichannel Integration
- [ ] Shopee: OAuth, Pull/Push produk, Pull order, Sync stok, Webhook
- [ ] Tokopedia: OAuth, Pull/Push produk, Pull order, Sync stok, Webhook
- [ ] Lazada: OAuth, Pull/Push produk, Pull order, Sync stok, Webhook
- [ ] Blibli: OAuth, Pull/Push produk, Pull order, Sync stok, Webhook
- [ ] Sinkronisasi stok real-time lintas semua channel (event-driven)
- [ ] Auto-fulfill & status update ke semua marketplace
- [ ] Order aggregator dashboard (semua order dari semua channel dalam 1 list)
- [ ] Webhook listener (marketplace push event ke sistem kita)

---

## 🚀 Milestone Menuju 100%

### Milestone 1 — WMS Outbound & Purchase Order (Minggu 1)
> Fokus: Mengisi kekosongan terbesar di WMS core — outbound fulfillment dan purchase order.

**Darel (Outbound — Pick, Pack, Ship):**
- [ ] Buat migration `outbounds`, `outbound_items`, `picklists`, `picklist_items`, `packlists`, `shipments`, `shipment_items`
- [ ] Buat model: `Outbound`, `OutboundItem`, `Picklist`, `PicklistItem`, `Packlist`, `Shipment`, `ShipmentItem`
- [ ] Buat `OutboundRepository`, `PicklistRepository`, `ShipmentRepository`
- [ ] Buat `OutboundService`:
  - `createFromOrder(orderId)` — buat outbound dari sales order
  - `createPicklist(outboundIds[], pickerId)` — gabung beberapa outbound jadi 1 picklist
  - `confirmPick(picklistId, items[])` — konfirmasi qty yang di-pick per item
- [ ] Buat `PackingService`:
  - `scanAndPack(salesorderId)` — mulai packing
  - `updateQtyPacked(packlistId, items[])` — update qty packed
  - `markComplete(packlistId)` — tandai selesai packing
- [ ] Buat `ShipmentService`:
  - `createSchedule(data)` — buat jadwal kirim
  - `addOrderToShipment(shipmentId, salesorderId)` — tambah order ke jadwal
  - `confirmPickup(shipmentId)` — kurir sudah ambil
- [ ] Buat `OutboundController` (refactor dari skeleton):
  - `POST /api/v1/outbounds` — buat outbound
  - `POST /api/v1/picklists` — buat picklist
  - `PUT /api/v1/picklists/{id}/confirm` — konfirmasi picking
  - `POST /api/v1/packlists` — mulai packing
  - `PUT /api/v1/packlists/{id}/complete` — selesai packing
  - `POST /api/v1/shipments` — buat jadwal kirim
  - `PUT /api/v1/shipments/{id}/pickup` — konfirmasi kurir ambil
- [ ] Integrasi `InventoryService::fulfillStock()` saat picking selesai

**Rasyid (Purchase Order & Sales Order Internal):**
- [ ] Buat migration `suppliers`, `purchase_orders`, `purchase_order_items`, `purchase_bills`, `purchase_bill_items`
- [ ] Buat model: `Supplier`, `PurchaseOrder`, `PurchaseOrderItem`, `PurchaseBill`
- [ ] Buat `SupplierRepository`, `PurchaseOrderRepository`
- [ ] Buat `PurchaseOrderService`:
  - `create(data)` — buat PO baru (DRAFT)
  - `approve(poId)` — approve PO
  - `receive(poId, items[])` — terima barang dari PO → otomatis buat Inbound
- [ ] Buat `PurchaseOrderController`:
  - `POST /api/v1/purchase-orders` — buat PO
  - `GET /api/v1/purchase-orders` — list PO
  - `GET /api/v1/purchase-orders/{id}` — detail PO
  - `PUT /api/v1/purchase-orders/{id}/approve` — approve
  - `POST /api/v1/purchase-orders/{id}/receive` — terima barang
  - `DELETE /api/v1/purchase-orders/{id}` — hapus PO draft
- [ ] Buat `SupplierController` CRUD:
  - `apiResource('suppliers')` — CRUD supplier
- [ ] Buat `SalesOrderService`:
  - `create(data)` — buat SO manual (non-marketplace)
  - `confirm(soId)` → otomatis buat Outbound & reserve stock
  - `cancel(soId)` → release reserved stock
- [ ] Tambahkan field `type` di `orders` table (`MARKETPLACE`, `MANUAL`, `POS`)

---

### Milestone 2 — Stock Opname, Retur & Laporan (Minggu 2)
> Fokus: Fitur WMS lanjutan — stock opname, retur dua arah, dan laporan.

**Darel (Stock Opname & Laporan):**
- [ ] Buat migration `stock_opnames`, `stock_opname_items`
- [ ] Buat model: `StockOpname`, `StockOpnameItem`
- [ ] Buat `StockOpnameRepository`, `StockOpnameService`:
  - `create(locationId, binId, processBy)` — buat sesi opname
  - `scanItem(opnameId, itemId, actualQty)` — input qty fisik
  - `finalize(opnameId)` — selesaikan opname → otomatis adjustment selisih
- [ ] Buat `StockOpnameController`:
  - `POST /api/v1/stock-opnames` — mulai opname
  - `GET /api/v1/stock-opnames` — list opname
  - `GET /api/v1/stock-opnames/{id}` — detail (items + selisih)
  - `PUT /api/v1/stock-opnames/{id}/scan` — input qty fisik
  - `POST /api/v1/stock-opnames/{id}/finalize` — finalisasi
- [ ] Buat `ReportService`:
  - `stockSummary(locationId, date)` — ringkasan stok per gudang
  - `movementReport(filters)` — pergerakan barang
  - `adjustmentReport(filters)` — history adjustment
  - `putawayReport(filters)` — history putaway
  - `pickingReport(filters)` — history picking
  - `shippingReport(filters)` — history pengiriman
- [ ] Refactor `ReportController` (dari skeleton):
  - `GET /api/v1/reports/stock-summary` — laporan stok
  - `GET /api/v1/reports/movements` — laporan pergerakan
  - `GET /api/v1/reports/adjustments` — laporan adjustment
  - `GET /api/v1/reports/shipping` — laporan kirim
- [ ] Semua report support export Excel (maatwebsite/excel)

**Rasyid (Retur Inbound & Outbound):**
- [ ] Buat migration `returns`, `return_items`
- [ ] Buat model: `ReturnOrder`, `ReturnItem`
- [ ] Buat `ReturnRepository`
- [ ] Buat `SalesReturnService`:
  - `createFromOrder(orderId, items[])` — buat retur dari order
  - `accept(returnId)` — terima retur → stok masuk kembali via InventoryService
  - `reject(returnId)` — tolak retur
  - `receiveItems(returnId, items[])` — terima barang fisik retur
- [ ] Buat `PurchaseReturnService`:
  - `createFromPO(poId, items[])` — buat retur ke supplier
  - `ship(returnId)` — kirim barang kembali → kurangi stok
- [ ] Buat `ReturnController`:
  - `POST /api/v1/returns/sales` — buat retur penjualan
  - `POST /api/v1/returns/purchase` — buat retur pembelian
  - `GET /api/v1/returns` — list semua retur
  - `GET /api/v1/returns/{id}` — detail retur
  - `PUT /api/v1/returns/{id}/accept` — terima retur
  - `PUT /api/v1/returns/{id}/reject` — tolak retur
  - `POST /api/v1/returns/{id}/receive` — terima barang fisik
- [ ] Buat Webhook listener foundation:
  - `POST /api/v1/webhooks/tiktok` — endpoint menerima push event dari TikTok
  - Implement signature verification (SHA256)
  - Handle event: `order.status.update`, `return.status.update`
- [ ] Refactor `WebhookController` (dari skeleton ke working)
- [ ] Refactor `NotificationController` — kirim notifikasi internal saat event penting (order baru, retur, stok habis)

---

### Milestone 3 — Shopee & Tokopedia Integration (Minggu 3)
> Fokus: Replikasi arsitektur TikTok ke dua marketplace terbesar Indonesia.

**Darel (Shopee Integration):**
- [ ] Buat `ShopeeClient` (HTTP client untuk Shopee Open Platform API v2)
  - Base URL, signature generation (HMAC-SHA256), timestamp
- [ ] Buat `ShopeeAuthService` (OAuth flow — redirect, callback, token refresh)
- [ ] Buat `ShopeeAuthController`:
  - `GET /api/v1/shopee/auth` — redirect ke Shopee OAuth
  - `GET /api/v1/shopee/callback` — handle callback
- [ ] Buat `ShopeeProductService`:
  - `pullProducts(shopId)` — tarik produk dari Shopee
  - `pushProduct(productId, shopId)` — upload produk ke Shopee
  - `syncPriceAndStock(productId, shopId)` — sync harga & stok
- [ ] Buat `ShopeeOrderService`:
  - `pullOrders(shopId)` — tarik order dari Shopee
  - `acceptOrder(shopId, orderId)` — terima order
  - `shipOrder(shopId, orderId, trackingNo)` — update status kirim
- [ ] Buat `ShopeeProductMapper` & `ShopeeOrderMapper`
- [ ] Buat `ShopeeStoreController` (list/delete toko Shopee yang terkoneksi)
- [ ] Buat `ShopeeSyncApiController` (pull/push/sync endpoint)
- [ ] Daftarkan routes di `Modules/Channel/routes/api.php` prefix `shopee/`
- [ ] Buat Artisan Commands: `PullShopeeOrders`, `PullShopeeProducts`

**Rasyid (Tokopedia Integration):**
- [ ] Buat `TokopediaClient` (HTTP client untuk Tokopedia API)
  - Base URL, app_id / secret, auth header
- [ ] Buat `TokopediaAuthService` (OAuth flow)
- [ ] Buat `TokopediaAuthController`:
  - `GET /api/v1/tokopedia/auth` — redirect ke Tokopedia OAuth
  - `GET /api/v1/tokopedia/callback` — handle callback
- [ ] Buat `TokopediaProductService`:
  - `pullProducts(shopId)` — tarik produk
  - `pushProduct(productId, shopId)` — upload produk
  - `syncPriceAndStock(productId, shopId)` — sync harga & stok
- [ ] Buat `TokopediaOrderService`:
  - `pullOrders(shopId)` — tarik order
  - `acceptOrder(shopId, orderId)` — terima order
  - `shipOrder(shopId, orderId, trackingNo)` — update status kirim
- [ ] Buat `TokopediaProductMapper` & `TokopediaOrderMapper`
- [ ] Buat `TokopediaStoreController` & `TokopediaSyncApiController`
- [ ] Daftarkan routes di `Modules/Channel/routes/api.php` prefix `tokopedia/`
- [ ] Buat Artisan Commands: `PullTokopediaOrders`, `PullTokopediaProducts`

---

### Milestone 4 — Lazada, Blibli, Real-time Sync & Polish (Minggu 4)
> Fokus: Channel terakhir, event-driven sync, auto-fulfill, dan stabilisasi.

**Darel (Lazada + Real-time Stock Sync):**
- [ ] Buat `LazadaClient`, `LazadaAuthService`, `LazadaAuthController`
- [ ] Buat `LazadaProductService`, `LazadaOrderService`
- [ ] Buat `LazadaProductMapper`, `LazadaOrderMapper`
- [ ] Buat `LazadaStoreController`, `LazadaSyncApiController`
- [ ] Registrasi routes prefix `lazada/`
- [ ] Buat event-driven stock sync:
  - Event `StockUpdated` → Listener `SyncStockToAllChannels`
  - Setiap kali stok berubah (adjustment, transfer, fulfill, return) → push ke semua channel yang terhubung
  - Pakai Laravel Queue (Redis/database) agar non-blocking
- [ ] Buat `ChannelStockSyncService`:
  - `syncToAll(itemId)` — push stok ke semua marketplace yang punya produk tersebut
  - Lookup `channel_shop_id` + `source` di `products` → panggil service masing-masing
- [ ] Auto-fulfill flow:
  - Saat order status `PROCESSING` → otomatis buat Outbound → reserve stock
  - Saat picking + packing selesai → update status di marketplace via API masing-masing

**Rasyid (Blibli + Webhook + Order Aggregator):**
- [ ] Buat `BlibliClient`, `BlibliAuthService`, `BlibliAuthController`
- [ ] Buat `BlibliProductService`, `BlibliOrderService`
- [ ] Buat `BlibliProductMapper`, `BlibliOrderMapper`
- [ ] Buat `BlibliStoreController`, `BlibliSyncApiController`
- [ ] Registrasi routes prefix `blibli/`
- [ ] Webhook listeners untuk semua channel:
  - `POST /api/v1/webhooks/shopee` — Shopee push event
  - `POST /api/v1/webhooks/tokopedia` — Tokopedia push event
  - `POST /api/v1/webhooks/lazada` — Lazada push event
  - `POST /api/v1/webhooks/blibli` — Blibli push event
  - Signature verification per marketplace
  - Handle event: `order.new`, `order.status`, `return.request`, `product.update`
- [ ] Order aggregator:
  - `GET /api/v1/orders/aggregated` — list semua order dari semua channel, support filter by `source`, `channel_shop_id`, `status`, `date_range`
  - Implement Spatie QueryBuilder dengan FuzzyFilter
- [ ] Scheduler untuk auto-pull:
  - `php artisan schedule:run` → pull orders setiap 15 menit dari semua channel aktif
  - Pull products setiap 1 jam dari semua channel aktif
- [ ] Final polish:
  - Pastikan semua controller pakai ApiResponse trait
  - Pastikan semua repository pakai Spatie QueryBuilder
  - Pastikan semua per_page default 10
  - Review semua Service — tidak boleh ada logic di Controller
  - Update `agents.md` dengan standar baru

---

## 📊 Progress Tracker

| Area | Progress | Status | PIC |
|------|----------|--------|-----|
| **Auth & User** | 100% | ✅ Done | Darel |
| **Product (CRUD + Import + Media)** | 95% | ✅ Done | Darel |
| **Channel: TikTok Shop** | 90% | ✅ Done | Darel |
| **Channel: Shopee** | 0% | 🔴 TODO | Darel (M3) |
| **Channel: Tokopedia** | 0% | 🔴 TODO | Rasyid (M3) |
| **Channel: Lazada** | 0% | 🔴 TODO | Darel (M4) |
| **Channel: Blibli** | 0% | 🔴 TODO | Rasyid (M4) |
| **Warehouse (Lokasi + Bin + Channel)** | 100% | ✅ Done | - |
| **Inventory (Stok + Movement)** | 100% | ✅ Done | - |
| **Inbound (PO Receive + Putaway)** | 90% | ✅ Done | - |
| **Purchase Order** | 0% | 🔴 TODO | Rasyid (M1) |
| **Outbound (Pick/Pack/Ship)** | 5% | 🟡 Skeleton | Darel (M1) |
| **Stock Opname** | 0% | 🔴 TODO | Darel (M2) |
| **Retur (Sales + Purchase)** | 0% | 🔴 TODO | Rasyid (M2) |
| **Report & Export** | 5% | 🟡 Skeleton | Darel (M2) |
| **Webhook Listener** | 5% | 🟡 Skeleton | Rasyid (M2/M4) |
| **Notification** | 5% | 🟡 Skeleton | Rasyid (M2) |
| **Real-time Stock Sync** | 0% | 🔴 TODO | Darel (M4) |
| **Auto-fulfill** | 0% | 🔴 TODO | Darel (M4) |
| **Order Aggregator** | 0% | 🔴 TODO | Rasyid (M4) |
| **Scheduler (Auto-pull)** | 0% | 🔴 TODO | Rasyid (M4) |

---

## 🔗 Dependency Graph (Blocking Tasks)

```
M1: Purchase Order ──→ M2: Purchase Return (butuh PO dulu)
M1: Outbound       ──→ M2: Sales Return (butuh Outbound dulu)
M1: Outbound       ──→ M4: Auto-fulfill (butuh pick/pack/ship dulu)
M2: Webhook foundation ──→ M4: Webhook per channel
M3: Shopee/Tokopedia    ──→ M4: Real-time Sync + Order Aggregator
```

---

## 📝 Catatan Teknis

1. **Semua channel marketplace** harus mengikuti pola arsitektur yang sama dengan TikTok:
   - `{Channel}Client` — HTTP client (signature, auth header)
   - `{Channel}AuthService` — OAuth flow
   - `{Channel}ProductService` — Pull/Push/Sync produk
   - `{Channel}OrderService` — Pull/Accept/Decline/Ship order
   - `{Channel}ProductMapper` — Mapping format channel ↔ internal
   - `{Channel}OrderMapper` — Mapping format channel ↔ internal

2. **Standar Kode** (lihat `agents.md`):
   - Service-Repository Pattern
   - Semua response pakai `ApiResponse` trait
   - Semua pagination pakai Spatie QueryBuilder + FuzzyFilter
   - Default per_page = 10
   - Tidak boleh ada logic bisnis di Controller
   - Tidak boleh ada `view()` atau `redirect()` di Controller API

3. **Database per Module**: Setiap module menyimpan migration-nya sendiri di `Modules/{Module}/database/migrations/`
