# Plan: Implementasi UUIDv7 — Product, Inventory, Inbound, Supplier, Warranty, Purchase, Sales

## Context

Project ini sedang migrasi bertahap dari auto-increment bigint ke UUIDv7. Standar yang sudah established:
- **Trait:** `App\Traits\HasUuid7` — generate `Uuid::uuid7()->getHex()->toString()` (hex 32 char, tanpa dash)
- **Kolom DB:** `VARCHAR(32)` via `ALTER COLUMN ... TYPE VARCHAR(32)`
- **Modul yang sudah benar:** Channel, Order, Warehouse (Location, LocationZone, ChannelWarehouse)

### Status Saat Ini

| Modul | Migration PK | Model Trait | Masalah |
|---|---|---|---|
| **Product** | `uuid('id')` ✓ | `HasUuids` ✗ | Trait salah (Laravel bawaan, bukan `HasUuid7`) |
| **Inbound** | `uuid('id')` ✓ | `HasUuids` ✗ | Trait salah |
| **Warehouse/LocationBin** | `uuid('id')` ✓ | `HasUuids` ✗ | Import `HasUuid7` tapi pakai `HasUuids` |
| **Inventory** | `$table->id()` ✗ | Tidak ada ✗ | Masih bigint, belum ada UUID sama sekali |
| **Supplier** | `$table->id()` ✗ | Tidak ada ✗ | Masih bigint |
| **Purchase** | `$table->id()` ✗ | Tidak ada ✗ | Masih bigint |
| **Sales** | `$table->id()` ✗ | Tidak ada ✗ | Masih bigint |
| **Warranty** | Belum ada tabel | — | Buat baru langsung UUID |

### Perbedaan `HasUuids` vs `HasUuid7`

- `HasUuids` (Laravel bawaan): generate UUID v4 format `550e8400-e29b-41d4-a716-446655440000` (36 char, ada dash)
- `HasUuid7` (custom trait): generate UUID v7 hex `0190a1b2c3d4e5f6a7b8c9d0e1f2a3b4` (32 char, tanpa dash, time-sortable)

Kolom database adalah `VARCHAR(32)` — `HasUuids` akan gagal karena generate 36 char.

---

## Phase 1: Fix Model Traits (HasUuids → HasUuid7)

Ganti `HasUuids` ke `HasUuid7` di model yang sudah punya UUID PK di migration tapi pakai trait salah.

### 1.1 Product Models

**`Modules/Product/app/Models/Product.php`:**
```diff
- use Illuminate\Database\Eloquent\Concerns\HasUuids;
+ use App\Traits\HasUuid7;
  ...
-     use HasUuids;
+     use HasUuid7;
```

**`Modules/Product/app/Models/ProductVariant.php`:** — sama

### 1.2 Inbound Models

**`Modules/Inbound/app/Models/Inbound.php`** — ganti `HasUuids` → `HasUuid7`
**`Modules/Inbound/app/Models/InboundItem.php`** — sama
**`Modules/Inbound/app/Models/InboundReceipt.php`** — sama
**`Modules/Inbound/app/Models/InboundAssignment.php`** — sama

### 1.3 Warehouse/LocationBin

**`Modules/Warehouse/app/Models/LocationBin.php`:**
```diff
- use Illuminate\Database\Eloquent\Concerns\HasUuids;
  use App\Traits\HasUuid7;
  ...
-     use HasFactory, HasUuids;
+     use HasFactory, HasUuid7;
```

### 1.4 Fix `insertGetId()` dan `Str::orderedUuid()`

**ProductService.php** sudah pakai `Str::orderedUuid()` (UUID v4 ordered) — harus ganti ke `Uuid::uuid7()->getHex()->toString()` atau refactor ke Eloquent `create()`.

**Pattern:** ganti semua `(string) Str::orderedUuid()` → `Ramsey\Uuid\Uuid::uuid7()->getHex()->toString()`

| File | Baris | Perubahan |
|---|---|---|
| `Modules/Product/app/Services/ProductService.php:64` | `Str::orderedUuid()` | → `Uuid::uuid7()->getHex()->toString()` |
| `Modules/Product/app/Services/ProductService.php:86` | `Str::orderedUuid()` | → idem |
| `Modules/Product/app/Services/ProductService.php:142` | `Str::orderedUuid()` | → idem |

**ProductImportService.php** masih pakai `insertGetId()`:

| File | Baris | Perubahan |
|---|---|---|
| `ProductImportService.php:41` | `insertGetId($productData)` | → generate UUID dulu, lalu `insert()` |
| `ProductImportService.php:61` | `insertGetId($variantData)` | → idem |
| `ProductImportService.php:126` | `insertGetId(categories)` | → tetap (categories masih bigint) |
| `ProductImportService.php:145` | `insertGetId(brands)` | → tetap (brands masih bigint) |

**OrderRepository.php** pakai `insertGetId()`:

| File | Baris | Perubahan |
|---|---|---|
| `Modules/Order/app/Repositories/OrderRepository.php:84` | `insertGetId($orderRow)` | → generate UUID dulu, lalu `insert()` |

