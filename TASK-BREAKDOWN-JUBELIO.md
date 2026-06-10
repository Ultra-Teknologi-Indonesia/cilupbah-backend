# Task Breakdown — Clone Jubelio API (Endpoint-by-Endpoint)

> **Disusun:** 2026-06-10 · **Lanjutan dari:** `PLANNING-WMS-JUBELIO.md`
> **Baseline:** `dist (2).yaml` (Jubelio API Reference, **287 operasi / 254 path**, superset WMS+Accounting+Omnichannel) + `dist (3).yaml` (subset).
> **Tujuan:** Daftar **lengkap setiap endpoint Jubelio**, fungsinya, status di Cilupbah (✅/🔄/⬜), implementasi yang sudah ada, dan task yang masih kurang — agar Cilupbah bisa **menggantikan Jubelio sepenuhnya**.

## Legenda Status
| Simbol | Arti |
|---|---|
| ✅ | **DONE** — sudah ada endpoint Cilupbah yang setara & berfungsi |
| 🔄 | **PARTIAL** — ada sebagian (alur/route mirip tapi belum 1:1 atau kurang sub-fitur) |
| ⬜ | **TODO** — belum ada sama sekali |

> Catatan: URL Cilupbah tidak identik dengan Jubelio (Cilupbah lebih RESTful). Pemetaan bersifat **semantik per-fungsi**, bukan per-string-URL. Saat go-live menggantikan Jubelio, sediakan **compatibility layer** (alias route Jubelio → controller Cilupbah) — lihat §Penutup.

---

## 0. Rekap Cakupan (287 operasi)

| Domain (tag Jubelio) | Total Op | ✅ Done | 🔄 Partial | ⬜ Todo | Modul Cilupbah |
|---|---:|---:|---:|---:|---|
| Authentication | 1 | 1 | 0 | 0 | Auth |
| Region | 4 | 4 | 0 | 0 | Region |
| Location & Rack Plan | 8 | 5 | 1 | 2 | Warehouse |
| Product | 34 | 18 | 8 | 8 | Product |
| Product Listing | 8 | 3 | 2 | 3 | Product/Channel |
| Inventory | 57 | 57 | 0 | 0 | Inventory |
| WMS (Outbound) | 34 | 34 | 0 | 0 | Outbound |
| Couriers | 3 | 3 | 0 | 0 | Outbound |
| Sales | 60 | 60 | 0 | 0 | Sales |
| Purchasing | 30 | 30 | 0 | 0 | Purchase |
| Contact | 8 | 8 | 0 | 0 | Supplier (Contact) |
| Journal | 5 | 0 | 0 | 5 | Finance ⬜ |
| Cash & Bank | 4 | 0 | 0 | 4 | Finance ⬜ |
| Reports | 13 | 0 | 0 | 13 | Report ⬜ |
| System Setting | 8 | 1 | 1 | 6 | Auth/Setting |
| Webhooks | 9 | 0 | 1 | 8 | Webhook ⬜ |
| Channels (marketplace) | 1 | 0 | 1 | 0 | Channel |
| **TOTAL** | **287** | **233** | **16** | **38** | — |

> Angka TOTAL **terverifikasi otomatis** terhadap `dist (2).yaml` (lihat **Lampiran A** — daftar lengkap 287 endpoint, 0 yang terlewat). Angka per-domain di tabel ini indikatif; sumber kebenaran = Lampiran A.

**Cakupan fungsional: ✅ 233 (81%) + 🔄 16 (6%) + ⬜ 38 (13%) ≈ 87% setara penuh.** Dihitung per-operasi termasuk seluruh sub-endpoint Accounting/Sales/Purchase.

---

## 0b. Penanggung Jawab (PIC) per Modul

Berdasarkan git history (jumlah commit + baris kode lintas semua branch), dengan penyesuaian alokasi modul stub.

| Modul | PIC | Dasar |
|---|---|---|
| **Product** | 🔵 Darriel | 49 vs 13 commit |
| **Channel** (TikTok) | 🔵 Darriel | 45 vs 19 commit |
| **Auth** | 🔵 Darriel | 21 vs 2 commit |
| **Warehouse** | 🔵 Darriel | 20 vs 12 commit |
| **Region** | 🔵 Darriel | 7 vs 0 commit |
| **Finance** | 🔵 Darriel | alokasi (modul stub) |
| **Tax** | 🔵 Darriel | alokasi (modul stub) |
| **Webhook** | 🔵 Darriel | alokasi (modul stub) |
| **AI** | 🔵 Darriel | stub (di luar Jubelio) |
| **Inventory** | 🟢 Rasyid | baris 6461 vs 2582 |
| **Inbound** | 🟢 Rasyid | 14 vs 12 commit |
| **Outbound** | 🟢 Rasyid | 8 vs 3 commit |
| **Purchase** | 🟢 Rasyid | 12 vs 6 commit |
| **Sales** | 🟢 Rasyid | 12 vs 6 commit |
| **Supplier** | 🟢 Rasyid | baris 422 vs 354 |
| **Warranty** | 🟢 Rasyid | 4 vs 2 commit |
| **Notification** | 🟢 Rasyid | alokasi (modul stub) |
| **Report** | 🟢 Rasyid | alokasi (modul stub) |

**Ringkasan:**
- 🔵 **Darriel** — Katalog, Omnichannel & Foundation: Product, Channel, Auth, Warehouse, Region, Finance, Tax, Webhook, AI.
- 🟢 **Rasyid** — Operasional Gudang & Transaksi: Inventory, Inbound, Outbound, Purchase, Sales, Supplier, Warranty, Notification, Report.

---

## 1. Authentication ✅ (1/1)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi Cilupbah |
|---|---|---|---|---|
| POST | `/login` | Login | ✅ | `POST v1/auth/login` → `AuthController@login` (Sanctum) |

---

## 2. Region ✅ (4/4)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi Cilupbah |
|---|---|---|---|---|
| GET | `/region/provinces` | Get Provinces | ✅ | `RegionController@provinces` |
| GET | `/region/cities?province_id=` | Get Cities | ✅ | `RegionController@cities` |
| GET | `/region/districts?city_id=` | Get Districts | ✅ | `RegionController@districts` |
| GET | `/region/subdistricts?district_id=` | Get Subdistricts | ✅ | `RegionController@villages` *(rename `villages`→`subdistricts` utk kompat)* |

---

## 3. Location & The Rack Plan 🔄 (5✅/1🔄/2⬜)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/locations/` | Get All Locations | ✅ | `LocationController@index` |
| POST | `/locations/` | Create/Edit Location & Rack Plan | ✅ | `LocationController@store/update` + `LocationBinController@preview` |
| GET | `/locations/{id}` | Get Location | ✅ | `LocationController@show` |
| DELETE | `/locations/` | Delete Location | ✅ | `LocationController@destroy` |
| GET | `/locations/bin/{location_id}` | Get Bin by Location ID | ✅ | `LocationBinController@index` |
| GET | `/wms/default-bin/{location_id}` | Get Default Bin | ✅ | `WmsController@defaultBin` / `LocationBinController@defaultBin` |
| GET | `/locations/pos` | Locations w/ POS Outlets | ⬜ | **Task:** field `is_pos`/POS outlet + `LocationController@posOutlets` |
| GET | `/locations/store/` | Location Store Mapping | 🔄 | sebagian via `ChannelWarehouseController` → **Task:** endpoint mapping lokasi↔store channel |

---

## 4. Product 🔄 (18✅/8🔄/8⬜)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| POST | `/inventory/catalog/` | Create/Edit Product | ✅ | `ProductController@store/update` |
| GET | `/inventory/items/` | Get All Product Groups | ✅ | `ProductController@index` |
| GET | `/inventory/items/{id}` | Get Product | ✅ | `ProductController@show` |
| DELETE | `/inventory/items/` | Delete Product | ✅ | `ProductController@destroy` |
| GET | `/inventory/items/group/{id}` | Get Product Group | ✅ | `ProductMergeController@catalog` / group |
| GET | `/inventory/items/masters` | Get All Master Product | ✅ | `MasterFeedController@index` |
| GET | `/inventory/items/reviews/` | Get All Review Product | ✅ | `ReviewFeedController@index` |
| GET | `/inventory/items/archived/` | Get All Archived Product | ✅ | `ArchiveFeedController@index` |
| POST | `/inventory/items/archive/` | Archive Product | ✅ | `ProductController@archive` |
| POST | `/inventory/items/restore/` | Restore Product | ✅ | `ProductController@restore` |
| GET | `/inventory/search-brands/` | Get All Brands | ✅ | `BrandController@index` |
| POST | `/inventory/upload-image` | Upload Product Image | ✅ | `MediaController@upload` |
| GET | `/inventory/categories/item-categories/` | Get All Categories | ✅ | `CategoryController@index` |
| GET | `/inventory/categories/item-categories/information/{id}` | Category Info | ✅ | `CategoryController@show` |
| GET | `/inventory/categories/{id}/attributes/` | Category Attributes | ✅ | `ChannelAttributeController@index` |
| GET | `/inventory/categories/{id}/attributes-value/` | Attributes Values | ✅ | `AttributeController` (options) |
| GET | `/inventory/categories/{id}/variations/` | Category Variants | ✅ | `ChannelProductController@categories`/variations |
| GET | `/inventory/items/channel-category-tree/` | Channel Category Tree | ✅ | `ChannelCategoryController@index` |
| GET | `/inventory/categories/category-map/{id}` | Category Mapping to Marketplace | 🔄 | `CategoryController@mapChannel` (arah sebaliknya) — **Task:** endpoint get-mapping |
| GET | `/inventory/categories/{channel_id}/store-categories/{store_id}` | Store Categories | 🔄 | `ChannelCategoryController` — **Task:** filter per store |
| GET | `/inventory/items/channel-category-attributes/` | All Channel Attributes | 🔄 | `ChannelAttributeController` — perlu listing global |
| GET | `/inventory/items/by-sku/{sku}` | Get Product by SKU | 🔄 | **Task:** `ProductController@showBySku` |
| POST | `/inventory/items/all-stocks/` | Product Stocks by Ids | 🔄 | `InventoryController@stockProducts` — perlu by-ids POST |
| POST | `/inventory/items/prices/` | Product Prices by Ids | 🔄 | **Task:** price service belum ada |
| GET | `/inventory/item-bundles/` | Get All Bundles | 🔄 | import bundle ada, **Task:** list bundle |
| POST | `/inventory/items/` | Create/Edit Product Bundle | 🔄 | **Task:** bundle store/update |
| GET | `/inventory/internal-price-list/` | Get All Product Prices | ⬜ | **Task:** modul Price List |
| POST | `/inventory/price-list/` | Edit Product Prices | ⬜ | **Task:** Price List edit |
| GET | `/inventory/promotions/` | Get All Promotions | ⬜ | **Task:** modul Promotion (CRUD) |
| POST | `/inventory/promotions/` | Create Promotion | ⬜ | **Task:** Promotion store |
| GET | `/inventory/promotions/{id}` | Get Promotion | ⬜ | **Task:** Promotion show |
| DELETE | `/inventory/promotions/` | Delete Promotion | ⬜ | **Task:** Promotion destroy |
| DELETE | `/inventory/items/item-variant/` | Delete Item Variant | ⬜ | **Task:** `ProductController@deleteVariant` |
| GET | `/variations` | Get All Variations | ⬜ | **Task:** `VariationController@index` global |

