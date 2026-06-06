# Plan: Hapus Nested Duplicate Folder di Modules

## Context

Tiga modul memiliki folder duplikat bersarang di dalamnya:

```
Modules/Inventory/Inventory/   ← duplikat (versi lama)
Modules/Inbound/Inbound/       ← duplikat (versi lama)
Modules/Warehouse/Warehouse/   ← duplikat (versi lama)
```

Struktur yang benar seharusnya hanya:
```
Modules/Inventory/app/
Modules/Inbound/app/
Modules/Warehouse/app/
```

Folder inner (`Module/Module/`) adalah **versi lama** — kemungkinan sisa dari proses generate module atau copy-paste. Folder outer (`Module/app/`) adalah **versi terbaru** yang sudah dikembangkan lebih lanjut.

---

## Analisis Perbedaan

### Inventory — `Modules/Inventory/Inventory/` vs `Modules/Inventory/`

| File | Status | Perbedaan di outer (terbaru) |
|---|---|---|
| Models (4 file) | **Identik** | — |
| ServiceProvider, RouteServiceProvider, EventServiceProvider | **Identik** | — |
| InventoryTransferRepository.php | **Identik** | — |
| TransferInRequest.php | **Identik** | — |
| InventoryController.php | **Berbeda** | `item_id` type → `string/uuid` |
| InventoryTransactionController.php | **Berbeda** | `item_id` type → `string/uuid` (3 tempat) |
| AdjustStockRequest.php | **Berbeda** | validation `uuid\|exists:product_variants` |
| PutawayStockRequest.php | **Berbeda** | validation `uuid\|exists:product_variants` |
| TransferOutRequest.php | **Berbeda** | validation `uuid\|exists:product_variants` |
| TransferStockRequest.php | **Berbeda** | validation `uuid\|exists:product_variants` |
| InventoryRepository.php | **Berbeda** | type hint `int` → `string` untuk `$itemId` |
| InventoryMovementRepository.php | **Berbeda** | type hint `int` → `string` untuk `$itemId` |
| InventoryService.php | **Berbeda** | type hint `int` → `string` untuk `$itemId` |
| Migrations (4 file) | **Berbeda** | outer lebih baru |

**Kesimpulan:** Outer sudah di-update untuk UUID support, inner masih versi int.

---

### Inbound — `Modules/Inbound/Inbound/` vs `Modules/Inbound/`

| File | Status | Perbedaan di outer (terbaru) |
|---|---|---|
| InboundItem.php, beberapa Requests | **Identik** | — |
| Providers (3 file) | **Identik** | — |
| InboundController.php | **Berbeda** | OA type `integer` → `string/uuid` |
| AutoPutawayRequest.php | **Berbeda** | validation `integer` → `uuid` |
| StoreInboundRequest.php | **Berbeda** | validation `integer` → `uuid` |
| Inbound.php (Model) | **Berbeda** | outer punya `use HasUuids` |
| InboundAssignment.php | **Berbeda** | outer punya `use HasUuids` |
| InboundReceipt.php | **Berbeda** | outer punya `use HasUuids` |
| InboundRepository.php | **Berbeda** | type hint `int` → `string` |
| InboundService.php | **Berbeda** | type hint `int` → `string` |

**Kesimpulan:** Outer sudah mulai adopsi UUID (pakai Laravel `HasUuids` bawaan, bukan `HasUuid7` custom).

---

### Warehouse — `Modules/Warehouse/Warehouse/` vs `Modules/Warehouse/`

| File | Status | Perbedaan di outer (terbaru) |
|---|---|---|
| Repositories (3 file) | **Identik** | — |
| Providers (3 file) | **Identik** | — |
| Location.php | **Berbeda** | outer punya `HasUuid7`, relasi `zones()` |
| LocationBin.php | **Berbeda** | outer punya `HasUuid7`, field `zone_id`, relasi `zone()` |
| ChannelWarehouse.php | **Berbeda** | outer punya `HasUuid7` |
| LocationController.php | **Berbeda** | outer punya schema `LocationZone`, `LocationLayoutRack` |
| LocationBinController.php | **Berbeda** | outer pakai `GenerateLocationBinRequest` |
| StoreLocationRequest.php | **Berbeda** | outer punya validasi `layout` zones/racks |
| UpdateLocationRequest.php | **Berbeda** | outer punya validasi `layout` zones/racks |
| StoreChannelWarehouseRequest.php | **Berbeda** | `channel_id` → `string` |
| LocationService.php | **Berbeda** | outer punya `syncLayout()` dengan zones |
| LocationBinService.php | **Berbeda** | outer refactored |

**Kesimpulan:** Outer jauh lebih maju — sudah ada zones, layout, `HasUuid7`.

---

## Tindakan

### Yang harus dihapus:

```
rm -rf Modules/Inventory/Inventory/
rm -rf Modules/Inbound/Inbound/
rm -rf Modules/Warehouse/Warehouse/
```

### Pre-check sebelum hapus:

1. **Pastikan tidak ada import/autoload** yang mereferensikan path inner:
   - Cek `composer.json` autoload paths
   - Cek `module.json` — namespace tetap `Modules\Inventory\Providers\...` (mengarah ke outer)
   - Cek `modules_statuses.json`
2. **Pastikan tidak ada file unik** di inner yang tidak ada di outer (file yang hanya ada di inner):
   - Cari file di inner yang tidak punya padanan di outer

### Post-check setelah hapus:

1. `composer dump-autoload` — pastikan tidak ada class not found
2. `php artisan route:list` — pastikan semua route masih terdaftar
3. `php artisan migrate:status` — pastikan migration masih terdeteksi

---

## Catatan Tambahan

Inbound outer saat ini pakai `HasUuids` (Laravel bawaan) bukan `HasUuid7` (custom trait). Ini perlu diperhatikan saat migrasi UUIDv7 nanti — harus diganti ke `HasUuid7` agar konsisten dengan modul lain (Channel, Order, Warehouse).
