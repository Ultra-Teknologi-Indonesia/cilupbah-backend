# PLAN — `GET /locations/pos` (Tracker ID 163)

**Domain:** Location & The Rack Plan
**PIC:** Darriel
**Sumber:** Jubelio `dist (2).yaml:6942` / `dist (3).yaml:4031` — *Get All Locations that have POS Outlets* (`operationId: getLocationsPos`)
**Status target:** `todo` → `done`

---

## 1. Tujuan & Definisi

Endpoint mengembalikan daftar **lokasi yang berfungsi sebagai outlet POS** (Point of Sale — titik penjualan ritel fisik tempat transaksi kasir terjadi), terpisah dari gudang fulfillment biasa.

Di Jubelio sebuah `location` bisa menjadi outlet POS. Skema kita **belum punya penanda POS**. Tabel `locations` saat ini punya flag peran: `is_warehouse`, `is_fbl`, `is_tcb`, `is_fbs`. Kita tambahkan **`is_pos`** mengikuti pola yang sama persis (kolom boolean nullable, di-cast, fillable, masuk filter).

> Keputusan: pakai flag `is_pos` di tabel `locations` (bukan tabel baru). Alasannya identik dengan cara `is_fbl/is_tcb/is_fbs` ditambahkan sebelumnya — POS adalah *peran* dari sebuah lokasi, bukan entitas terpisah. Non-invasif terhadap modul lain.

---

## 2. Kontrak API (selaras Jubelio)

```
GET /api/v1/locations/pos
Auth: Bearer (auth:sanctum)
Query params:
  - page       (Spatie ?page=)
  - limit      (per_page, default 10)   ← konsisten dgn index() yg sudah ada
  - q / filter[search]  → cari di location_name
  - sort       (location_name | created_at | location_code)
```

**Response:** sama bentuk dengan `GET /locations` (paginated, `successPaginatedResponse`), tapi hanya berisi lokasi `is_active = true AND is_pos = true`.

**No-500 / no-404 issue:** path statik `locations/pos` (tanpa param id) — tidak ada risiko cast UUID. Wajib didaftarkan **sebelum** `apiResource('locations')` agar tidak tertangkap pola `locations/{location}` (sama seperti trik `locations/store` yang sudah ada di `routes/api.php:12`).

---

## 3. Perubahan File (urut implementasi)

### 3.1 Migration baru — `add_is_pos_to_locations_table`
`Modules/Warehouse/database/migrations/2026_06_11_xxxxxx_add_is_pos_to_locations_table.php`
```php
$table->boolean('is_pos')->nullable()->after('is_fbs');
```
`down()`: `dropColumn('is_pos')`.

### 3.2 Model `Location.php`
- Tambah `'is_pos'` ke `$fillable`.
- Tambah `'is_pos' => 'boolean'` ke `$casts`.

### 3.3 Repository `LocationRepository.php`
- Tambah `AllowedFilter::exact('is_pos')` ke `getAllPaginated()` (biar bisa difilter di list umum juga).
- Method baru:
```php
public function getPosPaginated(int $limit = 10)
{
    return QueryBuilder::for(Location::class)
        ->with('village.district.city.province')
        ->where('is_active', true)
        ->where('is_pos', true)
        ->allowedSearch('location_name')
        ->allowedSorts('location_name', 'created_at', 'location_code')
        ->defaultSort('location_name')
        ->paginate($limit);
}
```
*(mengikuti pola `getAllPaginated` + `getActiveWarehouses` yang sudah ada — tidak menambah dependency baru.)*

### 3.4 Service `LocationService.php`
```php
public function getPosLocations(int $limit = 10)
{
    return $this->locationRepository->getPosPaginated($limit);
}
```

### 3.5 Controller `LocationController.php`
Method `pos()` (mirror `index()`), + anotasi OA\Get `path: /api/v1/locations/pos`:
```php
public function pos(Request $request): JsonResponse
{
    $limit = $request->query('limit', 10);
    $locations = $this->locationService->getPosLocations($limit);
    return $this->successPaginatedResponse($locations, 'Daftar lokasi POS berhasil diambil');
}
```

### 3.6 Route `Modules/Warehouse/routes/api.php`
Tambah **sebelum** `apiResource` (setelah baris `locations/store`):
```php
Route::get('locations/pos', [LocationController::class, 'pos'])->name('warehouse.location.pos');
```
Nama unik → aman untuk `php artisan route:cache`.

### 3.7 FormRequest (opsional, konsisten)
`StoreLocationRequest` & `UpdateLocationRequest`: tambah `'is_pos' => 'nullable|boolean'` supaya lokasi bisa ditandai POS saat create/update (jika tidak ditambah, flag hanya bisa di-set via seeder/DB). **Disertakan** agar fitur usable end-to-end.

---

## 4. Testing (`Modules/Warehouse/tests/Feature/`)

Tambah ke test Warehouse yang ada (atau file `LocationPosTest.php`):
1. **`test_pos_endpoint_returns_only_pos_locations`** — buat 3 lokasi (1 `is_pos=true active`, 1 `is_pos=false`, 1 `is_pos=true inactive`) → `GET /locations/pos` hanya kembalikan 1 (yang pos & aktif). Assert `200` + struktur paginated.
2. **`test_pos_endpoint_paginates`** — default `per_page` = 10, ada `meta`/`links`.
3. **`test_pos_endpoint_search`** — `?filter[search]=` mempersempit hasil.
4. **`test_location_can_be_flagged_pos_on_create`** — `POST /locations` dengan `is_pos=true` lalu muncul di `/locations/pos`.

Jalankan: `php artisan test Modules/Warehouse/tests` → target semua hijau (33 existing + baru).
Verifikasi `php artisan route:cache` sukses (tidak ada bentrok nama route).

---

## 5. Integrasi & Risiko

| Aspek | Status |
|---|---|
| Bentrok modul lain (Rasyid: Inventory/Sales/dsb) | ❌ tidak — hanya nambah kolom nullable + endpoint baca | 
| Migration aman di data lama | ✅ `is_pos` nullable, default NULL (≠ true) → lokasi lama tidak otomatis jadi POS |
| `route:cache` | ✅ nama `warehouse.location.pos` unik |
| Error 500 | ✅ path statik, tanpa cast id |
| agents.md (Controller thin → Service → Repo, Spatie QB, per_page 10) | ✅ dipatuhi |

---

## 6. Definition of Done
- [ ] Migration jalan (`migrate` sukses, kolom `is_pos` ada)
- [ ] `GET /locations/pos` 200, hanya lokasi POS aktif
- [ ] Flag POS bisa di-set via create/update
- [ ] Test Warehouse hijau + `route:cache` sukses
- [ ] Tracker ID 163 → `done`
- [ ] Commit + push + merge main
