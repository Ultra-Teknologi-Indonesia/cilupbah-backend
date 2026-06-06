# Plan E2E: Implementasi UUIDv7 — Semua Layer

## Context

Migrasi dari auto-increment bigint ke UUIDv7 (hex 32 char) harus konsisten di **semua layer**: migration, model, controller, service, repository, request validation, API resource, OpenAPI docs, seeder, dan test.

Standar project:
- **Trait:** `App\Traits\HasUuid7` → `Uuid::uuid7()->getHex()->toString()`
- **Kolom DB:** `VARCHAR(32)`
- **Type hint:** `string` (bukan `int`)

---

## Status Saat Ini (Per Layer)

| Modul | Migration | Model | Controller | Service | Repository | Request | OpenAPI | Test |
|---|---|---|---|---|---|---|---|---|
| Product | ✓ uuid PK | ✗ `HasUuids` | ⚠ `(int)` cast | ✓ uuid7 | ✓ | ⚠ mix | ⚠ mix | ✗ hardcoded int |
| Inventory | ✗ bigint PK | ✗ tidak ada | ✗ `int` hint | ⚠ mix | ✗ `int` hint | ✓ uuid rule | ✗ `integer` | ✗ |
| Inbound | ✓ uuid PK | ✗ `HasUuids` | ✓ `string` | ✓ `string` | ✓ `string` | ✓ uuid rule | ⚠ mix | — |
| Supplier | ✗ bigint PK | ✗ tidak ada | ✗ `int` hint | ✗ `int` hint | ✗ `int` hint | ✓ | ✗ `integer` | — |
| Purchase | ✗ bigint PK | ✗ tidak ada | ✗ `int` hint | ✗ `int` hint | ✗ `int` hint | ⚠ `integer` | ✗ `integer` | — |
| Sales | ✗ bigint PK | ✗ tidak ada | ✗ `int` hint | ✗ `int` hint | ✗ `int` hint | ⚠ `integer` | ✗ `integer` | — |
| Warranty | — belum ada | — | — stub | — | — | — | — | — |

---

## Phase 1: Model — Fix Trait

Ganti `HasUuids` (Laravel bawaan, UUID v4 36 char) → `HasUuid7` (custom, hex 32 char).

### Product
```
Modules/Product/app/Models/Product.php
Modules/Product/app/Models/ProductVariant.php
```
```diff
- use Illuminate\Database\Eloquent\Concerns\HasUuids;
+ use App\Traits\HasUuid7;
-     use HasUuids;
+     use HasUuid7;
```

### Inbound
```
Modules/Inbound/app/Models/Inbound.php
Modules/Inbound/app/Models/InboundItem.php
Modules/Inbound/app/Models/InboundReceipt.php
Modules/Inbound/app/Models/InboundAssignment.php
```
Sama — ganti `HasUuids` → `HasUuid7`.

### Warehouse (LocationBin)
```
Modules/Warehouse/app/Models/LocationBin.php
```
```diff
- use Illuminate\Database\Eloquent\Concerns\HasUuids;
  use App\Traits\HasUuid7;
-     use HasFactory, HasUuids;
+     use HasFactory, HasUuid7;
```

### Tambah trait baru (belum ada trait sama sekali)
```
Modules/Inventory/app/Models/Inventory.php
Modules/Inventory/app/Models/InventoryMovement.php
Modules/Inventory/app/Models/InventoryTransfer.php
Modules/Inventory/app/Models/InventoryTransferItem.php
Modules/Supplier/app/Models/Supplier.php
Modules/Purchase/app/Models/PurchaseOrder.php
Modules/Purchase/app/Models/PurchaseOrderItem.php
Modules/Sales/app/Models/SalesReturn.php
Modules/Sales/app/Models/SalesReturnItem.php
```
Tambah `use App\Traits\HasUuid7;` dan `use HasUuid7;` di class.

---

## Phase 2: Migration — Konversi Kolom bigint → VARCHAR(32)

### 2.1 `change_inventory_ids_to_uuid.php`