---

## Phase 2: Migration — Inventory Module (bigint → UUID)

Inventory masih full bigint. Perlu migration konversi.

### Migration: `2026_06_07_000100_change_inventory_ids_to_uuid.php`

**Step 1 — Drop FK:**

| Tabel | FK |
|---|---|
| `inventory_transfer_items` | `inventory_transfer_id` |

**Step 2 — ALTER to VARCHAR(32) + DROP DEFAULT:**

| Tabel | Kolom |
|---|---|
| `inventories` | `id` (PK) |
| `inventory_movements` | `id` (PK) |
| `inventory_transfers` | `id` (PK) |
| `inventory_transfer_items` | `id` (PK), `inventory_transfer_id` (FK) |

> `item_id`, `location_id`, `bin_id` sudah `uuid()` type di migration asal — tidak perlu diubah.
> `location_id` di `inventory_transfers` pakai `foreignId` (bigint) tapi sudah dikonversi oleh Warehouse migration `2026_06_06_120400`.

**Step 3 — Re-add FK:**
- `inventory_transfer_items.inventory_transfer_id` → `inventory_transfers.id` cascadeOnDelete

### Models — Tambah `HasUuid7`:
- `Modules/Inventory/app/Models/Inventory.php`
- `Modules/Inventory/app/Models/InventoryMovement.php`
- `Modules/Inventory/app/Models/InventoryTransfer.php`
- `Modules/Inventory/app/Models/InventoryTransferItem.php`

---

## Phase 3: Migration — Supplier Module (bigint → UUID)

### Migration: `2026_06_07_000200_change_supplier_ids_to_uuid.php`

**Step 1 — Drop FK:**
- `purchase_orders.supplier_id`

**Step 2 — ALTER to VARCHAR(32):**
- `suppliers.id` (PK + DROP DEFAULT)
- `purchase_orders.supplier_id` (FK)

**Step 3 — Re-add FK:**
- `purchase_orders.supplier_id` → `suppliers.id` restrictOnDelete

### Model — Tambah `HasUuid7`:
- `Modules/Supplier/app/Models/Supplier.php`

---

## Phase 4: Migration — Purchase Module (bigint → UUID)

### Migration: `2026_06_07_000300_change_purchase_ids_to_uuid.php`

**Step 1 — Drop FK:**
- `purchase_order_items.purchase_order_id`

**Step 2 — ALTER to VARCHAR(32):**
- `purchase_orders.id` (PK + DROP DEFAULT)
- `purchase_order_items.id` (PK + DROP DEFAULT)
- `purchase_order_items.purchase_order_id` (FK)

> `purchase_orders.supplier_id` sudah diubah di Phase 3.
> `purchase_orders.location_id` sudah diubah oleh Warehouse migration.
> `purchase_order_items.item_id` sudah `uuid()` di migration asal.

**Step 3 — Re-add FK:**
- `purchase_order_items.purchase_order_id` → `purchase_orders.id` cascadeOnDelete

### Models — Tambah `HasUuid7`:
- `Modules/Purchase/app/Models/PurchaseOrder.php`
- `Modules/Purchase/app/Models/PurchaseOrderItem.php`

---

## Phase 5: Migration — Sales Module (bigint → UUID)

### Migration: `2026_06_07_000400_change_sales_ids_to_uuid.php`

**Step 1 — Drop FK:**
- `sales_return_items.sales_return_id`

**Step 2 — ALTER to VARCHAR(32):**
- `sales_returns.id` (PK + DROP DEFAULT)
- `sales_return_items.id` (PK + DROP DEFAULT)
- `sales_return_items.sales_return_id` (FK)

> `sales_returns.order_id` sudah diubah oleh Order migration.
> `sales_returns.location_id` sudah diubah oleh Warehouse migration.
> `sales_return_items.item_id` sudah `uuid()` di migration asal.

**Step 3 — Re-add FK:**
- `sales_return_items.sales_return_id` → `sales_returns.id` cascadeOnDelete

### Models — Tambah `HasUuid7`:
- `Modules/Sales/app/Models/SalesReturn.php`
- `Modules/Sales/app/Models/SalesReturnItem.php`

---

## Phase 6: Warranty Module (tabel baru)

### Migration: `2026_06_07_000500_create_warranties_table.php`

```php
$table->string('id', 32)->primary();  // UUIDv7, no auto-increment
$table->string('product_variant_id', 32);
$table->string('order_id', 32)->nullable();
$table->string('serial_no', 100)->nullable();
$table->integer('duration_months');
$table->date('start_date');
$table->date('end_date');
$table->enum('status', ['ACTIVE', 'EXPIRED', 'VOIDED'])->default('ACTIVE');
$table->text('notes')->nullable();
$table->timestamps();

$table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
$table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
```

### Migration: `2026_06_07_000501_create_warranty_claims_table.php`