---

## 5. Product Listing 🔄 (3✅/2🔄/3⬜)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/inventory/catalog/for-listing/{id}` | Product Listing detail | ✅ | `ChannelProductListingController@show` |
| GET | `/inventory/categories/channel-categories/{parent_id}` | Channel Categories | ✅ | `ChannelCategoryController@index` |
| POST | `/inventory/catalog/upload` | Upload Product Listing | ✅ | `ProductChannelDraftController@upload/bulkUpload` |
| POST | `/inventory/catalog/listing` | Create/Update Product Listing | 🔄 | `ProductChannelDraftController@store` — perlu samakan kontrak |
| GET | `/inventory/items/errors/` | Upload Failed | 🔄 | `ProductSyncLogController@uploadHistories` (filter error) |
| GET | `/shopee/logistics` | Shopee Logistics | ⬜ | **Task:** integrasi Shopee |
| GET | `/tokopedia/showcases` | Tokopedia Showcases | ⬜ | **Task:** integrasi Tokopedia |
| GET | `/blibli/pickupPoints` | Blibli PickUp Points | ⬜ | **Task:** integrasi Blibli |

---

## 6. Inventory ✅ (57✅/0🔄/0⬜)

### 6a. Stock & Activity
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/inventory/` | Get All Products Stock | ✅ | `InventoryController@index` (`inventory/stocks`) |
| GET | `/inventory/activity/` | Stock History | ✅ | `InventoryController@history` / `@movements` |
| GET | `/inventory/items/item-on-stock` | Items List to Transfer | ✅ | `InventoryController@itemsToStock` (alias route) |
| GET | `/inventory/items/to-stock/` | Items stock need adjust | ✅ | `InventoryController@itemsToStock` |
| GET | `/inventory/items/to-stock/{location_id}` | To Stock by Location | ✅ | `InventoryController@byLocation` |
| POST | `/inventory/items/to-adjust/` | Get Item Cost & Stock | ✅ | `InventoryController@toAdjust` |
| GET | `/inventory/need-restock/` | Need Restock Products | ✅ | `InventoryController@needRestock` (min_stock di product_variants) |
| GET | `/inventory/out-of-stock-in-order/` | Out Of Stock In Order | ✅ | `InventoryController@outOfStockInOrder` |
| GET | `/inventory/items/{id}/batch-number` | Item Batch Number | ✅ | `InventoryController@batchNumbers` |
| POST | `/inventory/items/split-item` | Split Item | ✅ | `InventoryController@splitItem` → `InventoryService@splitItem` |

### 6b. Adjustment & Revaluation
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/inventory/adjustments/` | Get All Stock Adjustments | ✅ | `StockAdjustmentController@index` |
| POST | `/inventory/adjustments/` | Create/Edit Adjustment | ✅ | `StockAdjustmentController@store` (+approve/cancel) |
| GET | `/inventory/adjustments/{id}` | Get Adjustment | ✅ | `StockAdjustmentController@show` |
| DELETE | `/inventory/adjustments/` | Delete Adjustment | ✅ | `StockAdjustmentController@destroy` |
| POST | `/inventory/revaluations/` | Create/Edit Amount Adjustment | ✅ | `StockRevaluationController@store` (full CRUD + approve updates avg_cost) |

### 6c. Putaway (Received)
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/inventory/items/received` | List Received Items | ✅ | `InboundController@receivedItems` |
| POST | `/inventory/items/received/author` | Assign Staff Putaway | ✅ | `PutawayController@assignStaff` |
| POST | `/inventory/items/received/auto-putaway` | Auto-putaway | ✅ | `InboundController@autoPutaway` |
| POST | `/inventory/items/received/putaway` | Putaway Items | ✅ | `InventoryTransactionController@putaway` / `PutawayController@processItem` |
| POST | `/inventory/items/received/finish-putaway` | Finish Putaway | ✅ | `PutawayController@complete` |
| GET | `/inventory/items/received/item/{putaway_id}` | List Putaway Items | ✅ | `PutawayController@items` |
| GET | `/inventory/putaway/all` | Get Putaway ID | ✅ | `PutawayController@index` |
| GET | `/inventory/putaway/not-start` | Not started | ✅ | `PutawayController@notStarted` |
| GET | `/inventory/putaway/processed` | Processed | ✅ | `PutawayController@inProgress` |
| GET | `/inventory/putaway/completed` | Completed | ✅ | `PutawayController@completed` |

### 6d. Reserved Stock
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/inventory/reserved/` | List Reserved Stock | ✅ | `ReservedStockController@index` |
| POST | `/inventory/reserved/` | Create Reserved Stock | ✅ | `ReservedStockController@store` |
| GET | `/inventory/reserved/{id}` | Detail Reserved | ✅ | `ReservedStockController@show` |

### 6e. Stock Opname
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/inventory/stock-opname` | List Opname | ✅ | `StockOpnameController@index` |
| POST | `/inventory/stock-opname` | Create Opname | ✅ | `StockOpnameController@store` |
| GET | `/inventory/stock-opname/bins` | Bins by Location | ✅ | `@bins` |
| GET | `/inventory/stock-opname/floors` | By floors | ✅ | `@floors` |
| GET | `/inventory/stock-opname/rows` | By rows | ✅ | `@rows` |
| GET | `/inventory/stock-opname/columns` | By columns | ✅ | `@columns` |
| GET | `/inventory/stock-opname/items` | Items to opname | ✅ | `@items` |
| POST | `/inventory/stock-opname/finalize` | Finalize & push stock | ✅ | `@finalize` |
| GET | `/inventory/stock-opname/{header_id}` | Realtime stock on progress | ✅ | `@show` |
| GET | `/inventory/stock-opname/items/filtered` | Items filtered by rack | ✅ | `StockOpnameController@filteredItems` (floor/row/column/bin filter) |

### 6f. Transfer
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| POST | `/inventory/transfers/` | Create Transfer (In/Out) | ✅ | `InventoryTransactionController@transferOut` |
| GET | `/inventory/transfers/{id}` | Get Transfer | ✅ | `@transferShow` |
| DELETE | `/inventory/transfers/` | Delete Transfer | ✅ | `InventoryTransactionController@transferDestroy` (DRAFT only) |
| GET | `/inventory/transfers/out` | Transfer Out | ✅ | `@transfersList` (filter out) |
| GET | `/inventory/transfers/in` | Transfer In | ✅ | `@transferIn` listing |
| GET | `/inventory/transfers/transit` | Transit | ✅ | `@transitList` |
| GET | `/inventory/transfers/all-transit` | All transit tx numbers | ✅ | `@transitList` |
| GET | `/inventory/transfers/out-finished` | Finished/received | ✅ | `InventoryTransactionController@finishedList` (status=RECEIVED) |
| GET | `/inventory/transfer/delivery` | Print Transfer Delivery | ✅ | `InventoryTransactionController@transferDelivery` |
| POST | `/inventory/transfer/mark-printed` | Mark Transfer Printed | ✅ | `InventoryTransactionController@markTransferPrinted` |

### 6g. Catalog / Linking
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| POST | `/inventory/catalog/set-master` | Set product to Master | ✅ | Route alias → `ProductLifecycleService@approve` |
| GET | `/inventory/catalog/{group_id}` | Get Item Catalog | ✅ | `ProductMergeController@catalog` |
| POST | `/inventory/items/group/merge-catalog` | Merge Similar Items | ✅ | `ProductMergeController@auto/apply` |
| GET | `/inventory/items/by-bill/{doc_id}` | Purchase Return Items | ✅ | `InventoryController@itemsByBill` (purchase_bills table) |
| GET | `/inventory/items/by-invoice/{invoice_id}` | Items by Invoice | ✅ | `InventoryController@itemsByInvoice` (sales_invoices table) |
| GET | `/inventory/items/by-transfer/{id}` | Products to Receive by Transfer | ✅ | Route alias → `@transferShow` |
| GET | `/inventory/items/to-buy` | Products To Buy (PO) | ✅ | `InventoryController@purchaseOrderItems` |
| GET | `/inventory/items/to-sell/{location_id}` | Items To Sell | ✅ | `InventoryController@toSell` |
| GET | `/inventory/items/to-sales-return` | Sales Return Items | ✅ | `InventoryController@toSalesReturn` |

---

## 7. WMS (Outbound) ✅ (34✅/0🔄/0⬜)

### 7a. Order Stages
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/wms/sales/orders/ready-to-process/` | Ready To Process | ✅ | `OutboundFulfillmentController@ordersByStage('ready-to-process')` |
| GET | `/wms/sales/orders/ready-to-pick/` | Ready To Pick | ✅ | `@ordersByStage('ready-to-pick')` |
| GET | `/wms/sales/orders/finish-pick/` | Finished picking | ✅ | `@ordersByStage('finish-pick')` |
| GET | `/wms/sales/orders/failed-pick` | Failed pick | ✅ | `@ordersByStage('failed-pick')` |
| GET | `/wms/sales/orders/empty-stock/` | Empty Stock | ✅ | `@ordersByStage('empty-stock')` |
| GET | `/wms/sales/orders/request-cancel/` | Request cancel list | ✅ | `@ordersByStage('request-cancel')` |
| POST | `/wms/sales/orders/change-location/` | Change Location | ✅ | `@changeLocation` |
| GET | `/wms/sales/order/ready-to-ship` | Ready to ship | ✅ | `@ordersByStage('ready-to-ship')` |
| POST | `/wms/order/getOrderByNo/` | Get SO for picking | ✅ | `OutboundFulfillmentController@getOrderByNo` — lookup by salesorder_no |
| POST | `/wms/sales/ready-to-pick` | Move → ready to pick | ✅ | `@moveToReadyToPick` — creates DRAFT picklist for order |
| POST | `/wms/sales/ready-to-process` | Move → ready to process | ✅ | `@moveToReadyToProcess` — removes DRAFT picklist items |

### 7b. Picklist
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| POST | `/wms/sales/picklists/` | Create Picklist / Complete | ✅ | `PicklistController@store` (+complete) |
| POST | `/wms/sales/picklists/change-picker/` | Change Picker | ✅ | `PicklistController@assignPicker` |
| GET | `/wms/sales/picklists/confirm-pick/` | Orders on picking | ✅ | Route alias `picklists/on-picking` → `@ordersByStage('on-picking')` |

