# Planning: Tab "Draft" (sub-tab Upload) — lengkapi UI + aksi Upload

> Tujuan: melengkapi tab **Draft** pada halaman Upload sehingga (a) datanya siap
> ditampilkan di UI dengan layout sejajar tab "Hasil", dan (b) ada aksi **Upload**
> yang menyambungkan Draft → Hasil (`product_sync_logs` pending → sukses/gagal).
>
> Referensi: cabang `27-product`, modul `Modules/Product`. Mengikuti pola fitur
> Upload History (`upload-history-feed-implementation-plan.md`), Master Feed, dan
> standar `agents.md`. Memakai **UUID**.

---

## 0. Konteks (relasi Draft ↔ Hasil)

```
DRAFT (product_channel_drafts)        klik Upload         HASIL (product_sync_logs)
  status: draft / ready / cancelled  ───────────────►   status: pending → success/failed
  "disiapkan, belum dikirim"                              "sudah dikirim ke marketplace"
```

Tab Draft & Hasil = dua tabel berbeda. Draft = konfigurasi listing sebelum kirim;
Hasil = riwayat upload. Saat ini **belum nyambung**: upload dipicu jalur terpisah
(`POST /v1/{channel}/products`), bukan dari draft.

---

## 1. Kondisi Sekarang (audit)

| Komponen                                                                                        | Status                  |
| ----------------------------------------------------------------------------------------------- | ----------------------- |
| Tabel `product_channel_drafts` (status, channel_category_id, attribute_mapping, price_override) | ✅ Ada                  |
| CRUD per produk: `index`/`store`/`update`/`destroy` (`products/{id}/channel-drafts`)            | ✅ Ada                  |
| List global `GET /v1/products/channel-drafts` (Spatie QueryBuilder, paginate, filter)           | ✅ Ada                  |
| `ProductChannelDraftService::upsertDraft`                                                       | ✅ Ada                  |
| Query list ada **di controller** (bukan repository)                                             | ⚠️ Langgar agents.md §1 |
| `ProductChannelDraftResource` minimal (tanpa nama produk, thumbnail, channel, varian)           | ⚠️ Belum siap UI        |
| `allowedSearch` (`?search=`) di list                                                            | ❌ Belum                |
| Aksi **Upload dari Draft** (draft → job push → Hasil)                                           | ❌ Belum                |
| Bulk upload (checkbox)                                                                          | ❌ Belum                |

**Tidak perlu migration inti** (kecuali keputusan status pasca-upload, lihat §7).

---

## 2. Kontrak Data (Resource Draft untuk UI)

Layout sejajar tab Hasil (kolom Produk + Store + aksi). Field ➕ = penambahan.

| Field                       | Sumber                                              | Catatan                                |
| --------------------------- | --------------------------------------------------- | -------------------------------------- |
| `id`                        | `draft.id` (uuid)                                   | untuk aksi Upload/Edit/Hapus           |
| `item_group_id`             | `draft.product_id`                                  |                                        |
| ➕ `item_group_name`        | `draft.product.name`                                | kolom **Produk**                       |
| ➕ `thumbnail`              | media utama produk                                  | kolom **Produk**                       |
| `status`                    | `draft.status` (draft/ready/cancelled)              | badge                                  |
| ➕ `max`                    | `draft.channelShop.shop_name`                       | kolom **Store** (saat ini `shop_name`) |
| ➕ `channel_code`           | `draft.channelShop.channel.code`                    | ikon channel                           |
| ➕ `channel_name`           | `draft.channelShop.channel.name`                    |                                        |
| ➕ `channel_id`             | `draft.channelShop.channel_id`                      |                                        |
| `store_id`                  | `draft.channel_shop_id`                             |                                        |
| ➕ `active_store`           | `draft.channelShop.is_active`                       |                                        |
| `channel_category_id`       | `draft.channel_category_id`                         |                                        |
| `attribute_mapping`         | `draft.attribute_mapping`                           |                                        |
| `price_override`            | `draft.price_override`                              |                                        |
| ➕ `can_upload`             | `status !== cancelled` (lihat §7)                   | enable tombol Upload                   |
| ➕ `products[]`             | `draft.product.variants` → `{item_name, item_code}` | bila UI menampilkan varian             |
| `created_at` / `updated_at` | —                                                   |                                        |

---

## 3. Endpoint

| Method & Path                                     | Fungsi                                                                                  |
| ------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `GET /v1/products/channel-drafts`                 | **Refactor** → resource diperkaya + repository + `allowedSearch`. `meta.total` = badge. |
| `POST /v1/products/channel-drafts/{draft}/upload` | Upload satu draft → dispatch job push + buat `ProductSyncLog(pending)` + tandai draft.  |
| `POST /v1/products/channel-drafts/bulk-upload`    | Upload massal draft terpilih (checkbox).                                                |

> CRUD per-produk (`store`/`update`/`destroy`/`index`) **tetap apa adanya**.

---

## 4. Fase Implementasi

### Fase 1 — Repository (pindahkan query list + Spatie QueryBuilder)

`ProductChannelDraftRepository::paginate()`:

- `QueryBuilder::for(ProductChannelDraft::class)`
- `leftJoin('products')` + `allowedSearch('products.name','products.sku')`
- `allowedFilters`: `status`, `channel` (callback relasi), `shop_id` (callback),
  `product_id`
