# UUID Migration Plan — Remaining Tasks

## Context

Proyek ini sedang migrasi primary key dari integer auto-increment ke UUID. Tabel `products`, `product_variants`, `inbounds`, `inbound_items`, dan `location_bins` sudah selesai. Dokumen ini berisi sisa pekerjaan yang belum dikerjakan.

**Tech Stack:** Laravel 12 / PHP 8.5, nwidart/laravel-modules, SQLite untuk testing.

**Konvensi UUID yang sudah diterapkan:**
- Migration: `$table->uuid('id')->primary()` untuk PK, `$table->uuid('column_name')` + explicit `$table->foreign()->references()->on()` untuk FK
- Model: tambah `use HasUuids;` trait dari `Illuminate\Database\Eloquent\Concerns\HasUuids`
- Service/Repository: parameter type hint `int $id` → `string $id`
- Request validator: `'integer'` → `'uuid'`, `'exists:old_table,id'` → `'exists:correct_table,id'`
- Swagger: `type: 'integer'` → `type: 'string', format: 'uuid'`
- Jangan pakai `$table->enum()`, gunakan `$table->string('column', 30)` untuk kompatibilitas SQLite test

---

## Phase 1: Fix `item_id` FK — Inventory Tables Harus Reference `product_variants`

### Masalah
Semua Eloquent model inventory (`Inventory`, `InventoryMovement`, `InventoryTransferItem`, `InboundItem`) menggunakan `belongsTo(ProductVariant::class, 'item_id')`. Tapi FK constraint di migration masih reference ke tabel `products`. Ini bug lama yang tertutupi karena dulu integer auto-increment product dan variant kebetulan sama-sama ID = 1.

### File yang harus diubah

#### 1.1 Migration FK `products` → `product_variants`

| File | Baris yang dicari | Ubah jadi |
|------|-------------------|-----------|
| `Modules/Inventory/database/migrations/2026_06_03_000004_create_inventories_table.php` | `->on('products')` | `->on('product_variants')` |
| `Modules/Inventory/database/migrations/2026_06_03_000005_create_inventory_movements_table.php` | `->on('products')` | `->on('product_variants')` |
| `Modules/Inventory/database/migrations/2026_06_06_120200_rebuild_inventory_transfers_table.php` | `->on('products')` (di bagian `inventory_transfer_items`) | `->on('product_variants')` |

> **JANGAN ubah** `purchase_order_items.item_id` dan `sales_return_items.item_id` — mereka memang reference ke `products` (sesuai model `belongsTo(Product::class)`).

#### 1.2 Request Validator `exists:products,id` → `exists:product_variants,id`

| File | Rule yang dicari | Ubah jadi |
|------|------------------|-----------|
| `Modules/Inventory/app/Http/Requests/AdjustStockRequest.php` | `'exists:products,id'` | `'exists:product_variants,id'` |
| `Modules/Inventory/app/Http/Requests/PutawayStockRequest.php` | `'exists:products,id'` | `'exists:product_variants,id'` |
| `Modules/Inventory/app/Http/Requests/TransferStockRequest.php` | `'exists:products,id'` | `'exists:product_variants,id'` |
| `Modules/Inventory/app/Http/Requests/TransferOutRequest.php` | `'exists:products,id'` | `'exists:product_variants,id'` |

---

## Phase 2: Fix Semua Test — `item_id` Harus Pakai Variant ID

### Masalah
Test membuat `Inventory` dengan `'item_id' => $this->product->id` (Product UUID), tapi flow order/inbound meresolve `item_id` dari `product_variants` (Variant UUID). Sekarang UUID-nya beda, jadi lookup `Inventory::where('item_id', $variantUUID)` gagal 404.

### 2.1 `tests/Feature/Order/OrderLifecycleTest.php`

1. Di method `seedWarehouseAndStock()`, ubah:
   ```php
   // SEBELUM
   'item_id' => $this->product->id,
   // SESUDAH
   'item_id' => $this->variant->id,
   ```

