# Inventory Module Implementation Plan

> Total: 27 endpoints (9 PARTIAL + 18 TODO) — dibagi 3 phase + commit per phase

---

## Phase 1 — PARTIAL Fixes (6 endpoints)

### 1.1 Stock opname rack filter
- **Endpoint:** `GET /inventory/stock-opname/items/filtered`
- **Deskripsi:** Filter item opname berdasarkan lokasi rak (floor, row, column, bin)
- **File:** `StockOpnameController@filteredItems`, `StockOpnameService`, `StockOpnameRepository`, `routes/api.php`

### 1.2 Delete transfer
- **Endpoint:** `DELETE /inventory/transfers/{id}`
- **Deskripsi:** Hapus dokumen transfer (hanya status DRAFT)
- **File:** `InventoryTransactionController@transferDestroy`, `InventoryService@deleteTransfer`, `InventoryTransferRepository`, `routes/api.php`

### 1.3 Filter finished transfers
- **Endpoint:** `GET /inventory/transfers/out-finished`
- **Deskripsi:** List transfer yang sudah selesai (status=RECEIVED)
- **File:** `InventoryTransactionController@finishedList`, `routes/api.php`

### 1.4 Route aliases (3 endpoint "samakan")
- `GET /inventory/items/item-on-stock` → `InventoryController@itemsToStock`
- `GET /inventory/items/by-transfer/{id}` → `InventoryTransactionController@transferShow`
- `POST /inventory/catalog/set-master` → wrapper ke `ProductMergeService`

---

## Phase 2 — New Read Endpoints (5 endpoints)

### 2.1 Get cost+stock for adjustment
- **Endpoint:** `POST /inventory/items/to-adjust/`
- **Deskripsi:** Terima `{ item_ids: [...] }`, return stock per item per lokasi
- **File:** `InventoryController@toAdjust`, `InventoryRepository@getStockByItemIds`

### 2.2 Out of stock in order
- **Endpoint:** `GET /inventory/out-of-stock-in-order/`
- **Deskripsi:** Produk habis stok yang punya order pending
- **File:** `InventoryController@outOfStockInOrder`, `InventoryRepository@getOutOfStockInOrder`

### 2.3 Item batch numbers
- **Endpoint:** `GET /inventory/items/{id}/batch-number`
- **Deskripsi:** Daftar batch/serial number suatu item
- **File:** `InventoryController@batchNumbers`, `InventoryRepository@getBatchNumbers`

### 2.4 Items to sell by location
- **Endpoint:** `GET /inventory/items/to-sell/{location_id}`
- **Deskripsi:** Item yang available > 0 di lokasi tertentu
- **File:** `InventoryController@toSell`, `InventoryRepository@getAvailableToSell`

### 2.5 Items to sales return
- **Endpoint:** `GET /inventory/items/to-sales-return`
- **Deskripsi:** Item yang eligible untuk retur penjualan
- **File:** `InventoryController@toSalesReturn`

---

## Phase 3 — Schema Changes + New Features (16 endpoints)

### Migrations (6 file baru):
1. `add_avg_cost_to_inventories` — tambah `avg_cost decimal(15,2) DEFAULT 0`
2. `add_min_stock_to_product_variants` — tambah `min_stock integer DEFAULT 0`
3. `add_printed_fields_to_inventory_transfers` — tambah `printed_at`, `printed_by`
4. `create_stock_revaluations_table` — tabel revaluasi + items
5. `create_purchase_bills_table` — tabel purchase bill + items
6. `create_sales_invoices_table` — tabel sales invoice + items

### 3.1 Need restock
- **Endpoint:** `GET /inventory/need-restock/`
- **Deskripsi:** Produk di bawah reorder point (min_stock)
- **File:** `InventoryController@needRestock`, `InventoryRepository@getNeedRestock`

