# Plan: WMS Enhancement — Inventory, Adjustment, Reserved Stock, Putaway, Catalog, Queue

## Context

Project WMS berbasis Laravel 12 + nwidart/laravel-modules + PostgreSQL. Sudah ada modul Inventory, Inbound, Warehouse, Purchase, Order, Product. Perlu ditambahkan fitur-fitur WMS baru mengikuti flow Jubelio WMS sebagai referensi, namun sebagai API independen.

### Yang Sudah Ada

| Fitur | Status | Lokasi |
|---|---|---|
| Inventory CRUD (on_hand, on_order, reserved, available) | ✓ Ada | `Modules/Inventory` |
| Inventory Movement (audit trail) | ✓ Ada | `Modules/Inventory` |
| Inventory Transfer (antar warehouse) | ✓ Ada | `Modules/Inventory` |
| Inbound + Putaway (manual, auto, QR scan) | ✓ Ada | `Modules/Inbound` |
| Worker Assignment (inbound) | ✓ Ada | `Modules/Inbound` |
| Location + Zone + Bin | ✓ Ada | `Modules/Warehouse` |
| Purchase Order + Receive | ✓ Ada | `Modules/Purchase` |
| Order + Stock Reserve/Pick/Ship/Cancel | ✓ Ada | `Modules/Order` (Redis lock per SKU) |
| Stock Adjustment (dokumen) | ✗ Belum | Hanya mutasi langsung |
| Reserved Stock (dokumen) | ✗ Belum | Hanya kolom di inventories |
| Standalone Putaway | ✗ Belum | Hanya via Inbound |
| Item Catalog Merge | ✗ Belum | — |
| Redis Job Queue untuk semua mutasi stok | ✗ Partial | Hanya Order (SyncStockJob) |

### Pola yang Harus Diikuti

- **Service Repository Pattern** — seperti Inbound, Purchase module
- **HasUuid7** trait untuk semua model baru — PK `VARCHAR(32)`
- **API Response format:** `{ status, message, data, meta }`
- **Auth:** `auth:sanctum` middleware
- **Validation:** Laravel Form Request
- **OpenAPI:** Annotations di controller
- **Pagination:** `page` + `pageSize` (max 200), via Spatie QueryBuilder

---

## Phase 1: Tabel Baru — Migration

### 1.1 `stock_adjustments` (Dokumen Penyesuaian Stok)

```
Modules/Inventory/database/migrations/2026_06_07_100000_create_stock_adjustments_table.php
```

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `string('id', 32)->primary()` | UUIDv7 |
| `adjustment_no` | `string(50)->unique()` | Auto-generate: `ADJ-YYYYMMDD-XXXX` |
| `transaction_date` | `dateTime` | |
| `location_id` | `string(32)` FK → `locations.id` | restrictOnDelete |
| `status` | `enum: DRAFT, APPROVED, CANCELLED` | default DRAFT |
| `is_beginning_balance` | `boolean` | default false |
| `notes` | `text->nullable()` | |
| `created_by` | `string(100)` | |
| `approved_by` | `string(100)->nullable()` | |
| `approved_at` | `dateTime->nullable()` | |
| `timestamps` | | |
| `softDeletes` | | |

Index: `(location_id)`, `(status)`, `(transaction_date)`

### 1.2 `stock_adjustment_items`

```
Modules/Inventory/database/migrations/2026_06_07_100001_create_stock_adjustment_items_table.php
```

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `string('id', 32)->primary()` | UUIDv7 |
| `stock_adjustment_id` | `string(32)` FK → `stock_adjustments.id` | cascadeOnDelete |
| `item_id` | `string(32)` FK → `product_variants.id` | restrictOnDelete |
| `bin_id` | `string(32)->nullable()` FK → `location_bins.id` | nullOnDelete |
| `batch_no` | `string(100)->nullable()` | |
| `serial_no` | `string(100)->nullable()` | |
| `system_qty` | `integer` | Stok sistem saat adjustment dibuat |
| `actual_qty` | `integer` | Stok fisik aktual |
| `difference_qty` | `integer` | `actual_qty - system_qty` (computed) |
| `notes` | `text->nullable()` | |
| `timestamps` | | |

Index: `(item_id)`, `(stock_adjustment_id)`

