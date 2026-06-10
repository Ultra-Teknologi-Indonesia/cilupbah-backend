# Implementation Plan: sales-order-rename

## Overview

Rencana implementasi ini mengeksekusi refactoring rename `Order` -> `SalesOrder` dan `OrderItem` -> `SalesOrderItem`, mengonsolidasikan domain penjualan ke `Modules/Sales`, me-rename tabel `orders` -> `sales_orders` dan `order_items` -> `sales_order_items` (kolom FK tetap `order_id`), serta mengganti prefix route `/orders` -> `/sales`.

Urutan eksekusi mengikuti sequence aman pada design: **Fase 1 (kode) -> Fase 2 (database/migration) -> Fase 3 (verifikasi)**, sehingga seluruh perubahan dapat di-rollback (kode via VCS, skema via `migrate:rollback`).

Catatan penting (titik paling rawan, ditegaskan di sub-task terkait):
- **Argumen FK eksplisit `'order_id'`** wajib pada setiap relasi yang menyentuh `sales_orders`/`sales_order_items`, karena default Eloquent berubah menjadi `sales_order_id` setelah class di-rename.
- **Query literal `DB::table('orders')` / `DB::table('order_items')`** tidak ikut ter-rename otomatis dan harus diganti manual menjadi `sales_orders` / `sales_order_items`.
- Refactoring ini **tidak menggunakan property-based testing** (design menyatakan Correctness Properties = Not Applicable; kebenaran = behavioral equivalence). Verifikasi memakai regression, integration, dan migration test.

## Tasks

- [ ] 1. Pindahkan & rename Models entitas penjualan ke Modules/Sales
  - [ ] 1.1 Buat `Modules/Sales/app/Models/SalesOrder.php` (dari `Order`)
    - Pindahkan isi `Modules/Order/app/Models/Order.php`, ubah nama class menjadi `SalesOrder` dan namespace menjadi `Modules\Sales\Models`
    - Tambahkan `protected $table = 'sales_orders';` secara eksplisit
    - Pertahankan `$fillable`, `$casts`, trait `HasUuid7` identik dengan `Order`
    - **FK EKSPLISIT (titik kritis):** relasi `items()` -> `hasMany(SalesOrderItem::class, 'order_id')`; `picklistItems()` -> `hasMany(\Modules\Outbound\Models\PicklistItem::class, 'order_id')`; `packlist()` -> `hasOne(\Modules\Outbound\Models\Packlist::class, 'order_id')`; `shipmentOrders()` -> `hasMany(\Modules\Outbound\Models\ShipmentOrder::class, 'order_id')`; `location()` -> `belongsTo(\Modules\Warehouse\Models\Location::class)`
    - _Requirements: 1.1, 1.3, 1.4, 1.7, 3.2_

  - [ ] 1.2 Buat `Modules/Sales/app/Models/SalesOrderItem.php` (dari `OrderItem`)
    - Pindahkan isi `Modules/Order/app/Models/OrderItem.php`, ubah nama class menjadi `SalesOrderItem` dan namespace menjadi `Modules\Sales\Models`
    - Tambahkan `protected $table = 'sales_order_items';` secara eksplisit; pertahankan `$fillable` termasuk `order_id`
    - **FK EKSPLISIT (titik kritis):** `order()` -> `belongsTo(SalesOrder::class, 'order_id')`; pertahankan `product()` dan `inventory()` dengan key eksplisit seperti semula
    - _Requirements: 1.2, 1.3, 1.4, 1.7, 3.2_

  - [ ] 1.3 Hapus file Model lama `Modules/Order/app/Models/Order.php` dan `OrderItem.php`
    - Hapus kedua file setelah isinya dipindah agar tidak ada definisi class ganda
    - _Requirements: 1.1, 1.2, 1.3_

  - [ ]* 1.4 Tulis integration test relasi SalesOrder <-> SalesOrderItem
    - Verifikasi `SalesOrder->items` mengembalikan koleksi `SalesOrderItem` via FK `order_id`, dan `SalesOrderItem->order` mengembalikan `SalesOrder`
    - _Requirements: 1.7, 6.1_