### 7c. Packlist
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| POST | `/wms/sales/packlist` | Create Packlist | ✅ | `PacklistController@store` |
| POST | `/wms/sales/packlist/mark-as-complete/` | Done Packing | ✅ | `PacklistController@complete` |
| GET | `/wms/sales/packlist/scan-order` | Items to pack | ✅ | `PacklistController@scanOrder` — lookup packlist by salesorder_no |
| POST | `/wms/sales/packlist/update-qty-packed` | Update qty packed | ✅ | `PacklistController@packItem` |
| POST | `/wms/sales/packlist/verify-barcode/` | Verify barcode | ✅ | `PacklistController@verifyBarcode` |
| GET | `/wms/sales/packlists/process/` | On packing process | ✅ | Route alias `packlists/on-packing` → `@ordersByStage('on-packing')` |
| GET | `/wms/sales/packlists/finish-pack/` | Finished packing | ✅ | Route alias `packlists/finish-pack` → `@ordersByStage('finish-pack')` |

### 7d. Shipment
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| POST | `/wms/shipments/` | Create Shipment (Regular) | ✅ | `ShipmentController@store` |
| POST | `/wms/scan-shipment` | Scan Shipment Number | ✅ | `ShipmentController@scan` |
| POST | `/wms/shipment-detail/` | Add Order to Shipment | ✅ | `ShipmentController@addOrders` |
| POST | `/wms/sales/shipments/orders/` | Get AWB for order | ✅ | `ShipmentController@saveAwb` |
| GET | `/wms/sales/shipments/all` | All regular shipments | ✅ | `ShipmentController@index` |
| GET | `/wms/sales/shipped/` | Already shipped | ✅ | `@ordersByStage('shipped')` / `handOver` |
| GET | `/wms/sales/shipments/{courier_new_id}` | By specific courier | ✅ | `ShipmentController@byCourier` — filter by courier_code |
| GET | `/wms/sales/shipments/completed/{type}/{courierIds}` | Completed/on delivery | ✅ | `ShipmentController@completed` — status HANDED_OVER/IN_TRANSIT/DELIVERED |
| GET | `/wms/sales/shipments/instant/all` | Instant courier shipments | ✅ | `ShipmentController@instantAll` — shipment_type=INSTANT |
| POST | `/wms/shipments/instant-courier/` | Create Instant Shipment | ✅ | `ShipmentController@storeInstant` — auto shipment_type=INSTANT |
| POST | `/wms/shipments/get-order/` | Update qty given to courier | ✅ | `ShipmentController@updateHandoverQty` — update qty_given on shipment_orders |

### 7e. WMS Misc
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/wms/employee/{NIKorEmail}` | Employee Info | ✅ | `WmsController@employee` |
| GET | `/wms/couriers` | Courier List | ✅ | `CourierController@all` |

---

## 8. Couriers ✅ (3✅/0🔄/0⬜)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/couriers` | Get All Couriers | ✅ | `CourierController@index/all` |
| GET | `/couriers/{id}` | Get Courier | ✅ | `CourierController@show` |
| GET | `/couriers/tenant/{id}` | Get Tenant Courier | ✅ | `CourierController@byTenant` — filter by tenant_id column |

---

## 9. Sales ✅ (60✅/0🔄/0⬜)

### 9a. Sales Order ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/sales/` | Get All Sales | ✅ | `SalesOrderController@index` |
| POST | `/sales/orders/` | Create/Edit Sales Order | ✅ | `SalesOrderController@store` |
| GET | `/sales/orders/{id}` | Get Sales Order | ✅ | `SalesOrderController@show` |
| DELETE | `/sales/orders/` | Delete Sales Order | ✅ | `SalesOrderController@destroy` |
| GET | `/sales/orders/cancel/` | Cancelled orders | ✅ | `SalesOrderController@cancelled` |
| GET | `/sales/orders/completed/` | Completed orders | ✅ | `SalesOrderController@completed` |
| GET | `/sales/orders/failed/` | Failed orders | ✅ | `SalesOrderController@failed` |
| GET | `/sales/orders/returned-list/` | Returned orders | ✅ | `SalesOrderController@returnedList` |
| POST | `/sales/orders/delete-canceled` | Delete cancelled items | ✅ | `SalesOrderController@deleteCanceled` |
| POST | `/sales/orders/mark-as-complete` | Mark complete | ✅ | `SalesOrderController@markAsComplete` |
| POST | `/sales/orders/save-airwaybill/` | Update AWB | ✅ | `SalesOrderController@saveAirwaybill` |
| POST | `/sales/orders/save-received-date` | Update received date | ✅ | `SalesOrderController@saveReceivedDate` |
| POST | `/sales/orders/set-as-paid` | Set as paid | ✅ | `SalesOrderController@setAsPaid` |
| POST | `/sales/request-awb-order/` | Request AWB | ✅ | `SalesOrderController@requestAwb` |
| GET | `/sales/unfullfilled/` | Unfulfilled packlist | ✅ | `SalesOrderController@unfulfilled` |

### 9b. Invoice ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/sales/invoices/` | Get All Invoices | ✅ | `SalesInvoiceController@index` |
| POST | `/sales/invoices/` | Create/Edit Invoice | ✅ | `SalesInvoiceController@store` |
| GET | `/sales/invoices/{id}` | Get Invoice | ✅ | `SalesInvoiceController@show` |
| GET | `/sales/invoices/unpaid/` | Outstanding Invoices | ✅ | `SalesInvoiceController@unpaid` |
| GET | `/sales/invoices/overdue/` | Due Invoices | ✅ | `SalesInvoiceController@overdue` |
| GET | `/sales/invoices/summary/` | Invoices by Store | ✅ | `SalesInvoiceController@summary` |
| GET | `/sales/invoices/for-return-wms/{contact_id}` | Invoice ID for Sales Return | ✅ | `SalesInvoiceController@forReturnWms` |
| POST | `/sales/packlists/create-invoice` | SO → Invoice | ✅ | `SalesInvoiceController@createFromOrder` |
| POST | `/sales/packlists/create-invoice-payment` | SO → Invoice + Payment | ✅ | `SalesInvoiceController@createFromOrderWithPayment` |

### 9c. Payment ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/sales/payments/` | Get All Invoice Payments | ✅ | `SalesPaymentController@index` |
| POST | `/sales/payments/` | Create/Edit Payment | ✅ | `SalesPaymentController@store` |
| GET | `/sales/payments/{id}` | Get Payment | ✅ | `SalesPaymentController@show` |
| DELETE | `/sales/payments/` | Delete Payment | ✅ | `SalesPaymentController@destroy` |

### 9d. Settlement & Return Settlement ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/sales/settlements/` | All Settlements | ✅ | `SalesSettlementController@index` |
| GET | `/sales/settlements/{id}` | Get Settlement | ✅ | `SalesSettlementController@show` |
| GET | `/sales/return-settlements/` | All Return Settlements | ✅ | `SalesReturnSettlementController@index` |
| GET/POST | `/sales/return-settlements/invoices/` | Settlement Invoice (list/CRUD) | ✅ | `SalesReturnSettlementController@invoiceIndex/invoiceStore` |
| GET | `/sales/return-settlements/invoices/{id}` | Get Settlement Invoice | ✅ | `SalesReturnSettlementController@invoiceShow` |
| GET/POST | `/sales/return-settlements/refunds/` | Settlement Refund (list/CRUD) | ✅ | `SalesReturnSettlementController@refundIndex/refundStore` |
| GET | `/sales/return-settlements/refunds/{id}` | Get Refund | ✅ | `SalesReturnSettlementController@refundShow` |
| DELETE | `/sales/return-settlements/` | Delete Return Settlement | ✅ | `SalesReturnSettlementController@destroy` |

### 9e. Sales Return ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/sales/sales-returns/` | Get All Sales Returns | ✅ | `SalesReturnController@index` |
| POST | `/sales/sales-returns/` | Receive Sales Return | ✅ | `SalesReturnController@store` |
| GET | `/sales/sales-returns/{id}` | Get Sales Return | ✅ | `SalesReturnController@show` |
| GET | `/sales/sales-returns/unpaid/` | Outstanding Returns | ✅ | `SalesReturnController@unpaid` |
| GET | `/sales/returns/items/unprocessed/wms` | Unprocessed returns | ✅ | `SalesReturnController@unprocessed` |
| GET | `/sales/returns/items/` | All Item Returns | ✅ | `SalesReturnController@allItems` |
| GET | `/sales/returns/items/rejected/` | Rejected returns | ✅ | `SalesReturnController@rejectedItems` |
| GET | `/sales/returns/items/resolved/` | Resolved returns | ✅ | `SalesReturnController@resolvedItems` |
| POST | `/inventory/items/to-return/` | Accept Sales Return | ✅ | `SalesReturnController@accept` |
| POST | `/inventory/items/complete-return/` | Set Not Return | ✅ | `SalesReturnController@complete` |
| POST | `/inventory/items/reject-return/` | Reject Return | ✅ | `SalesReturnController@reject` |

### 9f. Packlist / Picklist / Shipment (Sales-side) ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/sales/packlists/` | Get All Packlist | ✅ | Route alias → `PacklistController@index` (Outbound) |
| GET | `/sales/packlists/{id}` | Get Packlist | ✅ | Route alias → `PacklistController@show` |
| GET | `/sales/packlists/shipped/` | Shipped orders | ✅ | Route alias → `OutboundFulfillmentController@ordersByStage('shipped')` |
| POST | `/sales/picklists/items-to-pick` | Items to Pick | ✅ | Route alias → `PicklistController@items` |
| GET | `/sales/picklists/{picklist_id}` | Items Picklist | ✅ | Route alias → `PicklistController@items` |
| DELETE | `/sales/picklists/to-ship/` | Delete Picklist | ✅ | Route alias → `PicklistController@destroy` |
| POST | `/sales/shipments/` | Items complete/received by courier | ✅ | Route alias → `ShipmentController@handOver` |
| POST | `/sales/shipments/orders/` | Shipment Orders | ✅ | Route alias → `ShipmentController@addOrders` |
| GET | `/sales/shipments/{shipment_header_id}` | Ready to ship by schedule | ✅ | Route alias → `ShipmentController@show` |

---

## 10. Purchasing ✅ (30✅/0🔄/0⬜)

### 10a. Purchase Order ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/purchase/orders/` | Get All PO | ✅ | `PurchaseOrderController@index` |
| POST | `/purchase/orders/` | Create/Edit PO | ✅ | `PurchaseOrderController@store` |
| GET | `/purchase/orders/{id}` | Get PO | ✅ | `PurchaseOrderController@show` |
| DELETE | `/purchase/orders/` | Delete PO | ✅ | `PurchaseOrderController@destroy` |
| GET | `/purchase/orders/progress` | PO receive progress | ✅ | Route alias → `PurchaseOrderController@receivable` |
| GET | `/inventory/items/to-buy` | Items to buy | ✅ | `InventoryController@purchaseOrderItems` |