### 1.3 `reserved_stocks` (Dokumen Stok Cadangan)

```
Modules/Inventory/database/migrations/2026_06_07_100002_create_reserved_stocks_table.php
```

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `string('id', 32)->primary()` | UUIDv7 |
| `reserved_stock_no` | `string(50)->unique()` | Auto-generate: `RSV-YYYYMMDD-XXXX` |
| `location_id` | `string(32)` FK → `locations.id` | restrictOnDelete |
| `start_date` | `dateTime` | |
| `end_date` | `dateTime` | |
| `status` | `enum: ACTIVE, EXPIRED, CANCELLED` | default ACTIVE |
| `is_active` | `boolean` | default true |
| `notes` | `text->nullable()` | |
| `created_by` | `string(100)` | |
| `timestamps` | | |
| `softDeletes` | | |

Index: `(location_id)`, `(status)`, `(end_date)`

### 1.4 `reserved_stock_items`

```
Modules/Inventory/database/migrations/2026_06_07_100003_create_reserved_stock_items_table.php
```

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `string('id', 32)->primary()` | UUIDv7 |
| `reserved_stock_id` | `string(32)` FK → `reserved_stocks.id` | cascadeOnDelete |
| `item_id` | `string(32)` FK → `product_variants.id` | restrictOnDelete |
| `bin_id` | `string(32)->nullable()` FK → `location_bins.id` | nullOnDelete |
| `qty` | `integer` | Jumlah yang di-reserve |
| `timestamps` | | |

Index: `(item_id)`, `(reserved_stock_id)`

### 1.5 `putaways` (Standalone Putaway)

```
Modules/Inventory/database/migrations/2026_06_07_100004_create_putaways_table.php
```

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `string('id', 32)->primary()` | UUIDv7 |
| `putaway_no` | `string(50)->unique()` | Auto-generate: `PUT-YYYYMMDD-XXXX` |
| `location_id` | `string(32)` FK → `locations.id` | restrictOnDelete |
| `source_type` | `string(30)` | INBOUND, ADJUSTMENT, MANUAL |
| `source_id` | `string(32)->nullable()` | Polymorphic ref |
| `status` | `enum: NOT_STARTED, IN_PROGRESS, COMPLETED, CANCELLED` | default NOT_STARTED |
| `assigned_to` | `unsignedBigInteger->nullable()` FK → `users.id` | |
| `assigned_by` | `string(100)->nullable()` | |
| `started_at` | `dateTime->nullable()` | |
| `completed_at` | `dateTime->nullable()` | |
| `notes` | `text->nullable()` | |
| `created_by` | `string(100)` | |
| `timestamps` | | |

Index: `(status)`, `(assigned_to, status)`, `(location_id)`

### 1.6 `putaway_items`

```
Modules/Inventory/database/migrations/2026_06_07_100005_create_putaway_items_table.php
```

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `string('id', 32)->primary()` | UUIDv7 |
| `putaway_id` | `string(32)` FK → `putaways.id` | cascadeOnDelete |
| `item_id` | `string(32)` FK → `product_variants.id` | restrictOnDelete |
| `source_bin_id` | `string(32)` FK → `location_bins.id` | restrictOnDelete |
| `destination_bin_id` | `string(32)->nullable()` FK → `location_bins.id` | nullOnDelete |
| `qty` | `integer` | Jumlah yang harus di-putaway |
| `putaway_qty` | `integer` default 0 | Jumlah yang sudah di-putaway |
| `batch_no` | `string(100)->nullable()` | |
| `serial_no` | `string(100)->nullable()` | |
| `timestamps` | | |

Index: `(putaway_id)`, `(item_id)`

---

## Phase 2: Model

Semua model baru di `Modules/Inventory/app/Models/` dengan `HasUuid7` trait.

### Model List

| Model | Relasi |
|---|---|
| `StockAdjustment` | `hasMany(StockAdjustmentItem)`, `belongsTo(Location)` |
| `StockAdjustmentItem` | `belongsTo(StockAdjustment)`, `belongsTo(ProductVariant, 'item_id')`, `belongsTo(LocationBin, 'bin_id')` |
| `ReservedStock` | `hasMany(ReservedStockItem)`, `belongsTo(Location)` |
| `ReservedStockItem` | `belongsTo(ReservedStock)`, `belongsTo(ProductVariant, 'item_id')`, `belongsTo(LocationBin, 'bin_id')` |
| `Putaway` | `hasMany(PutawayItem)`, `belongsTo(Location)` |
| `PutawayItem` | `belongsTo(Putaway)`, `belongsTo(ProductVariant, 'item_id')`, `belongsTo(LocationBin, 'source_bin_id')`, `belongsTo(LocationBin, 'destination_bin_id')` |