| Step | Tabel | Aksi |
|---|---|---|
| Drop FK | `inventory_transfer_items` | `inventory_transfer_id` |
| ALTER | `inventories` | `id` → VARCHAR(32), DROP DEFAULT |
| ALTER | `inventory_movements` | `id` → VARCHAR(32), DROP DEFAULT |
| ALTER | `inventory_transfers` | `id` → VARCHAR(32), DROP DEFAULT |
| ALTER | `inventory_transfer_items` | `id`, `inventory_transfer_id` → VARCHAR(32) |
| Re-add FK | `inventory_transfer_items.inventory_transfer_id` → `inventory_transfers.id` | cascadeOnDelete |

### 2.2 `change_supplier_ids_to_uuid.php`

| Step | Tabel | Aksi |
|---|---|---|
| Drop FK | `purchase_orders` | `supplier_id` |
| ALTER | `suppliers` | `id` → VARCHAR(32), DROP DEFAULT |
| ALTER | `purchase_orders` | `supplier_id` → VARCHAR(32) |
| Re-add FK | `purchase_orders.supplier_id` → `suppliers.id` | restrictOnDelete |

### 2.3 `change_purchase_ids_to_uuid.php`

| Step | Tabel | Aksi |
|---|---|---|
| Drop FK | `purchase_order_items` | `purchase_order_id` |
| ALTER | `purchase_orders` | `id` → VARCHAR(32), DROP DEFAULT |
| ALTER | `purchase_order_items` | `id`, `purchase_order_id` → VARCHAR(32) |
| Re-add FK | `purchase_order_items.purchase_order_id` → `purchase_orders.id` | cascadeOnDelete |

### 2.4 `change_sales_ids_to_uuid.php`

| Step | Tabel | Aksi |
|---|---|---|
| Drop FK | `sales_return_items` | `sales_return_id` |
| ALTER | `sales_returns` | `id` → VARCHAR(32), DROP DEFAULT |
| ALTER | `sales_return_items` | `id`, `sales_return_id` → VARCHAR(32) |
| Re-add FK | `sales_return_items.sales_return_id` → `sales_returns.id` | cascadeOnDelete |

### 2.5 `create_warranties_table.php` + `create_warranty_claims_table.php`

Tabel baru langsung pakai `$table->string('id', 32)->primary()`.

**warranties:** `id`, `product_variant_id` (FK), `order_id` (FK nullable), `serial_no`, `duration_months`, `start_date`, `end_date`, `status`, `notes`, timestamps.

**warranty_claims:** `id`, `warranty_id` (FK), `claim_number` (unique), `reason`, `status`, `resolution`, `claimed_by`, `claimed_at`, `resolved_at`, timestamps.

---

## Phase 3: Controller — Fix Type Hints & Casts

### Product

**`ChannelProductController.php`:**
```diff
- $tiktokService->pushUpdate($product->id, $shopId);   // line ~229, cast (int)
+ $tiktokService->pushUpdate($product->id, $shopId);   // hapus (int) cast
```
Cek line 346, 373 — ada `(int)$id` yang harus dihapus.

### Inventory

**`InventoryController.php`:**
```diff
- public function show(int $itemId)
+ public function show(string $itemId)
```

**`InventoryTransactionController.php`:**
```diff
- public function transferIn(int $id)
+ public function transferIn(string $id)
```

### Supplier

**`SupplierController.php`:**
```diff
- public function show(int $id)
+ public function show(string $id)
- public function update(UpdateSupplierRequest $request, int $id)
+ public function update(UpdateSupplierRequest $request, string $id)
- public function destroy(int $id)
+ public function destroy(string $id)
```

### Purchase

**`PurchaseOrderController.php`:** — semua method yang terima `int $id` → `string $id`
- `show(int $id)` → `show(string $id)`
- `approve(int $id)` → `approve(string $id)`
- `receive(int $id)` → `receive(string $id)`
- `cancel(int $id)` → `cancel(string $id)`
- `destroy(int $id)` → `destroy(string $id)`

### Sales

**`SalesReturnController.php`:** — semua method `int $id` → `string $id`
- `show`, `accept`, `reject`, `complete`

---

## Phase 4: Service — Fix Type Hints

### Inventory

**`InventoryService.php`:**
```diff
- public function transferOut(array $data): InventoryTransfer    // line ~300
+ // Pastikan $transfer->id diakses sebagai string, bukan int
```
Cek semua method yang pass transfer ID sebagai `int`.

### Supplier