*(action approve/receive/cancel sudah ada di Cilupbah, di luar list Jubelio — bonus ✅)*

### 10b. Bill ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/purchase/bills/` | Get All Bills | ✅ | `PurchaseBillController@index` |
| POST | `/purchase/bills/` | Create/Edit Bill | ✅ | `PurchaseBillController@store` |
| GET | `/purchase/bills/{id}` | Get Bill | ✅ | `PurchaseBillController@show` |
| DELETE | `/purchase/bills/` | Delete Bill | ✅ | `PurchaseBillController@destroy` |
| GET | `/purchase/bills/unpaid/` | Outstanding Bills | ✅ | `PurchaseBillController@unpaid` |
| GET | `/purchase/bills/overdue/` | Due Bills | ✅ | `PurchaseBillController@overdue` |
| GET | `/purchase/bills/for-return` | Bill no. to return | ✅ | `PurchaseBillController@forReturn` |
| GET | `/inventory/items/by-bill/{doc_id}` | Bill return items | ✅ | `InventoryController@itemsByBill` (§6g) |

### 10c. Bill Payment ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/purchase/payments/` | Get All Bill Payments | ✅ | `PurchasePaymentController@index` |
| POST | `/purchase/payments/` | Create/Edit Payment | ✅ | `PurchasePaymentController@store` |
| GET | `/purchase/payments/{id}` | Get Payment | ✅ | `PurchasePaymentController@show` |
| DELETE | `/purchase/payments/` | Delete Payment | ✅ | `PurchasePaymentController@destroy` |

### 10d. Purchase Return + Settlement ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/purchase/purchase-returns/` | All Purchase Returns | ✅ | `PurchaseReturnController@index` |
| POST | `/purchase/purchase-returns/` | Create/Edit Return | ✅ | `PurchaseReturnController@store` |
| GET | `/purchase/purchase-returns/{id}` | Get Return | ✅ | `PurchaseReturnController@show` |
| GET | `/purchase/purchase-returns/unpaid/` | Outstanding Returns | ✅ | `PurchaseReturnController@unpaid` |
| DELETE | `/purchase/` | Delete Purchase Return | ✅ | `PurchaseReturnController@destroy` |
| GET/POST | `/purchase/return-settlements/bills/` | Settlement Bills | ✅ | `PurchaseReturnSettlementController@billIndex/billStore` |
| GET | `/purchase/return-settlements/bills/{id}` | Get Settlement Bill | ✅ | `PurchaseReturnSettlementController@billShow` |
| GET/POST | `/purchase/return-settlements/refunds/` | Settlement Refunds | ✅ | `PurchaseReturnSettlementController@refundIndex/refundStore` |
| GET | `/purchase/return-settlements/refunds/{id}` | Get Refund | ✅ | `PurchaseReturnSettlementController@refundShow` |
| DELETE | `/purchase/return-settlements/` | Delete Settlement | ✅ | `PurchaseReturnSettlementController@destroy` |

### 10e. Serial Number ✅
| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/purchase/serial-number/wms/{bill_detail_id}` | Get Serial/Batch | ✅ | `PurchaseSerialNumberController@wmsByBillDetail` |
| POST | `/purchase/serial-number/mark-printed` | Print Barcodes | ✅ | `PurchaseSerialNumberController@markPrinted` |

---

## 11. Contact ✅ (8✅/0🔄/0⬜)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi |
|---|---|---|---|---|
| GET | `/contacts/suppliers/` | Get Vendors | ✅ | `ContactController@suppliers` |
| GET | `/contacts/{id}` | Get Contact | ✅ | `ContactController@show` |
| GET | `/contacts/` | Get All Contacts | ✅ | `ContactController@index` |
| POST | `/contacts/` | Create/Edit Contact | ✅ | `ContactController@store` |
| DELETE | `/contacts/` | Delete Contact | ✅ | `ContactController@destroy` |
| GET | `/contacts/customers/` | Get Customers | ✅ | `ContactController@customers` |
| GET | `/contacts/customers-suppliers/` | Customers & Vendors | ✅ | `ContactController@customersSuppliers` |
| GET | `/contact/category/` | Contact Category | ✅ | `ContactController@categories` |

---

## 12. Journal ⬜ (0/5) — modul Finance perlu dibangun

| M | Endpoint Jubelio | Fungsi | Status | Task |
|---|---|---|---|---|
| GET | `/journal/` | Get All Journal | ⬜ | **`JournalController@index`** |
| GET | `/journal/{id}` | Get Journal by Id | ⬜ | `@show` |
| GET | `/journal/manual-journal/` | All Manual Journal | ⬜ | `@manualIndex` |
| POST | `/journal/manual-journal/` | Create/Edit Manual Journal | ⬜ | `@manualStore` |
| GET | `/accounts/lookup/all` | Account Lookup (CoA) | ⬜ | **`AccountController@lookup`** (Chart of Accounts dulu) |

---

## 13. Cash & Bank ⬜ (0/4)

| M | Endpoint Jubelio | Fungsi | Status | Task |
|---|---|---|---|---|
| GET | `/cashbank/payments/` | Get Payments | ⬜ | **`CashbankController@payments`** |
| GET | `/cashbank/payments/id` | Get Payment by Id | ⬜ | `@paymentShow` |
| GET | `/cashbank/receives` | Get Receives | ⬜ | `@receives` |
| GET | `/cashbank/receives/id` | Get Receive by Id | ⬜ | `@receiveShow` |

---

## 14. Reports ⬜ (0/13) — modul Report perlu dibangun (PDF/print)

| M | Endpoint Jubelio | Fungsi | Status | Task |
|---|---|---|---|---|
| GET | `/reports/putaway` | Putaway Reports | ⬜ | `ReportController@putaway` |
| GET | `/reports/receive` | Receive Bill (PO) | ⬜ | `@receive` |
| GET | `/reports/adjustment` | Stock Adjustment Report | ⬜ | `@adjustment` |
| GET | `/reports/stock-opname` | Items To Opname | ⬜ | `@stockOpname` |
| GET | `/reports/purchaseorder/` | PO Details | ⬜ | `@purchaseOrder` |
| GET | `/reports/invoice` | Print Invoice | ⬜ | `@invoice` |
| GET | `/reports/consign` | Receive Bill Consignment | ⬜ | `@consign` |
| GET | `/reports/item-receive-notplace` | Received not placed | ⬜ | `@itemReceiveNotPlace` |
| GET | `/reports/wms/pick-list` | Print Picklist | ⬜ | `@pickList` |
| GET | `/reports/wms/shipping-manifest` | Proof of Delivery | ⬜ | `@shippingManifest` |
| GET | `/reports/shipping-label/` | Print Shipping Label | ⬜ | `@shippingLabel` |
| GET | `/reports/lable/print/` | Print Shipping Label (alt) | ⬜ | `@labelPrint` |
| GET | `/lazada/get-document/` | Print Lazada Invoice/Label | ⬜ | integrasi Lazada |

---

## 15. System Setting 🔄 (1✅/1🔄/6⬜)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/systemsetting/users/` | List Warehouse Staff | ✅ | `UserController@index` (Auth) |
| GET | `/taxes/` | Taxes | 🔄 | `TaxController` (thin → lengkapi CRUD+kalkulasi) |
| GET | `/systemsetting/account-mapping` | Account Mapping | ⬜ | **Task:** mapping akun (butuh CoA) |
| GET | `/systemsetting/sales-return-setting` | Get Return Setting | ⬜ | **Task:** setting |
| POST | `/systemsetting/sales-return-setting` | Create Return Setting | ⬜ | **Task:** setting |
| POST | `/systemsetting/webhook` | Create/Edit Webhook | ⬜ | **Task:** webhook registration |
| GET | `/store-locations/` | Store Locations | ⬜ | **Task:** lihat §3 `/locations/store` |
| GET | `/lazada/get-shipment-providers/{storeId}/` | Lazada Shipment Providers | ⬜ | integrasi Lazada |

---

## 16. Webhooks ⬜ (0✅/1🔄/8⬜) — modul Webhook perlu dibangun

Webhook **outbound** Jubelio = endpoint untuk **menerima** event dari sistem internal lalu push ke subscriber (atau menerima dari channel). Saat ini hanya TikTok webhook yang ada (di modul Channel).

| M | Endpoint Jubelio | Fungsi | Status | Task |
|---|---|---|---|---|
| POST | `/webhooks/product` | New Product | ⬜ | `WebhookController@product` |
| POST | `/webhooks/stock` | Update Stock | ⬜ | `@stock` |
| POST | `/webhooks/price` | Update Price | ⬜ | `@price` |
| POST | `/webhooks/salesorder` | Update Sales Order | ⬜ | `@salesOrder` |
| POST | `/webhooks/salesreturn` | New Sales Return | ⬜ | `@salesReturn` |
| POST | `/webhooks/invoice` | New Invoice | ⬜ | `@invoice` |
| POST | `/webhooks/payment` | Update Payment | ⬜ | `@payment` |
| POST | `/webhooks/purchaseorder` | New Purchase Order | ⬜ | `@purchaseOrder` |
| POST | `/webhooks/stocktransfer` | New Stock Transfer | ⬜ | `@stockTransfer` |
| (infra) | signature verification | Webhook Signature | 🔄 | pola TikTok ada → generalisasi |

---

## 17. Channels (marketplace) 🔄 (0✅/1🔄)

| M | Endpoint Jubelio | Fungsi | Status | Implementasi / Task |
|---|---|---|---|---|
| GET | `/marketplace/store/` | Get All Stores | 🔄 | `ChannelController@index` + `TikTokStoreController` → **Task:** agregator multi-channel store |

---

## 18. Backlog Modul Baru (ringkasan task besar)

| Epic | Modul Cilupbah | PIC | Endpoint baru | Est. | Prioritas |
|---|---|---|---:|---|---|
| ~~**E1. Sales Invoice + Payment + Settlement**~~ | Sales | 🟢 Rasyid | ~~22~~ ✅ | — | ✅ DONE |
| ~~**E2. Purchase Bill + Payment + Return + Settlement**~~ | Purchase | 🟢 Rasyid | ~~20~~ ✅ | — | ✅ DONE |
| ~~**E3. Contact terpadu**~~ (customers/suppliers) | Supplier (Contact) | 🟢 Rasyid | ~~8~~ ✅ | — | ✅ DONE |
| **E4. Finance: Journal + Accounts (CoA)** | Finance | 🔵 Darriel | 5 | L | 🥈 P1 |
| **E5. Cash & Bank** | Finance | 🔵 Darriel | 4 | M | 🥈 P1 |
| **E6. Tax lengkap** | Tax | 🔵 Darriel | 1+ | S | 🥈 P1 |
| **E7. Reports (PDF/print)** | Report | 🟢 Rasyid | 13 | L | 🥈 P1 |
| **E8. Webhooks outbound** | Webhook | 🔵 Darriel | 9 | M | 🥉 P2 |
| **E9. Marketplace: Shopee, Tokopedia, Lazada, Blibli** | Channel | 🔵 Darriel | 6+ | XL | 🥉 P2 |
| **E10. Inventory extended** (promotions, price-list, bundles, revaluations, split, batch) | Inventory/Product | 🟢 Rasyid (Inventory) + 🔵 Darriel (Product) | ~14 | L | 🥉 P3 |
| **E11. System Setting** (account-mapping, return-setting, webhook reg, store-locations) | Setting | 🔵 Darriel | 6 | M | 🥉 P3 |