---

## Phase 3: Repository

Buat di `Modules/Inventory/app/Repositories/`:

| Repository | Key Methods |
|---|---|
| `StockAdjustmentRepository` | `getAllPaginated()`, `findById(string)`, `findByIdForUpdate(string)`, `create(array)`, `updateStatus(string, string)`, `delete(string)` |
| `ReservedStockRepository` | `getAllPaginated()`, `findById(string)`, `findByIdForUpdate(string)`, `create(array)`, `getExpired()`, `deactivate(string)` |
| `PutawayRepository` | `getAllPaginated()`, `getByStatus(string)`, `findById(string)`, `findByIdForUpdate(string)`, `create(array)`, `updateStatus()`, `getByAssignee(int)` |

Pola query: pakai Spatie `QueryBuilder` + `AllowedFilter` + `AllowedSort` (konsisten dengan `InventoryRepository`).

---

## Phase 4: Service

### 4.1 `StockAdjustmentService`

```
Modules/Inventory/app/Services/StockAdjustmentService.php
```

| Method | Logic |
|---|---|
| `getAllPaginated()` | List adjustment dengan filter status, location, q (search adjustment_no) |
| `getById(string $id)` | Detail + items + relasi |
| `create(array $data)` | Buat dokumen DRAFT + items. Hitung `system_qty` dari current inventory. Hitung `difference_qty` |
| `approve(string $id, string $approvedBy)` | DRAFT → APPROVED. Untuk setiap item: mutasi `inventories.on_hand` += `difference_qty`, catat `inventory_movements` (source=ADJUSTMENT) |
| `cancel(string $id)` | Hanya jika DRAFT |
| `delete(string $id)` | Hanya jika DRAFT. Soft delete |

Auto-generate `adjustment_no`: `ADJ-{YYYYMMDD}-{4 digit seq}`.

### 4.2 `ReservedStockService`

```
Modules/Inventory/app/Services/ReservedStockService.php
```

| Method | Logic |
|---|---|
| `getAllPaginated()` | List dengan filter status, location, q |
| `getById(string $id)` | Detail + items |
| `create(array $data)` | Buat dokumen + items. Untuk setiap item: `inventories.reserved` += qty, recalculate `available`. Catat `inventory_movements` (source=RESERVE) |
| `cancel(string $id)` | ACTIVE → CANCELLED. Rollback: `inventories.reserved` -= qty. Catat movement |
| `releaseExpired()` | Cari semua `end_date < now() AND status = ACTIVE`. Set EXPIRED + rollback reserved. Bisa dipanggil dari scheduler |

Auto-generate `reserved_stock_no`: `RSV-{YYYYMMDD}-{4 digit seq}`.

### 4.3 `PutawayService`

```
Modules/Inventory/app/Services/PutawayService.php
```

| Method | Logic |
|---|---|
| `getAllPaginated()` | List semua putaway |
| `getByStatus(string $status)` | Filter: NOT_STARTED, IN_PROGRESS, COMPLETED |
| `getById(string $id)` | Detail + items |
| `getItems(string $putawayId)` | List items yang harus di-putaway |
| `assignStaff(array $data)` | Set `assigned_to`, `assigned_by`. Status tetap NOT_STARTED sampai staff mulai |
| `start(string $id)` | NOT_STARTED → IN_PROGRESS. Set `started_at` |
| `processItem(string $putawayId, string $itemId, array $data)` | Proses putaway per item: move stok dari source_bin ke destination_bin. Update `putaway_qty`. Catat inventory_movements (PUTAWAY_OUT + PUTAWAY_IN) |
| `complete(string $id)` | IN_PROGRESS → COMPLETED jika semua item `putaway_qty == qty`. Set `completed_at` |

Auto-generate `putaway_no`: `PUT-{YYYYMMDD}-{4 digit seq}`.

