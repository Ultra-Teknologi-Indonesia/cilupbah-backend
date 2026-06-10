# Planning — Tab Master Product: Arsip (Archive Feed)

## 1. Tujuan

Menyediakan endpoint daftar & detail **produk yang diarsipkan** (`status = archived`) dengan **struktur respons identik Master Feed**: produk utama (`item_group_id`) beserta varian-variannya (`variants[]`) dan tampil di channel mana saja (`online_status[]`). Tambahan khusus arsip: kapan & oleh siapa & alasan diarsipkan.

Ini melengkapi tab Master Product agar tidak lagi bergantung pada Jubelio.

## 2. Konteks yang sudah ada (reuse, jangan bangun ulang)

| Komponen | Status | Catatan |
|---|---|---|
| `Product::STATUS_ARCHIVED = 'archived'` | ✅ ada | konstanta + masuk `Product::STATUSES` |
| Kolom `archived_at`, `archived_by`, `archive_reason` | ✅ ada | fillable + cast `archived_at:datetime` |
| Relasi `Product::archivedBy()` → `User` | ✅ ada | untuk menampilkan email pengarsip |
| `POST /v1/products/{id}/archive` + `/restore` | ✅ ada | transisi lifecycle (di luar scope ini) |
| `MasterItemResource` | ✅ ada | shape utama yang akan di-reuse |
| `MasterFeedRepository` | ✅ ada | `paginate($status, $updatedSince)` sudah parametrik per-status |
| `MasterFeedService` | ✅ ada | `paginate(?status)`, `find(id)` (hardcode `STATUS_MASTER`) |

**Insight:** `MasterFeedRepository::paginate()` dan `find()` sudah menerima `$status`. Yang belum ada hanya: resource turunan untuk field arsip, service/controller/route khusus arsip, dan filter arsip.

## 3. Keputusan desain

### 3.1 Endpoint terpisah (BUKAN `?status=archived` di master)
Bikin endpoint sendiri `GET /v1/products/archives` & `/archives/{id}`, sejalan pola `ReviewFeedController` (`/products/reviews`). Alasan:
- Konsisten dengan tab UI yang terpisah (Master vs Arsip).
- Memungkinkan filter & field khusus arsip tanpa mengotori kontrak Master Feed.
- Reuse repository yang sama, hanya status berbeda.

### 3.2 Resource: `ArchiveItemResource extends MasterItemResource`
Persis pola `ReviewItemResource extends MasterItemResource`. Override `toArray()` → panggil `parent::toArray()` lalu tambah blok arsip:
```
'archived_at'    => $this->archived_at,
'archived_by'    => $this->archivedBy->email ?? null,
'archive_reason' => $this->archive_reason,
```
Struktur inti (`item_group_id`, `variants[]`, `online_status[]`, dst.) **tidak berubah** — sesuai permintaan "sama seperti master".

### 3.3 Spatie QueryBuilder + agents.md (WAJIB)
- Listing lewat `QueryBuilder::for(Product::class)` di repository (query DB hanya di repository).
- `allowedSearch('name','sku')` (FTS macro), `allowedSorts`, `defaultSort('-archived_at')`.
- Pagination default **10**: `paginate(request('per_page', 10))->appends(request()->query())`.
- Filter via `AllowedFilter::callback` / `exact`.
- Respons via `ApiResponse` trait (`successPaginatedResponse`, `successResponse`, `errorResponse`).
- **Tanpa komentar** di kode produksi (sesuai instruksi standar).

### 3.4 Filter yang didukung
- `filter[archived_by]` — exact UUID user pengarsip.
- `filter[archived_from]` / `filter[archived_to]` — rentang `archived_at` (callback).
- `filter[category_id]` — exact.
- `search=` — nama/sku (FTS).
- `sort=` — `archived_at` (default desc), `name`, `created_at`.

## 4. Perubahan file

### 4.1 Baru
1. **`Modules/Product/app/Http/Resources/ArchiveItemResource.php`**
   `extends MasterItemResource`; override `toArray()` → `parent::toArray()` + 3 field arsip (`archived_at`, `archived_by`, `archive_reason`).