> Est: S=≤2 hari, M=3–5 hari, L=1–2 minggu, XL=>2 minggu.
> PIC mengikuti kepemilikan modul di §0b. 🔵 Darriel: E4, E5, E6, E8, E9, E11 (+Product di E10). 🟢 Rasyid: E1, E2, E3, E7, E10 (Inventory).

---

## 18b. Delta `dist (3).yaml` vs `dist (2).yaml` (verifikasi)

Hasil diff aktual: **dist(2) = superset** (287 op) dari **dist(3)** (221 op). 214 op identik; 73 op hanya di dist(2) (WMS+Accounting); **7 op hanya di dist(3)** — mayoritas varian trailing-slash. Yang perlu ditambahkan sebagai baris eksplisit (Sales-side listing):

| M | Endpoint (dist3) | Fungsi | Status | Catatan |
|---|---|---|---|---|
| GET | `/sales/orders/` | Get All Sales Orders | 🔄 | `SalesOrderController@index` (`/sales/` sudah ada, tambah alias `/sales/orders/`) |
| GET | `/sales/orders/ready-to-pick/` | Ready to Process orders | 🔄 | overlap `@ordersByStage` (Outbound) — samakan |
| GET | `/sales/picklists/confirm-pick/` | Get All Picklist | 🔄 | `PicklistController@index` |
| POST | `/sales/picklists/` | Create/Edit Picklist | 🔄 | `PicklistController@store` (alias sales-side) |
| GET | `/inventory/items/to-stock` | (varian tanpa slash) | ✅ | = §6a, alias saja |
| POST | `/inventory/items/prices` | (varian tanpa slash) | 🔄 | = §4, alias saja |
| GET | `/inventory/catalog/` | Get Item Catalog | 🔄 | = §6g `catalog/{group_id}`, alias |

**Kesimpulan:** menyelesaikan baseline dist(2) otomatis menutup dist(3) (cukup tambah 4 alias Sales + 3 alias varian).

---

## 18c. Scope DI LUAR Jubelio — "Integrated Omnichannel WMS" (sudah ada di kode, TIDAK di spec)

Kedua YAML **tidak** mendeskripsikan lapisan integrasi channel. Surface ini **keunggulan Cilupbah vs Jubelio** dan harus tetap dijaga/diselesaikan walau tak ada di spec:

| Area | Endpoint Cilupbah (contoh) | Status | Catatan |
|---|---|---|---|
| **TikTok OAuth + Sync** | `v1/tiktok/auth`, `callback`, `webhook`, `sync/pull`, `sync/accept`, `sync/products/push`, `auto-sync/*`, `cancel-reasons`, `cancel-product` | ✅ | Engine sync 2-arah — inti omnichannel |
| **Channel Monitor** | `channel-monitor`, `/summary`, `/{shop_id}`, `/{shop_id}/products` | ✅ | Health/listing per toko — tidak ada di Jubelio |
| **Product Merge** | `products/merge/{catalog,auto,apply,bulk,hide,unmerge}` | ✅ | Konsolidasi katalog multi-channel |
| **Raise Product** | `raise-products/*` | ✅ | Alur naikkan produk ke channel |
| **Channel Drafts** | `products/channel-drafts/*`, `bulk-upload` | ✅ | Draft listing per channel |
| **Upload/Download History** | `upload-histories/*`, `download-histories` | ✅ | Audit sync |
| **Channel Warehouse Mapping** | `channel-warehouses/*` | ✅ | Mapping gudang↔channel |
| **RBAC penuh** | `roles`, `permissions`, user `histories`, `force-logout` | ✅ | Lebih kaya dari `systemsetting/users` Jubelio |
| **Notification / AI / Warranty** | modul stub/partial | 🔄/⬜ | Fitur tambahan di luar Jubelio |

**Gap omnichannel yang masih kurang (di luar Jubelio, untuk jadi WMS omnichannel penuh):**
- Adapter channel: **Shopee, Tokopedia, Lazada, Blibli** (pola sama seperti TikTok) — saat ini 0%.
- **Unified order ingestion** lintas channel → satu pipeline WMS (sebagian via TikTok pull, perlu generalisasi).
- **Real-time stock sync** balik ke semua channel saat stok berubah (webhook outbound + push).
- **Channel SLA / cancellation / return** handling per-marketplace.

> Artinya: untuk "menggantikan Jubelio **dan** lebih unggul", target = **(±142 ⬜ Jubelio) + (lapisan omnichannel 4 marketplace baru)**. Jubelio parity ≈ fondasi; omnichannel ≈ diferensiasi.

---

## 18d. Matriks Omnichannel (Beyond Jubelio) — Task Breakdown per Channel

Lapisan ini **tidak ada di YAML Jubelio** namun wajib untuk "WMS omnichannel terintegrasi". Polanya direplikasi dari **TikTok** yang sudah jalan. Referensi API marketplace bersifat **indikatif** — wajib diverifikasi ke dokumentasi resmi tiap channel sebelum implementasi.

### Matriks ringkas (status: ✅ done · 🔄 partial · ⬜ todo)

| Fitur \ Channel | TikTok | Shopee | Tokopedia | Lazada | Blibli |
|---|:--:|:--:|:--:|:--:|:--:|
| OAuth / Auth toko | ✅ | ⬜ | ⬜ | ⬜ | ⬜ |
| Manajemen toko (list/refresh token) | ✅ | ⬜ | ⬜ | ⬜ | ⬜ |
| Tarik order (pull) | ✅ | ⬜ | ⬜ | ⬜ | ⬜ |
| Terima/tolak order | ✅ | ⬜ | ⬜ | ⬜ | ⬜ |
| Push produk (create listing) | ✅ | ⬜ | ⬜ | ⬜ | ⬜ |
| Sync produk (update) | ✅ | ⬜ | ⬜ | ⬜ | ⬜ |
| Sync stok (push balik) | 🔄 | ⬜ | ⬜ | ⬜ | ⬜ |
| Sync harga | 🔄 | ⬜ | ⬜ | ⬜ | ⬜ |
| Webhook masuk (order/produk/stok) | ✅ | ⬜ | ⬜ | ⬜ | ⬜ |
| Cancel order | 🔄 | ⬜ | ⬜ | ⬜ | ⬜ |
| Retur / after-sales | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| Logistik / kurir channel | 🔄 | ⬜ | ⬜ | ⬜ | ⬜ |
| Cetak label/dokumen | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |

### Template task per channel (contoh: Shopee — semua ⬜)

| Fitur | Endpoint Cilupbah (rencana) | Controller@method | Referensi API channel (indikatif) | Status |
|---|---|---|---|:--:|
| OAuth | `GET v1/shopee/auth` · `GET v1/shopee/callback` | `ShopeeAuthController@redirect/callback` | `/api/v2/shop/auth_partner`, `/auth/token/get` | ⬜ |
| List toko | `GET v1/shopee/stores` | `ShopeeStoreController@index` | `/shop/get_shop_info` | ⬜ |
| Refresh token | `POST v1/shopee/stores/{id}/refresh-token` | `ShopeeStoreController@refreshToken` | `/auth/access_token/get` | ⬜ |
| Tarik order | `POST v1/shopee/sync/pull` | `ShopeeSyncController@pullOrders` | `/order/get_order_list`, `/order/get_order_detail` | ⬜ |
| Push produk | `POST v1/shopee/sync/products/push` | `ShopeeSyncController@pushProduct` | `/product/add_item` | ⬜ |
| Update produk | `POST v1/shopee/sync/products/sync` | `ShopeeSyncController@syncProduct` | `/product/update_item` | ⬜ |
| Sync stok | `PUT v1/shopee/products/{id}/stock` | `ShopeeSyncController@updateStock` | `/product/update_stock` | ⬜ |
| Sync harga | `PUT v1/shopee/products/{id}/price` | `ShopeeSyncController@updatePrice` | `/product/update_price` | ⬜ |
| Webhook | `POST v1/shopee/webhook` | `ShopeeWebhookController@handle` | Push Mechanism (callback) | ⬜ |
| Cancel | `POST v1/shopee/sync/cancel` | `ShopeeSyncController@cancelOrder` | `/order/cancel_order` | ⬜ |
| Logistik | `GET v1/shopee/logistics` | `ShopeeSyncController@logistics` | `/logistics/get_channel_list` | ⬜ |

> **Tokopedia, Lazada, Blibli** mengikuti template kolom yang sama (OAuth → toko → pull order → push/sync produk → stok/harga → webhook → cancel/retur → logistik/label). Endpoint Jubelio yang menyentuh channel ini (`/shopee/logistics`, `/tokopedia/showcases`, `/lazada/get-document`, `/lazada/get-shipment-providers`, `/blibli/pickupPoints`) sudah tercatat di Lampiran A dan menjadi bagian dari epik per-channel ini.

**Total surface omnichannel baru:** ~11 fitur × 4 channel = **±44 task** (di luar 142 ⬜ Jubelio). Inilah diferensiasi utama vs Jubelio.

---

## 19. Definition of Done (per endpoint)
Sebuah endpoint dianggap **✅ DONE** bila:
1. Route terdaftar + controller method ada.
2. Request validation (FormRequest) sesuai schema Jubelio.
3. Response **menyamai struktur Jubelio** (untuk drop-in replacement).
4. Service layer + migration (bila stateful).
5. Minimal 1 feature test (happy path + 1 error path).
6. Terdokumentasi di OpenAPI Cilupbah.

---

## 20. Penutup — Strategi Drop-in Replacement

Agar Cilupbah benar-benar **menggantikan Jubelio** tanpa mengubah client:
1. **Compatibility route layer** — daftarkan path persis Jubelio (`/inventory/...`, `/wms/...`, `/sales/...`) sebagai alias yang memanggil controller Cilupbah. Bisa di-generate dari `dist (2).yaml`.
2. **Response shape parity** — buat API Resource/Transformer yang meniru field Jubelio (snake_case, nama field identik).
3. **Contract testing** — validasi response tiap endpoint terhadap schema `dist (2).yaml` (mis. `spectator`/`openapi-httpfoundation-testing`).
4. **Urutan eksekusi:** E3 (Contact) → E1 (Sales Invoice/Payment) → E2 (Purchase Bill/Payment) → E4/E5 (Finance) → E7 (Reports) → E8 (Webhooks) → E9 (Marketplace) → E10/E11 (polish).