Standalone putaway tercipta dari:
- Inbound completion → auto-create Putaway dokumen
- Manual creation oleh user
- Stock adjustment → opsional

---

## Phase 5: Trait StockLockable + Jobs

### 5.1 Trait `StockLockable`

```
app/Traits/StockLockable.php
```

```php
trait StockLockable
{
    protected function withStockLock(string $itemId, string $locationId, callable $callback, int $ttl = 10)
    {
        $lockKey = "stock_lock:{$itemId}:{$locationId}";
        $lock = Cache::lock($lockKey, $ttl);

        if ($lock->get()) {
            try {
                return $callback();
            } finally {
                $lock->release();
            }
        }

        throw new \RuntimeException("Gagal mendapatkan lock stok untuk item {$itemId} di lokasi {$locationId}.");
    }
}
```

> Note: ID sudah UUID (string), bukan int. Konsisten dengan `HasUuid7`.

### 5.2 Jobs

Buat di `Modules/Inventory/app/Jobs/`:

| Job | Queue | Logic |
|---|---|---|
| `ProcessStockAdjustmentJob` | `stock-critical` | Loop items → `withStockLock()` → mutasi inventory + movement |
| `ProcessReservedStockJob` | `stock-critical` | Loop items → `withStockLock()` → tambah reserved + movement |
| `ReleaseExpiredReservationsJob` | `stock-default` | Cari expired → rollback reserved. Schedulable |
| `ProcessPutawayItemJob` | `stock-default` | Per item → `withStockLock()` → pindah bin + movement |

Semua Job: `$tries = 3`, `$backoff = [3, 10, 30]`, implements `ShouldQueue`.

### 5.3 Horizon Config Update

Tambah supervisor `stock` di `config/horizon.php`:

```php
'supervisor-stock' => [
    'connection' => 'redis',
    'queue' => ['stock-critical', 'stock-default'],
    'balance' => 'simple',
    'processes' => 5,
    'tries' => 3,
    'timeout' => 60,
],
```

### 5.4 Scheduler

Di `Modules/Inventory/app/Providers/InventoryServiceProvider.php`, tambah schedule:

```php
$schedule->job(new ReleaseExpiredReservationsJob)->hourly();
```

---

## Phase 6: Form Request

Buat di `Modules/Inventory/app/Http/Requests/`:

### `StoreStockAdjustmentRequest`
```
transaction_date: required|date
location_id: required|string|exists:locations,id
is_beginning_balance: boolean
notes: nullable|string
items: required|array|min:1
items.*.item_id: required|string|exists:product_variants,id
items.*.bin_id: nullable|string|exists:location_bins,id
items.*.actual_qty: required|integer|min:0
items.*.batch_no: nullable|string|max:100
items.*.serial_no: nullable|string|max:100
items.*.notes: nullable|string
```

### `StoreReservedStockRequest`
```
start_date: required|date
end_date: required|date|after:start_date
location_id: required|string|exists:locations,id
is_active: boolean
notes: nullable|string
items: required|array|min:1
items.*.item_id: required|string|exists:product_variants,id
items.*.bin_id: nullable|string|exists:location_bins,id
items.*.qty: required|integer|min:1
```

### `AssignPutawayStaffRequest`
```
data: required|array|min:1
data.*.putaway_id: required|string|exists:putaways,id
data.*.assigned_to: required|integer|exists:users,id
performed_by: required|string|max:100
```

### `ProcessPutawayItemRequest`
```
destination_bin_id: required|string|exists:location_bins,id
qty: required|integer|min:1
```

---

## Phase 7: Controller + Routes

### 7.1 `StockAdjustmentController`

```
Modules/Inventory/app/Http/Controllers/StockAdjustmentController.php
```

| Endpoint | Method | Response | Notes |
|---|---|---|---|
| `GET /inventory/adjustments` | `index()` | 200 + paginated | Filter: q, status, location_id, sort: transaction_date |
| `GET /inventory/adjustments/{id}` | `show(string $id)` | 200 | Detail + items |
| `POST /inventory/adjustments` | `store(StoreStockAdjustmentRequest)` | 202 | Dispatch `ProcessStockAdjustmentJob` |
| `POST /inventory/adjustments/{id}/approve` | `approve(string $id)` | 202 | Dispatch approval job |
| `POST /inventory/adjustments/{id}/cancel` | `cancel(string $id)` | 200 | Sync (no stock mutation) |
| `DELETE /inventory/adjustments/{id}` | `destroy(string $id)` | 200 | Soft delete, hanya DRAFT |