- [ ] 2. Pindahkan lapisan aplikasi (Service, Repository, Controller, Resources, Jobs, Exceptions) ke Modules\Sales
  - [ ] 2.1 Pindahkan Repository menjadi `Modules/Sales/app/Repositories/SalesOrderRepository.php`
    - Pindah & rename `OrderRepository` -> `SalesOrderRepository`, namespace `Modules\Sales\Repositories`, `use Modules\Sales\Models\SalesOrder;`
    - Ganti `QueryBuilder::for(Order::class)` -> `QueryBuilder::for(SalesOrder::class)`; pertahankan `allowedSearch('customer_name', 'salesorder_no')`, `allowedFilters`, `allowedSorts`, `allowedIncludes('items')`
    - Pertahankan `->paginate(request('per_page', 10))->appends(request()->query())` (AGENTS.md §5)
    - **TITIK KRITIS — query literal:** ganti `DB::table('orders')` -> `DB::table('sales_orders')` dan `DB::table('order_items')` -> `DB::table('sales_order_items')` pada `upsertOrderBySalesOrderNo` dan `syncOrderItems`
    - _Requirements: 4.1, 4.4, 4.6, 4.7, 4.8_

  - [ ] 2.2 Pindahkan Service menjadi `Modules/Sales/app/Services/SalesOrderService.php` dan `StockService.php`
    - Pindah & rename `OrderService` -> `SalesOrderService`, namespace `Modules\Sales\Services`; pindahkan `StockService` apa adanya ke namespace `Modules\Sales\Services`
    - Update type hint `Order` -> `SalesOrder`, `use Modules\Sales\Jobs\{SyncStockJob, CancelChannelOrderJob};`
    - **TITIK KRITIS — query literal:** ganti `DB::table('orders')` -> `DB::table('sales_orders')` di `upsertFromChannel`
    - Pertahankan logika bisnis identik (idempotency key, `ALLOWED_TRANSITIONS`, reserve/pick/ship/release stock) — interaksi DB tetap via repository (AGENTS.md §1)
    - _Requirements: 4.1, 4.2, 4.4, 6.1, 6.2_

  - [ ] 2.3 Pindahkan Resources menjadi `SalesOrderResource.php` dan `SalesOrderItemResource.php`
    - Pindah & rename ke `Modules/Sales/app/Http/Resources`, namespace `Modules\Sales\Http\Resources`
    - **Pertahankan persis struktur `toArray()`** (nama field, casting, blok `shipping`, `items`) agar skema respons setara (Requirement 5.5, 5.6); update hanya nama class, namespace, dan `schema:` OpenAPI
    - _Requirements: 4.1, 4.5, 5.5, 5.6_

  - [ ] 2.4 Pindahkan Controller menjadi `Modules/Sales/app/Http/Controllers/SalesOrderController.php`
    - Pindah & rename `OrderController` -> `SalesOrderController`, namespace `Modules\Sales\Http\Controllers`
    - Update type hint `Order` -> `SalesOrder`, resource `OrderResource` -> `SalesOrderResource`; pertahankan method `index/store/show/update/destroy`
    - Pertahankan `use ApiResponse`, `successResponse`/`successPaginatedResponse`, transform paginator via Resource (AGENTS.md §2); perbarui anotasi OpenAPI path `/api/v1/orders` -> `/api/v1/sales` tanpa mengubah nama field schema
    - _Requirements: 4.1, 4.3, 4.5, 5.3, 5.4, 5.6_

  - [ ] 2.5 Pindahkan Jobs ke `Modules/Sales/app/Jobs`
    - Pindahkan `SyncStockJob`, `CancelChannelOrderJob`, `ProcessMarketplaceOrder`, `AdminAlertJob` ke namespace `Modules\Sales\Jobs`
    - Update import internal `Order`/`OrderItem` -> `SalesOrder`/`SalesOrderItem`; pertahankan logika identik
    - _Requirements: 4.2, 6.2_

  - [ ] 2.6 Pindahkan Exceptions ke `Modules/Sales/app/Exceptions`
    - Pindahkan `CannotDeleteActiveOrderException`, `DuplicateOrderException`, `InsufficientStockException`, `InvalidStatusTransitionException` ke namespace `Modules\Sales\Exceptions`; pesan & perilaku dipertahankan
    - Update referensi exception pada `SalesOrderService`/`SalesOrderController`
    - _Requirements: 4.1, 4.3_

  - [ ] 2.7 Hapus file lapisan aplikasi lama di `Modules/Order/app`
    - Hapus `OrderRepository`, `OrderService`, `StockService`, `OrderController`, `OrderResource`, `OrderItemResource`, keempat Jobs, dan keempat Exceptions yang sudah dipindah
    - _Requirements: 1.3, 2.4_

  - [ ]* 2.8 Tulis smoke/contract test endpoint sales (level controller/resource)
    - Verifikasi skema field `SalesOrderResource` setara dengan baseline `OrderResource`
    - _Requirements: 5.5, 5.6_

