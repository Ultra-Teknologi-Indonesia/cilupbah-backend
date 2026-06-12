# Plan — 2 Endpoint Product Listing (in_progress → done)

> **PIC:** Darriel · **Scope:** id 76 & 110 (Product Listing). **Standar:** agents.md (Controller tipis → Service → Repository; Spatie utk listing; Resource; FormRequest).

## Konteks (alur produk Jubelio)
- **76 `POST /inventory/catalog/listing`** — *Buat/ubah listing produk* → tahap **Upload / Produk Channel** (siapkan listing produk untuk 1 marketplace).
- **110 `GET /inventory/items/errors/`** — *Daftar listing gagal upload* → tahap **Pantauan** (lihat listing yang gagal di-upload agar bisa diperbaiki/re-upload).

## Verifikasi kode
Fungsi sudah ada; tinggal endpoint path Jubelio (alias) + samakan response:
- 76 → `ProductChannelDraftService@upsertDraft($productId,$shopId,$data)` (sudah ada, dipakai `POST products/{id}/channel-drafts`).
- 110 → `UploadHistoryRepository@paginate()` (sudah Spatie, punya filter `status`). Endpoint errors = paksa `status = failed`.

## Task
### 76 — POST /inventory/catalog/listing
- **FormRequest** `StoreCatalogListingRequest` (product_id uuid+exists, shop_id required, channel_category_id?, attribute_mapping?, price_override?, status?).
- **Controller** `ProductChannelDraftController@catalogListing` — validasi 404 produk, panggil `upsertDraft`, balas `ProductChannelDraftResource`.
- **Route** `POST inventory/catalog/listing`.

### 110 — GET /inventory/items/errors
- **Repository** `UploadHistoryRepository@paginateErrors()` — query sama, dipaksa `status = STATUS_FAILED`.
- **Service** `UploadHistoryService@paginateErrors()` → repo.
- **Controller** `ProductSyncLogController@errors()` — pakai `UploadHistoryResource`.
- **Route** `GET inventory/items/errors`.

## DoD
Response 200/201 (no 500), input invalid → 422/404; lapisan benar; Resource; FormRequest; 162 test Product tetap lulus; set 76 & 110 → done di tracker.