- Eager-load anti-N+1:
    ```php
    ->with([
      'product:id,name',
      'product.variants:id,product_id,sku',
      'product.media',
      'channelShop.channel',
    ])
    ```
- `defaultSort('-created_at')->paginate(request('per_page',10))->appends(request()->query())`
- `findDraft($id)`, (untuk bulk) ambil banyak by ids.

### Fase 2 — Service (aksi Upload)

Tambah ke `ProductChannelDraftService`:

- `uploadDraft(string $draftId)` → validasi draft (bukan cancelled), ambil
  product+shop, dispatch `SyncProductToChannelJob('push')`, buat
  `ProductSyncLog(action=upload, status=pending)`, lalu **hapus draft** (sudah
  pindah ke Hasil). Lempar `DomainException` bila invalid. Bungkus dalam transaksi.
- `bulkUpload(array $draftIds)` → loop `uploadDraft`, kembalikan ringkasan
  (`uploaded`, `skipped`).

### Fase 3 — Resource

Perkaya `ProductChannelDraftResource` sesuai §2 — helper `thumbnail()`, `products()`,
field channel/store, `can_upload`. (Reuse pola `UploadHistoryResource`.)

### Fase 4 — Controller & Route

- Refactor `list()` → pakai `ProductChannelDraftRepository` + resource diperkaya
  (`successPaginatedResponse`).
- Tambah `upload($draftId)` & `bulkUpload(Request)` (+ guard `DomainException` → 422).
- Route baru (§3).

### Fase 5 — Tests

`DraftTabFeedTest` (lengkapi `ProductDraftTest` yang ada):

- Struktur entri diperkaya (+`item_group_name`, `thumbnail`, `channel_code`,
  `products[]`, `can_upload`).
- Search/filter status/channel, default `per_page=10`.
- **Upload draft** → 200 + job ter-dispatch + `ProductSyncLog(pending)` terbuat +
  draft tertandai; draft cancelled → 422; bulk-upload ringkasan benar.
- Integrasi: setelah upload, entri muncul di `GET /v1/upload-histories` (Hasil).

---

## 5. Keputusan Desain

1. **List query → pindah ke Repository** (perbaiki pelanggaran agents.md §1).
2. **Search → `allowedSearch('products.name','products.sku')`** via join (pola sama
   dengan Upload History).
3. **`channel_id`/`store_id` → UUID** + `channel_code`/`channel_name` (konsisten).
4. **Upload dari draft** memakai `SyncProductToChannelJob('push')` yang sama dengan
   jalur existing (produk sudah ada → job otomatis create di marketplace karena
   belum ada `external_product_id`).
5. **Pencatatan upload** identik dengan jalur lama: buat `ProductSyncLog(pending)`;
   status final (success/failed) di-update oleh job (sudah diperbaiki).
6. **agents.md**: Service-Repository ✅ · Spatie QueryBuilder ✅ · `allowedSearch` ✅
   · default 10 + `appends` ✅ · ApiResponse + Resource ✅.
7. **Konvensi query param → Spatie/agents.md** (`search`, `per_page` default 10,
   `sort=-field`, `filter[...]`). **Bukan** gaya Jubelio (`q`/`page_size`/`sort_by`/
   `sort_direction`); frontend menyesuaikan. Tidak ada perubahan pada endpoint
   Hasil yang sudah jadi.

> **Referensi Jubelio** (untuk paritas konsep, bukan param): Hasil =
> `inventory/v2/items/result-upload`, Draft = `catalog/v2/mass-listing` — keduanya
> sub-tab Upload. Schema v2 ini **tidak ada** di `dist (2)/(3).yaml` (itu API WMS
> lama). Kontrak Draft mengikuti §2 (tanpa sample tambahan).

---

## 6. Checklist Artefak

- [ ] `app/Repositories/ProductChannelDraftRepository.php`
- [ ] `ProductChannelDraftService::uploadDraft` + `bulkUpload`
- [ ] Perkaya `app/Http/Resources/ProductChannelDraftResource.php`
- [ ] Refactor `ProductChannelDraftController::list` + `upload`/`bulkUpload`
- [ ] Route di `routes/api.php`
- [ ] (opsional) migration bila pakai status `uploaded` (lihat §7)
- [ ] `tests/Feature/DraftTabFeedTest.php`

---

## 7. Keputusan Final (terkunci)

1. **Nasib draft setelah Upload → HAPUS draft** (sudah pindah ke Hasil). Tanpa
   migration; tab Draft hanya berisi yang belum diupload. Operasi
   dispatch+log+delete dibungkus transaksi.
2. **Bulk upload → YA** — sediakan upload per-baris **dan** bulk (checkbox),
   konsisten tab Hasil.
3. **`products[]` di tab Draft → daftar varian penuh** (`{item_name, item_code}`)
   seperti Hasil.

> Catatan terbuka (di luar scope ini): sumber pembuatan draft (manual via CRUD
> existing vs aksi "kirim ke Draft" dari katalog/master) — dibahas terpisah.

---

## 8. Urutan Eksekusi

```
Fase 1 (repo) → Fase 2 (service upload) → Fase 3 (resource)
→ Fase 4 (controller/route) → Fase 5 (tests)
```