- [ ] 3. Perbarui referensi lintas modul ke Modules\Sales (FK eksplisit)
  - [ ] 3.1 Perbarui Models Outbound (`PicklistItem`, `ShipmentOrder`, `Packlist`, `PacklistItem`)
    - **FK EKSPLISIT (titik kritis):** `PicklistItem.order()` -> `belongsTo(\Modules\Sales\Models\SalesOrder::class, 'order_id')` dan `PicklistItem.orderItem()` -> `belongsTo(\Modules\Sales\Models\SalesOrderItem::class, 'order_item_id')`; `ShipmentOrder.order()` -> `belongsTo(SalesOrder::class, 'order_id')`; `Packlist.order()` -> `belongsTo(SalesOrder::class, 'order_id')`; `PacklistItem.orderItem()` -> `belongsTo(SalesOrderItem::class, 'order_item_id')`
    - _Requirements: 2.1, 2.4, 2.5, 6.3_

  - [ ] 3.2 Perbarui Services Outbound (`PacklistService`, `OutboundFulfillmentService`, `ShipmentService`, `PicklistService`)
    - Ganti `use Modules\Order\Models\Order;` -> `use Modules\Sales\Models\SalesOrder;` dan seluruh type hint terkait
    - _Requirements: 2.1, 2.4, 6.3_

  - [ ] 3.3 Perbarui Model Warranty (`Modules/Warranty/.../Warranty.php`)
    - **FK EKSPLISIT:** `order()` -> `belongsTo(\Modules\Sales\Models\SalesOrder::class, 'order_id')`
    - _Requirements: 2.2, 2.4, 2.5, 6.4_

  - [ ] 3.4 Perbarui Model SalesReturn (`Modules/Sales/.../SalesReturn.php`)
    - Ganti `use Modules\Order\Models\Order;` -> `use Modules\Sales\Models\SalesOrder;`; **FK EKSPLISIT:** `order()` -> `belongsTo(SalesOrder::class, 'order_id')`
    - _Requirements: 2.3, 2.4, 2.5, 6.5_

  - [ ]* 3.5 Tulis integration test relasi lintas modul
    - Verifikasi `Packlist->order`, `ShipmentOrder->order`, `PicklistItem->order` & `->orderItem`, `PacklistItem->orderItem`, `Warranty->order`, `SalesReturn->order` mengembalikan instance `SalesOrder`/`SalesOrderItem` via FK `order_id`/`order_item_id`
    - _Requirements: 2.5, 6.3, 6.4, 6.5_