### 7.2 `ReservedStockController`

```
Modules/Inventory/app/Http/Controllers/ReservedStockController.php
```

| Endpoint | Method | Response |
|---|---|---|
| `GET /inventory/reserved-stocks` | `index()` | 200 + paginated |
| `GET /inventory/reserved-stocks/{id}` | `show(string $id)` | 200 |
| `POST /inventory/reserved-stocks` | `store(StoreReservedStockRequest)` | 202 |
| `POST /inventory/reserved-stocks/{id}/cancel` | `cancel(string $id)` | 200 |

### 7.3 `PutawayController`

```
Modules/Inventory/app/Http/Controllers/PutawayController.php
```

| Endpoint | Method | Response |
|---|---|---|
| `GET /putaway` | `index()` | 200 + paginated |
| `GET /putaway/not-started` | `notStarted()` | 200 + paginated |
| `GET /putaway/in-progress` | `inProgress()` | 200 + paginated |
| `GET /putaway/completed` | `completed()` | 200 + paginated |
| `GET /putaway/{id}` | `show(string $id)` | 200 |
| `GET /putaway/{id}/items` | `items(string $id)` | 200 + paginated |
| `POST /putaway/assign-staff` | `assignStaff(AssignPutawayStaffRequest)` | 200 |
| `POST /putaway/{id}/start` | `start(string $id)` | 200 |
| `POST /putaway/{id}/items/{itemId}/process` | `processItem(string $id, string $itemId, ProcessPutawayItemRequest)` | 202 |
| `POST /putaway/{id}/complete` | `complete(string $id)` | 200 |

### 7.4 Inventory Endpoints Enhancement

Tambah ke `InventoryController` yang sudah ada:

| Endpoint | Method | Keterangan |
|---|---|---|
| `GET /inventory/items/to-stock` | `itemsToStock()` | List item_id yang bisa di-stock (product variants dengan inventory) |
| `GET /inventory/stock-products` | `stockProducts()` | Semua stok produk (grouped by product, dengan variant detail) |
| `GET /inventory/history` | `history()` | Riwayat movement, filter: itemId, dateRange |
| `GET /inventory/items/by-location/{locationId}` | `byLocation(string $locationId)` | Stok per lokasi |
| `GET /inventory/purchase-order/items` | `purchaseOrderItems()` | Item dalam PO yang belum fully received |

### 7.5 Item Catalog Merge

Tambah ke `ProductController` atau buat `ItemCatalogController`:

| Endpoint | Method | Keterangan |
|---|---|---|
| `POST /inventory/items/merge` | `merge()` | Gabungkan item — reassign item_id di inventories, movements, dll |

---

## Phase 8: Routes Registration

Update `Modules/Inventory/routes/api.php`:

```php
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    // Existing routes...

    // Stock Adjustment
    Route::prefix('inventory/adjustments')->group(function () {
        Route::get('/', [StockAdjustmentController::class, 'index']);
        Route::post('/', [StockAdjustmentController::class, 'store']);
        Route::get('/{id}', [StockAdjustmentController::class, 'show']);
        Route::post('/{id}/approve', [StockAdjustmentController::class, 'approve']);
        Route::post('/{id}/cancel', [StockAdjustmentController::class, 'cancel']);
        Route::delete('/{id}', [StockAdjustmentController::class, 'destroy']);
    });

    // Reserved Stock
    Route::prefix('inventory/reserved-stocks')->group(function () {
        Route::get('/', [ReservedStockController::class, 'index']);
        Route::post('/', [ReservedStockController::class, 'store']);
        Route::get('/{id}', [ReservedStockController::class, 'show']);
        Route::post('/{id}/cancel', [ReservedStockController::class, 'cancel']);
    });

    // Inventory enhancements
    Route::get('inventory/items/to-stock', [...]);
    Route::get('inventory/stock-products', [...]);
    Route::get('inventory/history', [...]);
    Route::get('inventory/items/by-location/{locationId}', [...]);
    Route::get('inventory/purchase-order/items', [...]);
    Route::post('inventory/items/merge', [...]);

    // Standalone Putaway
    Route::prefix('putaway')->group(function () {
        Route::get('/', [PutawayController::class, 'index']);
        Route::get('/not-started', [PutawayController::class, 'notStarted']);
        Route::get('/in-progress', [PutawayController::class, 'inProgress']);
        Route::get('/completed', [PutawayController::class, 'completed']);
        Route::get('/{id}', [PutawayController::class, 'show']);
        Route::get('/{id}/items', [PutawayController::class, 'items']);
        Route::post('/assign-staff', [PutawayController::class, 'assignStaff']);
        Route::post('/{id}/start', [PutawayController::class, 'start']);
        Route::post('/{id}/items/{itemId}/process', [PutawayController::class, 'processItem']);
        Route::post('/{id}/complete', [PutawayController::class, 'complete']);
    });
});
```