2. **`Modules/Product/app/Repositories/ArchiveFeedRepository.php`**
   `QueryBuilder::for(Product::class)->where('status', STATUS_ARCHIVED)` + RELATIONS sama seperti MasterFeed **plus** `archivedBy:id,email`. Method `paginate()` (filter arsip + search + sort) & `find($id)`.
   *(Alternatif lebih ramping: tambah RELATIONS `archivedBy` ke `MasterFeedRepository` dan reuse — tapi repository terpisah lebih bersih & sesuai pola Review. **Rekomendasi: repository terpisah.**)*

3. **`Modules/Product/app/Services/ArchiveFeedService.php`**
   `paginate($filters)` & `find($id)` delegasi ke repo (hardcode `STATUS_ARCHIVED`).

4. **`Modules/Product/app/Http/Controllers/ArchiveFeedController.php`**
   `index()` (validate per_page/page/search/sort + filter arsip; map collection ke `ArchiveItemResource` lalu `successPaginatedResponse`) & `show()` (find atau `errorResponse 404 "Produk arsip tidak ditemukan"`).

### 4.2 Diubah
5. **`Modules/Product/routes/api.php`**
   Tambah **sebelum** `apiResource('products')` (agar tidak tertangkap `products/{id}`), berdampingan dengan master/reviews:
   ```php
   Route::get('products/archives', [ArchiveFeedController::class, 'index']);
   Route::get('products/archives/{id}', [ArchiveFeedController::class, 'show']);
   ```
   + `use ...ArchiveFeedController;`

## 5. Bentuk respons (ringkas)

`GET /v1/products/archives` → `successPaginatedResponse`:
```jsonc
{
  "data": [
    {
      "item_group_id": "uuid",
      "item_name": "...",
      "variations": [ { "label": "...", "values": [...] } ],
      "variants": [ { "item_id": "uuid", "item_code": "SKU", "store_names": [...], ... } ],
      "total_variants": 3,
      "online_status": [ { "channel_code": "tiktok", "store_name": "...", "channel_url": "..." } ],
      "thumbnail": "...",
      // tambahan arsip:
      "archived_at": "2026-06-10T...",
      "archived_by": "user@cilupbah.id",
      "archive_reason": "discontinued"
    }
  ],
  "meta": { "per_page": 10, ... }
}
```
`GET /v1/products/archives/{id}` → `successResponse` objek tunggal dengan shape sama.

## 6. Test (PHPUnit + RefreshDatabase + withoutMiddleware)

File baru: `Modules/Product/tests/Feature/ArchiveFeedTest.php`
1. `test_list_returns_only_archived_products_in_master_shape` — buat 1 master + 1 archived; assert hanya archived muncul; struktur `item_group_id/variants/online_status/archived_at/archived_by`.
2. `test_list_default_pagination_is_10`.
3. `test_search_by_name` (`?search=`).
4. `test_filter_by_archived_by`.
5. `test_filter_by_archived_date_range` (`archived_from`/`archived_to`).
6. `test_default_sort_is_archived_at_desc`.
7. `test_show_returns_archived_product`.
8. `test_show_unknown_or_non_archived_returns_404` — pastikan produk `master` tidak bisa diakses via endpoint arsip.

## 7. Urutan eksekusi
- **Fase 1** — ArchiveItemResource + ArchiveFeedRepository.
- **Fase 2** — ArchiveFeedService + ArchiveFeedController + routes.
- **Fase 3** — ArchiveFeedTest, jalankan `rtk php artisan test --filter=ArchiveFeed`, hijaukan.
- **Fase 4** — `rtk php artisan test` modul Product (regресi) lalu commit `feat(product): tab Master - sub-tab Arsip (produk diarsipkan struktur master)`.

## 8. Di luar scope
- Aksi restore/hapus-permanen dari UI arsip (endpoint `restore` sudah ada; bila perlu tombol, tinggal pasang di FE).
- Retensi/purge otomatis produk arsip.
- Perubahan kontrak Master Feed.