- [ ] 4. Checkpoint - Verifikasi tahap kode
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 5. Pindahkan migrations & tambahkan migration rename tabel
  - [ ] 5.1 Pindahkan migration modul Order ke `Modules/Sales/database/migrations` apa adanya
    - Pindahkan `create_orders_table`, `create_order_items_table`, `add_tiktok_fields_to_orders_table`, `change_order_ids_to_uuid` tanpa mengubah isi (tetap mereferensikan `orders`/`order_items`)
    - Pertahankan timestamp asli agar urutan `migrate:fresh` benar (create -> uuid -> rename)
    - _Requirements: 3.6_

  - [ ] 5.2 Buat migration rename tabel di `Modules/Sales/database/migrations` (timestamp paling akhir)
    - `up()`: drop FK `order_id`/`order_item_id` pada `order_items`, `picklist_items`, `packlist_items`, `packlists`, `shipment_orders`, `sales_returns`, `warranties` -> `Schema::rename('orders','sales_orders')` & `Schema::rename('order_items','sales_order_items')` -> re-add seluruh FK ke `sales_orders.id`/`sales_order_items.id` dengan perilaku on-delete asli (`cascadeOnDelete`, `restrictOnDelete`, `nullOnDelete` sesuai design)
    - `down()`: kebalikan deterministik — drop FK baru -> rename balik ke `orders`/`order_items` -> re-add FK ke acuan lama dengan perilaku on-delete asli
    - Pastikan timestamp lebih baru dari migration pembuat `warranties`/`packlists`/`shipment_orders`/`picklist_items`/`packlist_items`
    - _Requirements: 3.4, 3.5, 3.7, 8.1, 8.2, 8.4_

  - [ ]* 5.3 Tulis migration test skema & FK
    - Verifikasi `migrate:fresh`: `sales_orders`/`sales_order_items` ada, `orders`/`order_items` tidak ada; FK `order_id` pada `sales_order_items`, `picklist_items`, `packlists`, `shipment_orders`, `sales_returns`, `warranties` menunjuk `sales_orders.id`
    - _Requirements: 3.7, 8.1_

- [ ] 6. Perbarui routing ke prefix /sales
  - [ ] 6.1 Daftarkan route SalesOrder di `Modules/Sales/routes/api.php`
    - Di bawah `prefix('v1')` + `auth:sanctum`, daftarkan route `sales` (index/store), `sales/{id}` (show/update/destroy) memetakan ke `SalesOrderController`
    - **Urutan kritis:** daftarkan route literal `sales/returns*` (SalesReturn) **sebelum** `sales/{id}` agar `returns` tidak tertangkap sebagai `{id}`
    - Pertahankan HTTP method, payload, dan skema respons setara (Requirement 5.3, 5.4, 5.5)
    - _Requirements: 5.1, 5.3, 5.4_

  - [ ] 6.2 Hapus route lama `/orders` di `Modules/Order/routes/api.php`
    - Hapus `Route::apiResource('orders', ...)` dan referensi `OrderController`
    - _Requirements: 5.1, 5.2_

- [ ] 7. Perbarui Providers & autoload, nonaktifkan modul Order
  - [ ] 7.1 Hapus `OrderServiceProvider` dan pastikan SalesServiceProvider memuat route penjualan
    - Hapus `Modules/Order/app/Providers/OrderServiceProvider.php` (dan provider modul Order lain yang tidak terpakai); konfirmasi `SalesServiceProvider`/`RouteServiceProvider` memuat `Modules/Sales/routes/api.php`
    - _Requirements: 4.3, 1.5_

  - [ ] 7.2 Selaraskan seeder ke `SalesDatabaseSeeder`
    - Gabungkan/selaraskan `OrderDatabaseSeeder` ke `SalesDatabaseSeeder` di `Modules/Sales/database/seeders`; jika ada factory, namakan `SalesOrderFactory`/`SalesOrderItemFactory` (namespace `Modules\Sales\Database\Factories`)
    - _Requirements: 7.3_

  - [ ] 7.3 Nonaktifkan/hapus modul Order
    - Setelah nol referensi tersisa, hapus direktori `Modules/Order` (atau `php artisan module:disable Order`); pastikan tidak ada entri PSR-4 menggantung di `composer.json` root
    - _Requirements: 1.3, 1.5, 2.4_

  - [ ] 7.4 Refresh autoloader & cache
    - Jalankan `composer dump-autoload` lalu `php artisan optimize:clear` agar binding & route ter-refresh
    - _Requirements: 1.5, 1.6_