**`SupplierService.php`:**
```diff
- public function getById(int $id)
+ public function getById(string $id)
- public function update(int $id, array $data)
+ public function update(string $id, array $data)
- public function delete(int $id)
+ public function delete(string $id)
```

### Purchase

**`PurchaseOrderService.php`:**
```diff
- public function getById(int $id)
+ public function getById(string $id)
- public function approve(int $id)
+ public function approve(string $id)
- public function receive(int $poId, array $data)
+ public function receive(string $poId, array $data)
- public function cancel(int $id)
+ public function cancel(string $id)
```

### Sales

**`SalesReturnService.php`:**
```diff
- public function getById(int $id)
+ public function getById(string $id)
- public function accept(int $id, string $acceptedBy)
+ public function accept(string $id, string $acceptedBy)
- public function reject(int $id, string $rejectedBy, string $reason)
+ public function reject(string $id, string $rejectedBy, string $reason)
- public function complete(int $id, string $completedBy)
+ public function complete(string $id, string $completedBy)
```

---

## Phase 5: Repository — Fix Type Hints

### Inventory

**`InventoryTransferRepository.php`:**
```diff
- public function findById(int $id): ?InventoryTransfer
+ public function findById(string $id): ?InventoryTransfer
- public function findByIdForUpdate(int $id): ?InventoryTransfer
+ public function findByIdForUpdate(string $id): ?InventoryTransfer
```

### Supplier

**`SupplierRepository.php`:**
```diff
- public function findById(int $id): ?Supplier
+ public function findById(string $id): ?Supplier
```

### Purchase

**`PurchaseOrderRepository.php`:**
```diff
- public function findById(int $id): ?PurchaseOrder
+ public function findById(string $id): ?PurchaseOrder
- public function findByIdForUpdate(int $id): ?PurchaseOrder
+ public function findByIdForUpdate(string $id): ?PurchaseOrder
```

### Sales

**`SalesReturnRepository.php`:**
```diff
- public function findById(int $id): ?SalesReturn
+ public function findById(string $id): ?SalesReturn
- public function findByIdForUpdate(int $id): ?SalesReturn
+ public function findByIdForUpdate(string $id): ?SalesReturn
```

---

## Phase 6: Request Validation — Fix Rules

| File | Field | Sekarang | Ganti |
|---|---|---|---|
| `Purchase/StorePurchaseOrderRequest.php` | `supplier_id` | `'integer', 'exists:suppliers,id'` | `'string', 'exists:suppliers,id'` |
| `Purchase/StorePurchaseOrderRequest.php` | `location_id` | `'integer', 'exists:locations,id'` | `'string', 'exists:locations,id'` |
| `Purchase/ReceivePurchaseOrderRequest.php` | `purchase_order_item_id` | `'integer', 'exists:purchase_order_items,id'` | `'string', 'exists:purchase_order_items,id'` |
| `Sales/StoreSalesReturnRequest.php` | `order_id` | `'integer', 'exists:orders,id'` | `'string', 'exists:orders,id'` |
| `Sales/StoreSalesReturnRequest.php` | `location_id` | `'integer', 'exists:locations,id'` | `'string', 'exists:locations,id'` |
| `Inventory/TransferOutRequest.php` | `source_location_id` | `'integer', 'exists:locations,id'` | `'string', 'exists:locations,id'` |
| `Inventory/TransferOutRequest.php` | `destination_location_id` | `'integer', 'exists:locations,id'` | `'string', 'exists:locations,id'` |
| `Inbound/StoreInboundRequest.php` | `location_id` | `'integer', 'exists:locations,id'` | `'string', 'exists:locations,id'` |
| `Inbound/AutoPutawayRequest.php` | `location_id` | `'integer', 'exists:locations,id'` | `'string', 'exists:locations,id'` |
| `Inbound/StoreInboundRequest.php` | `source_id` | `'integer'` | `'string'` |

> **Tidak diubah:** `assigned_to` di `AssignInboundRequest` — tetap `'integer'` karena `users.id` masih bigint.

---

## Phase 7: OpenAPI Annotations — Fix `type: 'integer'` → `type: 'string'`

Semua properti dan parameter yang mereferensikan kolom UUID harus diubah.

### Supplier Controller
| Baris | Property/Param | Perubahan |
|---|---|---|
| 84, 108, 139 | `id` path param | `type: 'integer'` → `type: 'string'` |