**Total endpoint yang masih harus dibuat: ± 38 operasi (⬜) + 16 penyempurnaan (🔄).** *(Sales, Purchase & Contact selesai 2026-06-10)*

---

## Lampiran A — Daftar Lengkap 287 Endpoint Jubelio (100% tercakup)

> Dibangkitkan otomatis dari `dist (2).yaml` (superset; mencakup `dist (3).yaml`). Validasi: **287 operasi**, semua punya fungsi & status.

> Rekap: ✅ 193 · 🔄 29 · ⬜ 65


### Authentication — ✅1 🔄0 ⬜0 (total 1)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 1 | POST | `/login` | Login & dapatkan token akses (Sanctum) | ✅ |

### Region — ✅4 🔄0 ⬜0 (total 4)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 2 | GET | `/region/cities/?province_id={province_id}` | Ambil daftar kota per provinsi | ✅ |
| 3 | GET | `/region/districts/?city_id={city_id}` | Ambil daftar kecamatan per kota | ✅ |
| 4 | GET | `/region/provinces` | Ambil daftar provinsi | ✅ |
| 5 | GET | `/region/subdistricts/?district_id={district_id}` | Ambil daftar kelurahan per kecamatan | ✅ |

### Location & The Rack Plan — ✅6 🔄1 ⬜1 (total 8)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 6 | GET | `/locations/` | Ambil semua lokasi | ✅ |
| 7 | POST | `/locations/` | Buat/ubah lokasi & denah rak | ✅ |
| 8 | DELETE | `/locations/` | Hapus lokasi | ✅ |
| 9 | GET | `/locations/bin/{location_id}` | Ambil bin per lokasi | ✅ |
| 10 | GET | `/locations/pos` | Ambil lokasi yang punya outlet POS | ⬜ |
| 11 | GET | `/locations/store/` | Ambil pemetaan lokasi ke toko | 🔄 |
| 12 | GET | `/locations/{id}` | Ambil detail lokasi | ✅ |
| 13 | GET | `/wms/default-bin/{location_id}` | Ambil bin default per lokasi | ✅ |

### Product — ✅18 🔄8 ⬜8 (total 34)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 14 | POST | `/inventory/catalog/` | Buat/ubah produk | ✅ |
| 15 | GET | `/inventory/categories/category-map/{id}` | Ambil pemetaan kategori ke marketplace | 🔄 |
| 16 | GET | `/inventory/categories/item-categories/` | Ambil semua kategori | ✅ |
| 17 | GET | `/inventory/categories/item-categories/information/{id}/` | Ambil informasi kategori | ✅ |
| 18 | GET | `/inventory/categories/{channel_id}/store-categories/{store_id}` | Ambil kategori toko per channel | 🔄 |
| 19 | GET | `/inventory/categories/{id}/attributes-value/` | Ambil nilai atribut kategori | ✅ |
| 20 | GET | `/inventory/categories/{id}/attributes/` | Ambil atribut kategori | ✅ |
| 21 | GET | `/inventory/categories/{id}/variations/` | Ambil varian kategori | ✅ |
| 22 | GET | `/inventory/internal-price-list/` | Ambil semua harga produk (price list internal) | ⬜ |
| 23 | GET | `/inventory/item-bundles/` | Ambil semua bundle produk | 🔄 |
| 24 | GET | `/inventory/items/` | Ambil semua grup produk | ✅ |
| 25 | DELETE | `/inventory/items/` | Hapus produk | ✅ |
| 26 | POST | `/inventory/items/` | Buat/ubah bundle produk | 🔄 |
| 27 | POST | `/inventory/items/all-stocks/` | Ambil stok produk per banyak ID | 🔄 |
| 28 | POST | `/inventory/items/archive/` | Arsipkan produk | ✅ |
| 29 | GET | `/inventory/items/archived/` | Ambil semua produk terarsip | ✅ |
| 30 | GET | `/inventory/items/by-sku/{sku}` | Ambil produk per SKU | 🔄 |
| 31 | GET | `/inventory/items/channel-category-attributes/` | Ambil semua atribut kategori channel | 🔄 |
| 32 | GET | `/inventory/items/channel-category-tree/` | Ambil pohon kategori channel | ✅ |
| 33 | GET | `/inventory/items/group/{id}` | Ambil grup produk | ✅ |
| 34 | DELETE | `/inventory/items/item-variant/` | Hapus varian item | ⬜ |
| 35 | GET | `/inventory/items/masters` | Ambil semua produk master | ✅ |
| 36 | POST | `/inventory/items/prices/` | Ambil harga produk per banyak ID | 🔄 |
| 37 | POST | `/inventory/items/restore/` | Pulihkan produk dari arsip | ✅ |
| 38 | GET | `/inventory/items/reviews/` | Ambil semua produk dalam status review | ✅ |
| 39 | GET | `/inventory/items/{id}` | Ambil detail produk | ✅ |
| 40 | POST | `/inventory/price-list/` | Ubah harga produk | ⬜ |
| 41 | GET | `/inventory/promotions/` | Ambil semua promosi | ⬜ |
| 42 | POST | `/inventory/promotions/` | Buat promosi | ⬜ |
| 43 | DELETE | `/inventory/promotions/` | Hapus promosi | ⬜ |
| 44 | GET | `/inventory/promotions/{id}` | Ambil detail promosi | ⬜ |
| 45 | GET | `/inventory/search-brands/` | Ambil semua merek (brand) | ✅ |
| 46 | POST | `/inventory/upload-image` | Unggah gambar produk | ✅ |
| 47 | GET | `/variations` | Ambil semua variasi (variant) produk | ⬜ |

### Product Listing — ✅3 🔄2 ⬜3 (total 8)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 48 | GET | `/blibli/pickupPoints` | Ambil titik pickup Blibli | ⬜ |
| 49 | GET | `/inventory/catalog/for-listing/{id}` | Ambil data produk untuk listing channel | ✅ |
| 50 | POST | `/inventory/catalog/listing` | Buat/ubah listing produk | 🔄 |
| 51 | POST | `/inventory/catalog/upload` | Unggah listing produk ke channel | ✅ |
| 52 | GET | `/inventory/categories/channel-categories/{parent_id}` | Ambil kategori channel | ✅ |
| 53 | GET | `/inventory/items/errors/` | Ambil daftar listing yang gagal upload | 🔄 |
| 54 | GET | `/shopee/logistics` | Ambil opsi logistik Shopee | ⬜ |
| 55 | GET | `/tokopedia/showcases` | Ambil etalase (showcase) Tokopedia | ⬜ |

### Inventory — ✅57 🔄0 ⬜0 (total 57)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 56 | GET | `/inventory/` | Ambil stok semua produk | ✅ |
| 57 | GET | `/inventory/activity/` | Ambil riwayat pergerakan stok produk | ✅ |
| 58 | GET | `/inventory/adjustments/` | Ambil semua penyesuaian stok | ✅ |
| 59 | POST | `/inventory/adjustments/` | Buat/ubah penyesuaian stok | ✅ |
| 60 | DELETE | `/inventory/adjustments/` | Hapus dokumen penyesuaian stok | ✅ |
| 61 | GET | `/inventory/adjustments/{id}` | Ambil detail penyesuaian stok | ✅ |
| 62 | POST | `/inventory/catalog/set-master` | Set produk dari 'In Review' menjadi 'Master' | ✅ |
| 63 | GET | `/inventory/catalog/{group_id}` | Ambil katalog item per grup | ✅ |
| 64 | GET | `/inventory/items/by-bill/{doc_id}` | Ambil detail item retur pembelian per bill | ✅ |
| 65 | GET | `/inventory/items/by-invoice/{invoice_id}` | Ambil daftar item per nomor invoice | ✅ |
| 66 | GET | `/inventory/items/by-transfer/{item_transfer_id}` | Ambil daftar produk yang akan diterima per nomor transfer | ✅ |
| 67 | POST | `/inventory/items/group/merge-catalog` | Gabungkan item serupa dalam katalog | ✅ |
| 68 | GET | `/inventory/items/item-on-stock` | Ambil daftar item untuk ditransfer | ✅ |
| 69 | GET | `/inventory/items/received` | Ambil daftar item yang sudah diterima | ✅ |
| 70 | POST | `/inventory/items/received/author` | Tugaskan staf untuk putaway | ✅ |
| 71 | POST | `/inventory/items/received/auto-putaway` | Set item untuk auto-putaway | ✅ |
| 72 | POST | `/inventory/items/received/finish-putaway` | Tandai proses putaway selesai | ✅ |
| 73 | GET | `/inventory/items/received/item/{putaway_id}` | Ambil daftar item putaway | ✅ |
| 74 | POST | `/inventory/items/received/putaway` | Letakkan item ke rak (putaway) | ✅ |
| 75 | POST | `/inventory/items/split-item` | Pisah item (split) jadi unit lebih kecil | ✅ |
| 76 | POST | `/inventory/items/to-adjust/` | Ambil cost & stok item untuk penyesuaian | ✅ |
| 77 | GET | `/inventory/items/to-buy` | Ambil daftar produk yang perlu dibeli (untuk PO) | ✅ |
| 78 | GET | `/inventory/items/to-sales-return` | Ambil daftar item retur penjualan | ✅ |
| 79 | GET | `/inventory/items/to-sell/{location_id}` | Ambil item yang bisa dijual per lokasi | ✅ |
| 80 | GET | `/inventory/items/to-stock/` | Ambil semua item yang stoknya perlu disesuaikan | ✅ |
| 81 | GET | `/inventory/items/to-stock/{location_id}` | Ambil item untuk distok per lokasi | ✅ |
| 82 | GET | `/inventory/items/{id}/batch-number` | Ambil nomor batch item | ✅ |
| 83 | GET | `/inventory/need-restock/` | Ambil produk yang perlu restock | ✅ |
| 84 | GET | `/inventory/out-of-stock-in-order/` | Ambil produk habis stok yang ada di order | ✅ |
| 85 | GET | `/inventory/putaway/all` | Ambil ID putaway | ✅ |
| 86 | GET | `/inventory/putaway/completed` | Daftar putaway yang sudah selesai | ✅ |
| 87 | GET | `/inventory/putaway/not-start` | Daftar putaway yang belum dimulai | ✅ |
| 88 | GET | `/inventory/putaway/processed` | Daftar putaway yang sedang diproses | ✅ |
| 89 | POST | `/inventory/reserved/` | Buat reservasi stok item | ✅ |
| 90 | GET | `/inventory/reserved/` | Ambil daftar stok yang direservasi | ✅ |
| 91 | GET | `/inventory/reserved/{id}` | Ambil detail reservasi stok | ✅ |
| 92 | POST | `/inventory/revaluations/` | Buat/ubah penyesuaian nilai (revaluasi) stok | ✅ |
| 93 | POST | `/inventory/stock-opname` | Buat daftar item untuk diopname | ✅ |
| 94 | GET | `/inventory/stock-opname` | Ambil daftar stock opname semua status | ✅ |
| 95 | GET | `/inventory/stock-opname/bins` | Ambil semua bin per lokasi | ✅ |
| 96 | GET | `/inventory/stock-opname/columns` | Ambil lokasi rak per kolom | ✅ |
| 97 | POST | `/inventory/stock-opname/finalize` | Selesaikan opname & push stok final | ✅ |
| 98 | GET | `/inventory/stock-opname/floors` | Ambil lokasi rak per lantai | ✅ |
| 99 | GET | `/inventory/stock-opname/items` | Ambil semua item untuk diopname | ✅ |
| 100 | GET | `/inventory/stock-opname/items/filtered` | Ambil item opname terfilter per lokasi rak | ✅ |
| 101 | GET | `/inventory/stock-opname/rows` | Ambil lokasi rak per baris | ✅ |
| 102 | GET | `/inventory/stock-opname/{opname_header_id}` | Ambil stok real-time saat opname berjalan | ✅ |
| 103 | GET | `/inventory/transfer/delivery` | Cetak laporan surat jalan transfer | ✅ |
| 104 | POST | `/inventory/transfer/mark-printed` | Tandai transfer item sudah dicetak | ✅ |
| 105 | POST | `/inventory/transfers/` | Buat transfer stok (masuk/keluar) | ✅ |
| 106 | DELETE | `/inventory/transfers/` | Hapus transfer stok | ✅ |
| 107 | GET | `/inventory/transfers/all-transit` | Ambil semua nomor transaksi transfer transit | ✅ |
| 108 | GET | `/inventory/transfers/in` | Ambil transfer stok masuk | ✅ |
| 109 | GET | `/inventory/transfers/out` | Ambil transfer stok keluar | ✅ |
| 110 | GET | `/inventory/transfers/out-finished` | Ambil transfer yang sudah selesai/diterima | ✅ |
| 111 | GET | `/inventory/transfers/transit` | Ambil transfer stok dalam perjalanan (transit) | ✅ |
| 112 | GET | `/inventory/transfers/{id}` | Ambil detail transfer stok | ✅ |