- [ ] 8. Perbarui automated test terdampak
  - [ ] 8.1 Perbarui `tests/Feature/Order/OrderLifecycleTest.php`
    - Ganti `use Modules\Order\Models\Order;` -> `use Modules\Sales\Models\SalesOrder;`; seluruh `Order::create(...)` -> `SalesOrder::create(...)`
    - Update path `/api/v1/orders...` -> `/api/v1/sales...` pada `postJson`/`getJson`/`deleteJson`
    - Update `assertDatabaseMissing/Has('orders', ...)` -> `('sales_orders', ...)` dan `('order_items', ...)` -> `('sales_order_items', ...)`
    - _Requirements: 7.1, 7.4, 6.1, 6.2_

  - [ ] 8.2 Perbarui `tests/Feature/Inbound/InboundE2ETest.php`
    - Ganti `use Modules\Order\Models\Order;` -> `SalesOrder`; `Order::create(...)` -> `SalesOrder::create(...)`; sesuaikan path `/sales` bila ada; skenario sales return dengan `order_id` tetap valid (kolom FK tidak berubah)
    - _Requirements: 7.2, 7.4, 6.5_

- [ ] 9. Verifikasi akhir & validasi migrasi
  - [ ] 9.1 Pencarian repo-wide nol referensi `Modules\Order\`
    - Cari `Modules\Order\` di seluruh kode (kecuali histori/dokumen) dan pastikan nol hasil; perbaiki sisa referensi bila ada
    - _Requirements: 2.6, 6, 7.4_

  - [ ] 9.2 Jalankan test suite (single run)
    - Jalankan `php artisan test` (single run, bukan watch); pastikan tidak ada kegagalan akibat class/factory hilang
    - _Requirements: 1.6, 6.1, 6.2, 6.3, 6.4, 6.5, 7.4_

  - [ ] 9.3 Validasi skema via `migrate:fresh` dan rollback via `migrate:rollback`
    - Jalankan `migrate:fresh` (validasi skema & FK baru), lalu uji `migrate:rollback` (kembalikan `orders`/`order_items` & FK acuan lama)
    - _Requirements: 3.7, 8.1, 8.2, 8.4_

- [ ] 10. Checkpoint akhir - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks bertanda `*` bersifat opsional (test) dan dapat dilewati untuk MVP cepat, namun direkomendasikan untuk menjamin behavioral equivalence.
- Setiap task mereferensikan klausa requirement spesifik untuk traceability.
- Urutan eksekusi mengikuti sequence aman design: Fase 1 kode (task 1-4) -> Fase 2 database (task 5) -> routing/provider/test (task 6-8) -> Fase 3 verifikasi (task 9-10).
- **Fitur ini tidak menggunakan property-based testing** sesuai design (Correctness Properties = Not Applicable). Verifikasi memakai regression, integration relasi lintas modul, smoke/contract endpoint, dan migration test.
- Titik paling rawan yang ditegaskan di sub-task: argumen FK eksplisit `'order_id'` pada semua relasi, dan penggantian query literal `DB::table('orders'/'order_items')`.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2"] },
    { "id": 1, "tasks": ["1.3", "1.4"] },
    { "id": 2, "tasks": ["2.1", "2.2", "2.3", "2.5", "2.6"] },
    { "id": 3, "tasks": ["2.4", "2.7"] },
    { "id": 4, "tasks": ["2.8", "3.1", "3.2", "3.3", "3.4"] },
    { "id": 5, "tasks": ["3.5", "5.1"] },
    { "id": 6, "tasks": ["5.2", "6.1", "6.2"] },
    { "id": 7, "tasks": ["5.3", "7.1", "7.2"] },
    { "id": 8, "tasks": ["7.3", "8.1", "8.2"] },
    { "id": 9, "tasks": ["7.4", "9.1"] },
    { "id": 10, "tasks": ["9.2", "9.3"] }
  ]
}
```