### Purchase Controller
| Baris | Property/Param | Perubahan |
|---|---|---|
| 28 | `filter[supplier_id]` | `type: 'integer'` → `type: 'string'` |
| 69, 131, 154, 190, 213 | `id` path param | → `type: 'string'` |
| 95 | `supplier_id` property | → `type: 'string'` |
| 96 | `location_id` property | → `type: 'string'` |
| 163 | `purchase_order_item_id` | → `type: 'string'` |

### Sales Controller
| Baris | Property/Param | Perubahan |
|---|---|---|
| 67, 130, 161, 196 | `id` path param | → `type: 'string'` |
| 93 | `order_id` property | → `type: 'string'` |
| 94 | `location_id` property | → `type: 'string'` |

### Inventory Controller
| Baris | Property/Param | Perubahan |
|---|---|---|
| 21 | `id` property | → `type: 'string'` |
| 23 | `location_id` property | → `type: 'string'` |
| 75 | `itemId` path param | → `type: 'string'` |

### Inventory Transaction Controller
| Baris | Property/Param | Perubahan |
|---|---|---|
| 20, 35 | `location_id` | → `type: 'string'` |
| 51 | `source_location_id` | → `type: 'string'` |
| 53 | `destination_location_id` | → `type: 'string'` |
| 143, 206 | `id` path param | → `type: 'string'` |

### Inbound Controller
| Baris | Property/Param | Perubahan |
|---|---|---|
| 26, 44 | `location_id` property | → `type: 'string'` |
| 31, 48 | `source_id` property | → `type: 'string'` |
| 208 | `filter[location_id]` param | → `type: 'string'` |

### Order (sudah UUID tapi OpenAPI belum diupdate)
| File | Property/Param | Perubahan |
|---|---|---|
| `OrderController.php:20` | `id` property | → `type: 'string'` |
| `OrderController.php:187,227,271` | `order` path param | → `type: 'string'` |
| `OrderResource.php:14,17` | `id`, `channel_shop_id` | → `type: 'string'` |
| `OrderItemResource.php:14,15` | `id`, `item_id` | → `type: 'string'` |

---

## Phase 8: Service — Fix `insertGetId()` & `Str::orderedUuid()`

### ProductImportService.php

```diff
  // Line 41 — products
- $productId = DB::table('products')->insertGetId($productData);
+ $productId = \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString();
+ DB::table('products')->insert(array_merge($productData, ['id' => $productId]));

  // Line 61 — product_variants
- $variantId = DB::table('product_variants')->insertGetId($variantData);
+ $variantId = \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString();
+ DB::table('product_variants')->insert(array_merge($variantData, ['id' => $variantId]));
```

> `categories` dan `brands` tetap pakai `insertGetId()` — PK mereka masih bigint.

### OrderRepository.php

```diff
  // Line 84
- $orderId = DB::table('orders')->insertGetId($orderRow);
+ $orderId = \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString();
+ DB::table('orders')->insert(array_merge($orderRow, ['id' => $orderId]));
```

---

## Phase 9: Seeder — Fix Hardcoded Integer IDs

### `Modules/Inbound/database/seeders/InboundDatabaseSeeder.php`
- Cek apakah pakai `insertGetId()` untuk tabel yang sudah UUID — ganti ke generate UUID + `insert()`.

### `Modules/Warehouse/database/seeders/WarehouseDatabaseSeeder.php`
- `insertGetId()` untuk `locations` — locations sudah VARCHAR(32), harus generate UUID dulu.

---

## Phase 10: Test — Fix Hardcoded Integer IDs

### `Modules/Product/tests/Feature/ProductUploadTest.php`
- Hardcoded `brand_id: 101`, `category_id: 54` — ini masih benar karena brands/categories tetap bigint.
- Cek apakah ada test yang expect integer product/variant ID — ubah ke string.

### `Modules/Product/tests/Feature/ChannelProductTest.php`
- Hardcoded `brand_id: 1`, `category_id: 1` — masih benar (bigint).
- Cek product ID assertions.

### Semua test di modul lain
- Grep `->id` assertions yang expect integer.

---

## Phase 11: Warranty — Implementasi Lengkap