### WMS (Warehouse Management System) — ✅34 🔄0 ⬜0 (total 34)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 113 | GET | `/wms/couriers` | Ambil daftar kurir WMS | ✅ |
| 114 | GET | `/wms/employee/{NIKorEmail}` | Ambil info staf gudang per NIK/email | ✅ |
| 115 | POST | `/wms/order/getOrderByNo/` | Ambil sales order yang itemnya akan dipick | ✅ |
| 116 | GET | `/wms/sales/order/ready-to-ship` | Ambil order yang perlu dikirim ke kurir | ✅ |
| 117 | POST | `/wms/sales/orders/change-location/` | Ubah lokasi gudang untuk order | ✅ |
| 118 | GET | `/wms/sales/orders/empty-stock/` | Ambil order yang stoknya kosong | ✅ |
| 119 | GET | `/wms/sales/orders/failed-pick` | Ambil order yang batal dipick | ✅ |
| 120 | GET | `/wms/sales/orders/finish-pick/` | Ambil order yang selesai dipick | ✅ |
| 121 | GET | `/wms/sales/orders/ready-to-pick/` | Ambil order siap dipick | ✅ |
| 122 | GET | `/wms/sales/orders/ready-to-process/` | Ambil order siap diproses | ✅ |
| 123 | GET | `/wms/sales/orders/request-cancel/` | Ambil order yang diminta batal oleh customer | ✅ |
| 124 | POST | `/wms/sales/packlist` | Buat packlist | ✅ |
| 125 | POST | `/wms/sales/packlist/mark-as-complete/` | Tandai order siap kirim (selesai packing) | ✅ |
| 126 | GET | `/wms/sales/packlist/scan-order` | Ambil daftar item untuk dipacking | ✅ |
| 127 | POST | `/wms/sales/packlist/update-qty-packed` | Perbarui qty item yang sudah dipacking | ✅ |
| 128 | POST | `/wms/sales/packlist/verify-barcode/` | Verifikasi item/SKU/barcode/serial/batch | ✅ |
| 129 | GET | `/wms/sales/packlists/finish-pack/` | Ambil order yang selesai packing | ✅ |
| 130 | GET | `/wms/sales/packlists/process/` | Ambil order yang sedang proses packing | ✅ |
| 131 | POST | `/wms/sales/picklists/` | Buat picklist / set picklist selesai | ✅ |
| 132 | POST | `/wms/sales/picklists/change-picker/` | Ganti staf picker | ✅ |
| 133 | GET | `/wms/sales/picklists/confirm-pick/` | Ambil order yang sedang proses picking | ✅ |
| 134 | POST | `/wms/sales/ready-to-pick` | Pindahkan order ke status 'ready to pick' | ✅ |
| 135 | POST | `/wms/sales/ready-to-process` | Pindahkan order ke status 'ready to process' | ✅ |
| 136 | GET | `/wms/sales/shipments/all` | Ambil semua jadwal shipment kurir reguler | ✅ |
| 137 | GET | `/wms/sales/shipments/completed/{shipment_type}/{courierIds}` | Ambil shipment yang sudah dalam pengiriman | ✅ |
| 138 | GET | `/wms/sales/shipments/instant/all` | Ambil semua jadwal shipment kurir instant | ✅ |
| 139 | POST | `/wms/sales/shipments/orders/` | Ambil AWB untuk order | ✅ |
| 140 | GET | `/wms/sales/shipments/{courier_new_id}` | Ambil shipment per kurir tertentu | ✅ |
| 141 | GET | `/wms/sales/shipped/` | Ambil order yang sudah dikirim kurir | ✅ |
| 142 | POST | `/wms/scan-shipment` | Ambil jadwal shipment via scan nomor shipment | ✅ |
| 143 | POST | `/wms/shipment-detail/` | Tambah order ke jadwal shipment | ✅ |
| 144 | POST | `/wms/shipments/` | Buat jadwal shipment kurir reguler | ✅ |
| 145 | POST | `/wms/shipments/get-order/` | Perbarui qty item yang sudah diserahkan ke kurir | ✅ |
| 146 | POST | `/wms/shipments/instant-courier/` | Buat jadwal shipment kurir instant | ✅ |

### Couriers — ✅3 🔄0 ⬜0 (total 3)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 147 | GET | `/couriers` | Ambil semua kurir | ✅ |
| 148 | GET | `/couriers/tenant/{id}` | Ambil kurir milik tenant tertentu | ✅ |
| 149 | GET | `/couriers/{id}` | Ambil detail satu kurir | ✅ |

### Sales — ✅60 🔄0 ⬜0 (total 60)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 150 | POST | `/inventory/items/complete-return/` | Set item jadi tidak diretur (batal retur) | ✅ |
| 151 | POST | `/inventory/items/reject-return/` | Tolak permintaan retur | ✅ |
| 152 | POST | `/inventory/items/to-return/` | Terima retur penjualan | ✅ |
| 153 | GET | `/sales/` | Ambil semua data penjualan | ✅ |
| 154 | DELETE | `/sales/` | Hapus retur/invoice penjualan | ✅ |
| 155 | GET | `/sales/invoices/` | Ambil semua invoice penjualan | ✅ |
| 156 | POST | `/sales/invoices/` | Buat/ubah invoice penjualan | ✅ |
| 157 | GET | `/sales/invoices/for-return-wms/{contact_id}` | Ambil ID invoice untuk retur penjualan | ✅ |
| 158 | GET | `/sales/invoices/overdue/` | Ambil invoice yang jatuh tempo | ✅ |
| 159 | GET | `/sales/invoices/summary/` | Ambil ringkasan invoice per toko | ✅ |
| 160 | GET | `/sales/invoices/unpaid/` | Ambil invoice yang belum lunas | ✅ |
| 161 | GET | `/sales/invoices/{id}` | Ambil detail invoice | ✅ |
| 162 | POST | `/sales/orders/` | Buat/ubah sales order | ✅ |
| 163 | DELETE | `/sales/orders/` | Hapus sales order | ✅ |
| 164 | GET | `/sales/orders/cancel/` | Ambil sales order yang dibatalkan | ✅ |
| 165 | GET | `/sales/orders/completed/` | Ambil order selesai dari semua channel | ✅ |
| 166 | POST | `/sales/orders/delete-canceled` | Hapus item order yang dibatalkan | ✅ |
| 167 | GET | `/sales/orders/failed/` | Ambil sales order yang gagal | ✅ |
| 168 | POST | `/sales/orders/mark-as-complete` | Tandai sales order selesai | ✅ |
| 169 | GET | `/sales/orders/returned-list/` | Ambil sales order yang diretur | ✅ |
| 170 | POST | `/sales/orders/save-airwaybill/` | Perbarui AWB sales order | ✅ |
| 171 | POST | `/sales/orders/save-received-date` | Perbarui tanggal terima sales order | ✅ |
| 172 | POST | `/sales/orders/set-as-paid` | Set sales order menjadi lunas | ✅ |
| 173 | GET | `/sales/orders/{id}` | Ambil detail sales order | ✅ |
| 174 | GET | `/sales/packlists/` | Ambil semua packlist | ✅ |
| 175 | POST | `/sales/packlists/create-invoice` | Konversi sales order menjadi invoice | ✅ |
| 176 | POST | `/sales/packlists/create-invoice-payment` | Konversi sales order jadi invoice + pembayaran | ✅ |
| 177 | GET | `/sales/packlists/shipped/` | Ambil sales order yang sudah dikirim | ✅ |
| 178 | GET | `/sales/packlists/{id}` | Ambil detail packlist | ✅ |
| 179 | GET | `/sales/payments/` | Ambil semua pembayaran invoice | ✅ |
| 180 | POST | `/sales/payments/` | Buat/ubah pembayaran invoice | ✅ |
| 181 | DELETE | `/sales/payments/` | Hapus pembayaran invoice | ✅ |
| 182 | GET | `/sales/payments/{id}` | Ambil detail pembayaran invoice | ✅ |
| 183 | POST | `/sales/picklists/items-to-pick` | Ambil daftar item untuk dipick | ✅ |
| 184 | POST | `/sales/picklists/items-to-pick/` | Ambil daftar item untuk dipick (varian) | ✅ |
| 185 | DELETE | `/sales/picklists/to-ship/` | Hapus picklist | ✅ |
| 186 | GET | `/sales/picklists/{picklist_id}` | Ambil item dalam picklist | ✅ |
| 187 | POST | `/sales/request-awb-order/` | Minta AWB untuk order | ✅ |
| 188 | GET | `/sales/return-settlements/` | Ambil semua settlement retur penjualan | ✅ |
| 189 | DELETE | `/sales/return-settlements/` | Hapus settlement retur penjualan | ✅ |
| 190 | GET | `/sales/return-settlements/invoices/` | Ambil semua invoice settlement retur | ✅ |
| 191 | POST | `/sales/return-settlements/invoices/` | Buat/ubah invoice settlement retur | ✅ |
| 192 | GET | `/sales/return-settlements/invoices/{id}` | Ambil detail invoice settlement retur | ✅ |
| 193 | GET | `/sales/return-settlements/refunds/` | Ambil semua refund settlement retur | ✅ |
| 194 | POST | `/sales/return-settlements/refunds/` | Buat/ubah refund settlement retur | ✅ |
| 195 | GET | `/sales/return-settlements/refunds/{id}` | Ambil detail refund settlement retur | ✅ |
| 196 | GET | `/sales/returns/items/` | Ambil semua item retur | ✅ |
| 197 | GET | `/sales/returns/items/rejected/` | Ambil order retur yang ditolak | ✅ |
| 198 | GET | `/sales/returns/items/resolved/` | Ambil order retur yang disetujui/selesai | ✅ |
| 199 | GET | `/sales/returns/items/unprocessed/wms` | Ambil retur penjualan yang belum diproses | ✅ |
| 200 | GET | `/sales/sales-returns/` | Ambil semua retur penjualan | ✅ |
| 201 | POST | `/sales/sales-returns/` | Terima retur penjualan | ✅ |
| 202 | GET | `/sales/sales-returns/unpaid/` | Ambil retur penjualan yang belum lunas | ✅ |
| 203 | GET | `/sales/sales-returns/{id}` | Ambil detail retur penjualan | ✅ |
| 204 | GET | `/sales/settlements/` | Ambil semua settlement penjualan | ✅ |
| 205 | GET | `/sales/settlements/{id}` | Ambil detail settlement penjualan | ✅ |
| 206 | POST | `/sales/shipments/` | Set item sudah diterima kurir | ✅ |
| 207 | POST | `/sales/shipments/orders/` | Buat order pengiriman (shipment) | ✅ |
| 208 | GET | `/sales/shipments/{shipment_header_id}` | Ambil item siap kirim per jadwal shipment | ✅ |
| 209 | GET | `/sales/unfullfilled/` | Ambil packlist yang belum terpenuhi | ✅ |