2. Di method `test_create_order_records_inventory_movement()`, ubah assertion:
   ```php
   // SEBELUM
   'item_id' => $this->product->id,
   // SESUDAH
   'item_id' => $this->variant->id,
   ```

3. Di method `test_multi_item_order_reserves_stock_per_line()`, ubah:
   ```php
   // SEBELUM
   'item_id' => $product2->id,
   // SESUDAH
   'item_id' => $variant2->id,
   ```

### 2.2 `tests/Feature/Inbound/InboundE2ETest.php`

1. Tambah property di class:
   ```php
   private ProductVariant $variant1;
   private ProductVariant $variant2;
   private ProductVariant $variant3;
   ```

2. Di `seedTestData()`, simpan variant ke property:
   ```php
   // SEBELUM
   ProductVariant::create([...]);
   // SESUDAH
   $this->variant1 = ProductVariant::create([...]);
   // (sama untuk variant2 dan variant3)
   ```

3. Ganti SEMUA `$this->product1->id` → `$this->variant1->id` di mana dipakai sebagai `item_id` (untuk inventory, inbound items, inventory where clause). Pattern yang harus di-replace:
   - `'item_id' => $this->product1->id` → `'item_id' => $this->variant1->id`
   - `'item_id' => $this->product2->id` → `'item_id' => $this->variant2->id`
   - `'item_id' => $this->product3->id` → `'item_id' => $this->variant3->id`
   - `->where('item_id', $this->product1->id)` → `->where('item_id', $this->variant1->id)`

   > **JANGAN ubah** `$this->product1->id` yang dipakai di konteks PO items (`purchase/orders` endpoint) — PO `item_id` memang reference ke `products`.
   >
   > **KECUALI** jika PO item tersebut kemudian diproses menjadi inbound item — maka inbound item harus pakai variant ID. Cek flow `PurchaseOrderService::receive()` untuk memastikan.

4. Di `seedTestData()`, ubah loop inventory creation:
   ```php
   // SEBELUM
   foreach ([$this->product1, $this->product2] as $p) {
       Inventory::create(['item_id' => $p->id, ...]);
   }
   // SESUDAH
   foreach ([$this->variant1, $this->variant2] as $v) {
       Inventory::create(['item_id' => $v->id, ...]);
   }
   ```

### 2.3 `tests/Feature/Inbound/InboundScanFlowTest.php`

1. Tambah property:
   ```php
   private ProductVariant $variant;
   ```

2. Di `seedTestData()`:
   ```php
   // SEBELUM
   ProductVariant::create([...]);
   // SESUDAH
   $this->variant = ProductVariant::create([...]);
   ```

3. Ganti semua `$this->product->id` → `$this->variant->id` di mana dipakai sebagai `item_id`.

---

## Phase 3: Swagger Schema — `item_id` Type Update

### File yang harus diubah

| File | Yang dicari | Ubah jadi |
|------|-------------|-----------|
| `Modules/Sales/app/Http/Controllers/SalesReturnController.php` | `property: 'item_id', type: 'integer'` | `property: 'item_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'` |
| `Modules/Outbound/app/Http/Controllers/OutboundController.php` | `property: 'item_id', type: 'integer'` | `property: 'item_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'` |

---

## Phase 4: `inbound_receipts` dan `inbound_assignments` — UUID PK (Optional)

Kedua tabel ini internal (tidak di-reference sebagai FK dari module lain). Tapi untuk konsistensi, ubah ke UUID.

### 4.1 Migration `inbound_receipts`

File: `Modules/Inbound/database/migrations/2026_06_03_110842_create_inbound_receipts_table.php`

```php
// SEBELUM
$table->id();
// SESUDAH
$table->uuid('id')->primary();
```

### 4.2 Migration `inbound_assignments`