```php
$table->string('id', 32)->primary();
$table->string('warranty_id', 32);
$table->string('claim_number', 100)->unique();
$table->text('reason');
$table->enum('status', ['OPEN', 'IN_PROGRESS', 'APPROVED', 'REJECTED', 'RESOLVED'])->default('OPEN');
$table->text('resolution')->nullable();
$table->string('claimed_by', 100);
$table->dateTime('claimed_at');
$table->dateTime('resolved_at')->nullable();
$table->timestamps();

$table->foreign('warranty_id')->references('id')->on('warranties')->cascadeOnDelete();
```

### Models:
- `Modules/Warranty/app/Models/Warranty.php` — dengan `HasUuid7`
- `Modules/Warranty/app/Models/WarrantyClaim.php` — dengan `HasUuid7`

---

## Phase 7: Validation Rules — `'integer'` → `'string'`

| File | Field | Sekarang | Harus |
|---|---|---|---|
| `Inbound/StoreInboundRequest.php:20` | `location_id` | `'integer'` | `'string'` |
| `Inbound/AutoPutawayRequest.php:17` | `location_id` | `'integer'` | `'string'` |
| `Inbound/StoreInboundRequest.php:24` | `source_id` | `'integer'` | hapus (nullable only) |
| `Inventory/TransferOutRequest.php:17` | `source_location_id` | `'integer'` | `'string'` |
| `Inventory/TransferOutRequest.php:18` | `destination_location_id` | `'integer'` | `'string'` |
| `Purchase/StorePurchaseOrderRequest.php:18` | `supplier_id` | `'integer'` | `'string'` |
| `Purchase/StorePurchaseOrderRequest.php:19` | `location_id` | `'integer'` | `'string'` |
| `Purchase/ReceivePurchaseOrderRequest.php:19` | `purchase_order_item_id` | `'integer'` | `'string'` |
| `Sales/StoreSalesReturnRequest.php:17` | `order_id` | `'integer'` | `'string'` |
| `Sales/StoreSalesReturnRequest.php:18` | `location_id` | `'integer'` | `'string'` |
| `Inbound/AssignInboundRequest.php:17` | `assigned_to` | `'integer'` | tetap (users.id masih int) |

---

## Phase 8: OpenAPI Annotations — `type: 'integer'` → `type: 'string'`

Update semua properti dan parameter yang mereferensikan kolom UUID.

### Inventory Controllers
- `InventoryController.php:21` — `id` property
- `InventoryController.php:23` — `location_id` property
- `InventoryController.php:75` — `itemId` path param
- `InventoryTransactionController.php:20,35,51,53` — `location_id`, `source_location_id`, `destination_location_id`
- `InventoryTransactionController.php:143,206` — transfer `id` path params

### Inbound Controller
- `InboundController.php:26,31,44,48` — `location_id`, `source_id`
- `InboundController.php:208` — `filter[location_id]` param

### Supplier Controller
- `SupplierController.php:84,108,139` — `id` path params

### Purchase Controller
- `PurchaseOrderController.php:28` — `filter[supplier_id]` param
- `PurchaseOrderController.php:69,131,154,190,213` — `id` path params
- `PurchaseOrderController.php:95` — `supplier_id` property
- `PurchaseOrderController.php:96` — `location_id` property
- `PurchaseOrderController.php:163` — `purchase_order_item_id` property

### Sales Controller
- `SalesReturnController.php:67,130,161,196` — `id` path params
- `SalesReturnController.php:93` — `order_id` property
- `SalesReturnController.php:94` — `location_id` property

### Order (sudah UUID tapi OpenAPI belum update)
- `OrderController.php:20` — `id` property
- `OrderController.php:187,227,271` — `order` path params
- `OrderItemResource.php:14,15` — `id`, `item_id` properties
- `OrderResource.php:14,17` — `id`, `channel_shop_id` properties

---

## Urutan Eksekusi

```
Phase 1: Fix traits (HasUuids → HasUuid7) + fix Str::orderedUuid/insertGetId
Phase 2: Migration Inventory     → timestamp 000100
Phase 3: Migration Supplier      → timestamp 000200
Phase 4: Migration Purchase      → timestamp 000300
Phase 5: Migration Sales         → timestamp 000400
Phase 6: Migration Warranty      → timestamp 000500, 000501
Phase 7: Validation rules
Phase 8: OpenAPI annotations
```

Phase 1 harus duluan karena fix trait tidak butuh migration. Phase 2-5 bisa dalam urutan apapun karena FK antar modul sudah resolved oleh migration sebelumnya (Warehouse, Channel, Order). Phase 6 independent. Phase 7-8 bisa paralel.

---

## Verifikasi

1. `php artisan migrate` — semua migration jalan tanpa error
2. `php artisan tinker` — buat record di tiap tabel, cek ID = 32 char hex
3. Hit API endpoint create/list/show — ID di response berupa string 32 char
4. Test FK: delete parent → cascade/restrict behavior benar
5. Test validation: kirim integer ID → ditolak, kirim string UUID → diterima
6. `php artisan l5-swagger:generate` — OpenAPI docs regenerate tanpa error