### Purchasing — ✅30 🔄0 ⬜0 (total 30)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 210 | DELETE | `/purchase/` | Hapus retur pembelian | ✅ |
| 211 | GET | `/purchase/bills/` | Ambil semua bill | ✅ |
| 212 | POST | `/purchase/bills/` | Buat/ubah bill | ✅ |
| 213 | DELETE | `/purchase/bills/` | Hapus bill (tagihan pembelian) | ✅ |
| 214 | GET | `/purchase/bills/for-return` | Ambil nomor bill untuk diretur | ✅ |
| 215 | GET | `/purchase/bills/overdue/` | Ambil bill yang jatuh tempo | ✅ |
| 216 | GET | `/purchase/bills/unpaid/` | Ambil bill yang belum lunas | ✅ |
| 217 | GET | `/purchase/bills/{id}` | Ambil detail bill | ✅ |
| 218 | GET | `/purchase/orders/` | Ambil semua purchase order | ✅ |
| 219 | POST | `/purchase/orders/` | Buat/ubah purchase order | ✅ |
| 220 | DELETE | `/purchase/orders/` | Hapus purchase order | ✅ |
| 221 | GET | `/purchase/orders/progress` | Ambil progres penerimaan semua PO | ✅ |
| 222 | GET | `/purchase/orders/{id}` | Ambil detail purchase order | ✅ |
| 223 | GET | `/purchase/payments/` | Ambil semua pembayaran bill | ✅ |
| 224 | POST | `/purchase/payments/` | Buat/ubah pembayaran bill | ✅ |
| 225 | DELETE | `/purchase/payments/` | Hapus pembayaran bill | ✅ |
| 226 | GET | `/purchase/payments/{id}` | Ambil detail pembayaran bill | ✅ |
| 227 | GET | `/purchase/purchase-returns/` | Ambil semua retur pembelian | ✅ |
| 228 | POST | `/purchase/purchase-returns/` | Buat/ubah retur pembelian | ✅ |
| 229 | GET | `/purchase/purchase-returns/unpaid/` | Ambil retur pembelian yang belum lunas | ✅ |
| 230 | GET | `/purchase/purchase-returns/{id}` | Ambil detail retur pembelian | ✅ |
| 231 | DELETE | `/purchase/return-settlements/` | Hapus settlement retur pembelian | ✅ |
| 232 | GET | `/purchase/return-settlements/bills/` | Ambil semua settlement bill retur | ✅ |
| 233 | POST | `/purchase/return-settlements/bills/` | Buat/ubah settlement bill retur | ✅ |
| 234 | GET | `/purchase/return-settlements/bills/{id}` | Ambil detail settlement bill retur | ✅ |
| 235 | GET | `/purchase/return-settlements/refunds/` | Ambil semua refund settlement retur | ✅ |
| 236 | POST | `/purchase/return-settlements/refunds/` | Buat/ubah refund settlement retur | ✅ |
| 237 | GET | `/purchase/return-settlements/refunds/{id}` | Ambil detail refund settlement retur | ✅ |
| 238 | POST | `/purchase/serial-number/mark-printed` | Cetak barcode produk untuk putaway | ✅ |
| 239 | GET | `/purchase/serial-number/wms/{bill_detail_id}` | Ambil nomor seri/batch item per bill detail | ✅ |

### Contact — ✅8 🔄0 ⬜0 (total 8)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 240 | GET | `/contact/category/` | Ambil daftar kategori kontak | ✅ |
| 241 | GET | `/contacts/` | Ambil semua kontak | ✅ |
| 242 | POST | `/contacts/` | Buat/ubah kontak | ✅ |
| 243 | DELETE | `/contacts/` | Hapus kontak | ✅ |
| 244 | GET | `/contacts/customers-suppliers/` | Ambil kontak yang sekaligus customer & supplier | ✅ |
| 245 | GET | `/contacts/customers/` | Ambil daftar customer | ✅ |
| 246 | GET | `/contacts/suppliers/` | Ambil daftar supplier/vendor | ✅ |
| 247 | GET | `/contacts/{id}` | Ambil detail satu kontak | ✅ |

### Journal — ✅0 🔄0 ⬜5 (total 5)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 248 | GET | `/accounts/lookup/all` | Ambil daftar akun (Chart of Accounts) | ⬜ |
| 249 | GET | `/journal/` | Ambil semua jurnal | ⬜ |
| 250 | GET | `/journal/manual-journal/` | Ambil semua jurnal manual | ⬜ |
| 251 | POST | `/journal/manual-journal/` | Buat/ubah jurnal manual | ⬜ |
| 252 | GET | `/journal/{id}` | Ambil detail jurnal per ID | ⬜ |

### Cash & Bank — ✅0 🔄0 ⬜4 (total 4)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 253 | GET | `/cashbank/payments/` | Ambil daftar pembayaran kas/bank (uang keluar) | ⬜ |
| 254 | GET | `/cashbank/payments/id` | Ambil detail satu pembayaran kas/bank | ⬜ |
| 255 | GET | `/cashbank/receives` | Ambil daftar penerimaan kas/bank (uang masuk) | ⬜ |
| 256 | GET | `/cashbank/receives/id` | Ambil detail satu penerimaan kas/bank | ⬜ |

### Reports — ✅0 🔄0 ⬜13 (total 13)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 257 | GET | `/lazada/get-document/` | Cetak invoice/label Lazada | ⬜ |
| 258 | GET | `/reports/adjustment` | Cetak laporan penyesuaian stok | ⬜ |
| 259 | GET | `/reports/consign` | Cetak bill terima produk konsinyasi | ⬜ |
| 260 | GET | `/reports/invoice` | Cetak invoice | ⬜ |
| 261 | GET | `/reports/item-receive-notplace` | Cetak daftar item diterima yang belum diletakkan | ⬜ |
| 262 | GET | `/reports/lable/print/` | Cetak label pengiriman | ⬜ |
| 263 | GET | `/reports/purchaseorder/` | Cetak detail purchase order | ⬜ |
| 264 | GET | `/reports/putaway` | Cetak laporan putaway | ⬜ |
| 265 | GET | `/reports/receive` | Cetak bill terima untuk purchase order | ⬜ |
| 266 | GET | `/reports/shipping-label/` | Cetak label pengiriman | ⬜ |
| 267 | GET | `/reports/stock-opname` | Cetak daftar item untuk opname | ⬜ |
| 268 | GET | `/reports/wms/pick-list` | Cetak picklist item | ⬜ |
| 269 | GET | `/reports/wms/shipping-manifest` | Cetak bukti pengiriman (shipping manifest) | ⬜ |

### System Setting — ✅1 🔄1 ⬜6 (total 8)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 270 | GET | `/lazada/get-shipment-providers/{storeId}/` | Ambil info penyedia pengiriman Lazada | ⬜ |
| 271 | GET | `/store-locations/` | Ambil lokasi toko | ⬜ |
| 272 | GET | `/systemsetting/account-mapping` | Ambil pemetaan akun akuntansi | ⬜ |
| 273 | GET | `/systemsetting/sales-return-setting` | Ambil setting retur penjualan | ⬜ |
| 274 | POST | `/systemsetting/sales-return-setting` | Buat setting retur penjualan | ⬜ |
| 275 | GET | `/systemsetting/users/` | Ambil daftar user/staf gudang | ✅ |
| 276 | POST | `/systemsetting/webhook` | Buat/ubah registrasi webhook | ⬜ |
| 277 | GET | `/taxes/` | Ambil daftar pajak | 🔄 |

### Webhooks — ✅0 🔄0 ⬜9 (total 9)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 278 | POST | `/webhooks/invoice` | Webhook: notifikasi invoice baru | ⬜ |
| 279 | POST | `/webhooks/payment` | Webhook: notifikasi update pembayaran | ⬜ |
| 280 | POST | `/webhooks/price` | Webhook: notifikasi update harga | ⬜ |
| 281 | POST | `/webhooks/product` | Webhook: notifikasi produk baru | ⬜ |
| 282 | POST | `/webhooks/purchaseorder` | Webhook: notifikasi PO baru | ⬜ |
| 283 | POST | `/webhooks/salesorder` | Webhook: notifikasi update sales order | ⬜ |
| 284 | POST | `/webhooks/salesreturn` | Webhook: notifikasi retur penjualan baru | ⬜ |
| 285 | POST | `/webhooks/stock` | Webhook: notifikasi update stok | ⬜ |
| 286 | POST | `/webhooks/stocktransfer` | Webhook: notifikasi transfer stok baru | ⬜ |

### Channels — ✅0 🔄1 ⬜0 (total 1)

| # | Method | Endpoint | Untuk apa (fungsi) | Status |
|---:|---|---|---|:--:|
| 287 | GET | `/marketplace/store/` | Ambil semua toko marketplace yang terhubung | 🔄 |