File: `Modules/Inbound/database/migrations/2026_06_06_200000_add_qr_and_assignments_to_inbound.php`

```php
// SEBELUM (di Schema::create inbound_assignments)
$table->id();
// SESUDAH
$table->uuid('id')->primary();
```

### 4.3 Model `InboundReceipt`

File: `Modules/Inbound/app/Models/InboundReceipt.php`

Tambah:
```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InboundReceipt extends Model
{
    use HasUuids;
    // ... sisanya tetap
}
```

### 4.4 Model `InboundAssignment`

File: `Modules/Inbound/app/Models/InboundAssignment.php`

Tambah:
```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InboundAssignment extends Model
{
    use HasUuids;
    // ... sisanya tetap
}
```

### 4.5 Service Parameter Type Hints

File: `Modules/Inbound/app/Services/InboundService.php`

Ubah parameter yang reference assignment ID:
```php
// SEBELUM
public function startAssignment(int $assignmentId, int $userId): InboundAssignment
public function completeAssignment(int $assignmentId, int $userId): InboundAssignment
// SESUDAH
public function startAssignment(string $assignmentId, int $userId): InboundAssignment
public function completeAssignment(string $assignmentId, int $userId): InboundAssignment
```

> `$userId` tetap `int` karena tabel `users` masih pakai integer PK.

### 4.6 Swagger `InboundAssignment` Schema

File: `Modules/Inbound/app/Http/Controllers/InboundController.php`

Di schema `InboundAssignment`:
```php
// SEBELUM
new OA\Property(property: 'id', type: 'integer', example: 1),
// SESUDAH
new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
```

Cek juga route parameter untuk assignment endpoints yang masih pakai `type: 'integer'`.

### 4.7 Request Validator

Cek `Modules/Inbound/app/Http/Requests/` — jika ada rule yang validasi assignment ID sebagai `'integer'`, ubah ke `'uuid'`.

---

## Phase 5: Hapus Duplicate Directory

Hapus seluruh directory duplikat:
```bash
rm -rf Modules/Inbound/Inbound/
rm -rf Modules/Inventory/Inventory/
```

Ini directory lama dengan kode integer yang sudah tidak dipakai.

---

## Phase 6: Verifikasi

### 6.1 Jalankan Test
```bash
php artisan test
```

Semua test harus pass — khususnya:
- `tests/Feature/Inbound/InboundE2ETest.php` (42 tests)
- `tests/Feature/Inbound/InboundScanFlowTest.php`
- `tests/Feature/Order/OrderLifecycleTest.php` (33 tests)

### 6.2 Docker E2E
```bash
docker compose exec app php artisan migrate:fresh --seed
```

Pastikan migrate fresh berhasil tanpa error FK constraint.

### 6.3 Cek Flow PO → Inbound → Inventory

Penting: Verifikasi bahwa flow `PurchaseOrderService::receive()` benar meneruskan variant ID (bukan product ID) saat membuat inbound item. Jika PO `item_id` reference ke `products`, tapi inbound perlu variant ID, mungkin ada logic conversion yang harus ditambahkan.

Cek file:
- `Modules/Purchase/app/Services/PurchaseOrderService.php` — method `receive()`
- Lihat apakah dia membuat `InboundItem` dengan `item_id` dari PO item (product ID) atau sudah convert ke variant ID

---

## Ringkasan Perubahan per Module

| Module | Files | Jenis Perubahan |
|--------|-------|-----------------|
| Inventory | 3 migrations, 4 request validators | FK `products` → `product_variants` |
| Sales | 1 controller | Swagger `item_id` type |
| Outbound | 1 controller | Swagger `item_id` type |
| Inbound | 2 migrations, 2 models, 1 service, 1 controller | UUID PK untuk receipts & assignments |
| Tests | 3 test files | `product->id` → `variant->id` untuk `item_id` |
| Cleanup | 2 directories | Hapus duplikat |
