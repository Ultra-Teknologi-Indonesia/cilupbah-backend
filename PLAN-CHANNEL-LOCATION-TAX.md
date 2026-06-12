# Plan — 3 Endpoint in_progress (marketplace store, location store, taxes)

> **PIC:** Darriel · **Scope:** id 166, 164, 276. **Standar:** agents.md (Controller tipis → Service → Repository; Spatie; Resource; FormRequest).

## Verifikasi kode & strategi
| ID | Endpoint | Kondisi | Effort | Strategi |
|---|---|---|---|---|
| **164** | `GET /locations/store` | `ChannelWarehouseController@index` SUDAH ada (service→repo, paginated) | 🟢 XS | **alias route** ke `@index` |
| **166** | `GET /marketplace/store` | `ChannelShopRepository@getPaginatedShops()` SUDAH ada (Spatie) | 🟢 S | Resource + service method + controller + route |
| **276** | `GET /taxes` | Modul Tax **stub** (no model/migration, controller blade) | 🟡 M | greenfield: model+migration+repo+service+resource+rewrite controller API |

## Task
### 164 — locations/store (alias)
- Route `GET v1/locations/store` → `ChannelWarehouseController@index`.

### 166 — marketplace/store
- **Resource** `ChannelShopResource` (id, channel{code,name}, shop_id, shop_name, is_active — TANPA token).
- **Service** `ChannelService@getConnectedStores()` → `ChannelShopRepository@getPaginatedShops()`.
- **Controller** `ChannelController@stores()` → `successPaginatedResponse`.
- **Route** `GET v1/marketplace/store`.

### 276 — taxes (greenfield)
- **Migration** `taxes` (name, rate decimal, is_active).
- **Model** `Tax`.
- **Repository** `TaxRepository` (Spatie paginate + find/create/update/delete).
- **Service** `TaxService`.
- **Resource** `TaxResource`. **Request** `StoreTaxRequest`.
- **Rewrite** `TaxController` jadi API murni (index/store/show/update/destroy) — buang blade view.
- (opsional) seed beberapa pajak default (PPN 11%).

## DoD
Response 200 (no 500); lapisan benar; Resource; FormRequest; 162 test Product tetap lulus; tracker 164/166/276 → done (Darriel in_progress 3→0).