Module Warranty saat ini hanya scaffolding. Setelah migration dan model dibuat (Phase 2.5 + Phase 1), implementasikan:

1. **Model:** `Warranty.php`, `WarrantyClaim.php` — dengan `HasUuid7`, relationships, fillable
2. **Repository:** `WarrantyRepository.php` — CRUD, findById(`string $id`)
3. **Service:** `WarrantyService.php` — business logic
4. **Request:** `StoreWarrantyRequest.php`, `StoreWarrantyClaimRequest.php` — validasi dengan `'string'` rules
5. **Controller:** `WarrantyController.php` — CRUD endpoints, type hint `string $id`
6. **Routes:** `routes/api.php` — apiResource
7. **OpenAPI:** Annotations dengan `type: 'string'` untuk semua ID fields

---

## Urutan Eksekusi

```
Phase 1   → Model traits (fix HasUuids → HasUuid7, tambah HasUuid7 baru)
Phase 2   → Migrations (konversi bigint → VARCHAR(32), buat tabel warranty)
Phase 3-5 → Controller, Service, Repository type hints (int → string)
Phase 6   → Validation rules (integer → string)
Phase 7   → OpenAPI annotations (integer → string)
Phase 8   → Fix insertGetId / Str::orderedUuid
Phase 9   → Seeders
Phase 10  → Tests
Phase 11  → Warranty full implementation
```

---

## Verifikasi E2E

### Database
- [ ] `php artisan migrate` — tanpa error
- [ ] `php artisan tinker` — create record per tabel, cek ID = 32 char hex

### API Flow — Per Modul

**Product:**
- [ ] `POST /api/v1/products` → response `id` = string UUID
- [ ] `GET /api/v1/products/{uuid}` → return product
- [ ] `POST /api/v1/products/import/single` → import berhasil, ID UUID

**Inventory:**
- [ ] `GET /api/v1/inventory/stocks/{uuid}` → return stock by item UUID
- [ ] `POST /api/v1/inventory/adjustments` → adjust dengan UUID item_id
- [ ] `POST /api/v1/inventory/transfers` → create transfer, ID UUID
- [ ] `POST /api/v1/inventory/transfers/{uuid}` → receive transfer

**Inbound:**
- [ ] `POST /api/v1/inbounds` → create inbound, ID UUID
- [ ] `POST /api/v1/inbounds/{uuid}/receive` → receive items
- [ ] `POST /api/v1/inbounds/{uuid}/putaway` → putaway items

**Supplier:**
- [ ] `POST /api/v1/suppliers` → create, ID UUID
- [ ] `GET /api/v1/suppliers/{uuid}` → show
- [ ] `PUT /api/v1/suppliers/{uuid}` → update
- [ ] `DELETE /api/v1/suppliers/{uuid}` → delete

**Purchase:**
- [ ] `POST /api/v1/purchase/orders` → create PO dengan UUID supplier_id
- [ ] `POST /api/v1/purchase/orders/{uuid}/approve` → approve
- [ ] `POST /api/v1/purchase/orders/{uuid}/receive` → receive, auto-create inbound

**Sales:**
- [ ] `POST /api/v1/sales/returns` → create return dengan UUID order_id
- [ ] `POST /api/v1/sales/returns/{uuid}/accept` → accept
- [ ] `POST /api/v1/sales/returns/{uuid}/complete` → complete, auto-create inbound

**Warranty:**
- [ ] `POST /api/v1/warranties` → create warranty, ID UUID
- [ ] `GET /api/v1/warranties/{uuid}` → show

### Cross-Module Flow
- [ ] Create PO → Approve → Receive → Auto-create Inbound → Receive Inbound → Putaway → Inventory updated
- [ ] Create Order → Ship → Sales Return → Accept → Complete → Inbound created → Receive → Inventory updated
- [ ] Transfer Out → Transit → Transfer In → Inventory moved

### FK Integrity
- [ ] Delete product → cascade ke variants, specs, media, bundles
- [ ] Delete supplier → restrict (blocked jika ada PO)
- [ ] Delete inbound → cascade ke items dan receipts

### OpenAPI
- [ ] `php artisan l5-swagger:generate` — tanpa error
- [ ] Buka Swagger UI — semua ID field bertipe `string`

### Tests
- [ ] `php artisan test` — semua test pass