---

## Urutan Implementasi

```
1. Migration (6 tabel baru)
2. Model (6 model baru)
3. Trait StockLockable
4. Repository (3 repo baru)
5. Service (3 service baru)
6. Jobs (4 job baru)
7. Form Request (3 request baru)
8. Controller (3 controller baru + enhance existing)
9. Routes
10. Horizon config
11. Scheduler (expired reservations)
```

---

## File Baru yang Akan Dibuat

```
Modules/Inventory/
├── database/migrations/
│   ├── 2026_06_07_100000_create_stock_adjustments_table.php
│   ├── 2026_06_07_100001_create_stock_adjustment_items_table.php
│   ├── 2026_06_07_100002_create_reserved_stocks_table.php
│   ├── 2026_06_07_100003_create_reserved_stock_items_table.php
│   ├── 2026_06_07_100004_create_putaways_table.php
│   └── 2026_06_07_100005_create_putaway_items_table.php
├── app/Models/
│   ├── StockAdjustment.php
│   ├── StockAdjustmentItem.php
│   ├── ReservedStock.php
│   ├── ReservedStockItem.php
│   ├── Putaway.php
│   └── PutawayItem.php
├── app/Repositories/
│   ├── StockAdjustmentRepository.php
│   ├── ReservedStockRepository.php
│   └── PutawayRepository.php
├── app/Services/
│   ├── StockAdjustmentService.php
│   ├── ReservedStockService.php
│   └── PutawayService.php
├── app/Http/Controllers/
│   ├── StockAdjustmentController.php
│   ├── ReservedStockController.php
│   └── PutawayController.php
├── app/Http/Requests/
│   ├── StoreStockAdjustmentRequest.php
│   ├── StoreReservedStockRequest.php
│   ├── AssignPutawayStaffRequest.php
│   └── ProcessPutawayItemRequest.php
└── app/Jobs/
    ├── ProcessStockAdjustmentJob.php
    ├── ProcessReservedStockJob.php
    ├── ReleaseExpiredReservationsJob.php
    └── ProcessPutawayItemJob.php

app/Traits/
└── StockLockable.php
```

Total: **6 migration, 6 model, 3 repository, 3 service, 3 controller, 4 request, 4 job, 1 trait** = **30 file baru**

---

## File yang Dimodifikasi

| File | Perubahan |
|---|---|
| `Modules/Inventory/routes/api.php` | Tambah routes baru |
| `Modules/Inventory/app/Http/Controllers/InventoryController.php` | Tambah method `itemsToStock`, `stockProducts`, `history`, `byLocation`, `purchaseOrderItems` |
| `config/horizon.php` | Tambah supervisor-stock |
| `Modules/Inventory/app/Providers/InventoryServiceProvider.php` | Tambah scheduler |

---

## Verifikasi

1. `php artisan migrate` — 6 tabel baru terbuat
2. **Stock Adjustment flow:** create draft → approve → inventory berubah + movement tercatat
3. **Reserved Stock flow:** create → reserved naik, available turun → cancel → rollback → expire → auto rollback
4. **Putaway flow:** create → assign staff → start → process items → complete → inventory berpindah bin
5. **Concurrency test:** 2 request simultan ke item sama → hanya 1 yang dapat lock, yang lain retry
6. **Queue:** `php artisan horizon` → job terproses di queue `stock-critical` / `stock-default`
7. **Scheduler:** `php artisan schedule:run` → expired reservations ter-release