### 3.2 Transfer mark printed
- **Endpoint:** `POST /inventory/transfer/mark-printed`
- **Deskripsi:** Tandai transfer sudah dicetak
- **File:** `InventoryTransactionController@markTransferPrinted`, `InventoryService@markTransferPrinted`

### 3.3 Transfer delivery report
- **Endpoint:** `GET /inventory/transfer/delivery`
- **Deskripsi:** Data transfer untuk cetak surat jalan
- **File:** `InventoryTransactionController@transferDelivery`

### 3.4 Split item
- **Endpoint:** `POST /inventory/items/split-item`
- **Deskripsi:** Pisah item ke unit lebih kecil (misal 1 box → 10 pcs)
- **File:** `InventoryController@splitItem`, `InventoryService@splitItem`, `SplitItemRequest`

### 3.5 Stock revaluation (full CRUD)
- **Endpoint:** `POST /inventory/revaluations/` + index/show/approve/cancel
- **Deskripsi:** Penyesuaian nilai stok, update avg_cost di inventories saat approve
- **File baru:** `StockRevaluation` model, `StockRevaluationItem` model, `StockRevaluationService`, `StockRevaluationRepository`, `StockRevaluationController`, `StoreStockRevaluationRequest`

### 3.6 Items by purchase bill
- **Endpoint:** `GET /inventory/items/by-bill/{doc_id}`
- **Deskripsi:** Item yang terkait dengan purchase bill tertentu
- **File baru:** `PurchaseBill` + `PurchaseBillItem` model (Purchase module)
- **File:** `InventoryController@itemsByBill`

### 3.7 Items by sales invoice
- **Endpoint:** `GET /inventory/items/by-invoice/{invoice_id}`
- **Deskripsi:** Item yang terkait dengan sales invoice tertentu
- **File baru:** `SalesInvoice` + `SalesInvoiceItem` model (Sales module)
- **File:** `InventoryController@itemsByInvoice`

---

## File Summary

### New Files (17):
| File | Phase |
|------|-------|
| Migration: `add_avg_cost_to_inventories` | 3 |
| Migration: `add_min_stock_to_product_variants` | 3 |
| Migration: `add_printed_fields_to_inventory_transfers` | 3 |
| Migration: `create_stock_revaluations_table` | 3 |
| Migration: `create_purchase_bills_table` | 3 |
| Migration: `create_sales_invoices_table` | 3 |
| `Inventory/Models/StockRevaluation.php` | 3 |
| `Inventory/Models/StockRevaluationItem.php` | 3 |
| `Inventory/Services/StockRevaluationService.php` | 3 |
| `Inventory/Repositories/StockRevaluationRepository.php` | 3 |
| `Inventory/Controllers/StockRevaluationController.php` | 3 |
| `Inventory/Requests/StoreStockRevaluationRequest.php` | 3 |
| `Inventory/Requests/SplitItemRequest.php` | 3 |
| `Purchase/Models/PurchaseBill.php` | 3 |
| `Purchase/Models/PurchaseBillItem.php` | 3 |
| `Sales/Models/SalesInvoice.php` | 3 |
| `Sales/Models/SalesInvoiceItem.php` | 3 |

### Modified Files (12):
| File | Phase |
|------|-------|
| `Inventory/routes/api.php` | 1,2,3 |
| `Inventory/Controllers/InventoryController.php` | 1,2,3 |
| `Inventory/Controllers/InventoryTransactionController.php` | 1,3 |
| `Inventory/Controllers/StockOpnameController.php` | 1 |
| `Inventory/Services/InventoryService.php` | 1,3 |
| `Inventory/Services/StockOpnameService.php` | 1 |
| `Inventory/Repositories/InventoryRepository.php` | 2,3 |
| `Inventory/Repositories/InventoryTransferRepository.php` | 1 |
| `Inventory/Repositories/StockOpnameRepository.php` | 1 |
| `Inventory/Models/Inventory.php` | 3 |
| `Inventory/Models/InventoryTransfer.php` | 3 |
| `Product/Models/ProductVariant.php` | 3 |
