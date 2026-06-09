# Planning: Upload History Feed — Tab "Hasil" (setara `upload.json` + UI)

> Tujuan: menyajikan **riwayat hasil upload produk ke marketplace** (tab "Hasil")
> beserta aksi **Re-upload** & **Hapus**, dengan retensi **30 hari** untuk yang
> sukses. Struktur data setara `Modules/Product/upload.json` (ter-group per event,
> `products[]` = varian), memakai **UUID**.
>
> Referensi: cabang `27-product`, modul `Modules/Product`. Mengikuti pola Master
> Feed (`master-feed-implementation-plan.md`) dan standar `agents.md`.

---

## 0. Kebutuhan dari UI (tab "Hasil")

Dari desain UI:
- **Tabs**: Draft | Hasil, **Total** (badge jumlah), tombol refresh.
- **Banner**: "Produk yang berhasil di-upload ke channel lebih dari 30 hari akan
  otomatis terhapus dari halaman ini." → **retensi 30 hari untuk status sukses**.
- **Kolom tabel**: checkbox · **Produk** (thumbnail + nama) · **Store** (ikon
  channel + nama toko) · **Tgl. Upload** (tanggal + jam WIB) · **Status** (Sukses
  hijau / pesan error merah) · **Tindakan** (**Re-upload** + **Hapus**).
- **Re-upload**: aktif hanya untuk baris **gagal**; non-aktif untuk sukses.
- **Hapus**: per baris (ikon trash); checkbox header → indikasi **aksi massal**.

Implikasi: fitur ini **bukan read-only** — ada endpoint aksi + retensi terjadwal.

---

## 1. Kondisi Sekarang (audit)

| Komponen | Status |
|---|---|
| Tabel `product_sync_logs` (`action=upload`, status, payload, response, error_message, executed_by) | ✅ Ada |
| Penulisan log saat upload (`ChannelProductService` → `ProductSyncLog::record`) | ✅ Ada |
| Trigger upload (`SyncProductToChannelJob('push')` → `TikTokAdapter::pushProduct`) | ✅ Ada (TikTok real) |
| Endpoint `GET /v1/upload-histories` | ✅ Ada (output flat `ProductSyncLogResource`) |
| Query di **controller** + filter manual | ⚠️ Langgar agents.md §1/§3 |
| `products[]` (varian per event) | ❌ Belum |
| Aksi Re-upload / Hapus | ❌ Belum |
| Retensi 30 hari (auto-prune sukses) | ❌ Belum |

**Tidak perlu migration** — semua data sumber sudah tersimpan.

---

## 2. Kontrak Data (penyesuaian `upload.json` untuk UI)

Tiap entri = **satu event upload** (`product × store`) = **satu baris
`product_sync_logs(action=upload)`**. Field `upload.json` dipertahankan, **ditambah**
yang dibutuhkan UI (ditandai ➕):

### 2.1 Level entri ← `product_sync_logs` + relasi
| Field | Sumber | Catatan |
|---|---|---|
| ➕ `id` | `log.id` (uuid) | **wajib** untuk aksi Re-upload/Hapus |
| `item_group_id` | `log.product_id` (uuid) | |
| `item_group_name` | `log.product.name` | kolom **Produk** |
| `thumbnail` | media utama produk | kolom **Produk** |
| `upload_date` | `log.created_at` | kolom **Tgl. Upload** |
| `success` | `log.status === 'success'` | kolom **Status** |
| `status_message` | derivasi status (lihat §5.4) | "Sukses" / pesan error |
| `max` | `log.channelShop.shop_name` | nama toko (kolom **Store**) |
| ➕ `channel_code` | `log.channelShop.channel.code` | **ikon channel** di UI |
| ➕ `channel_name` | `log.channelShop.channel.name` | |
| `channel_id` | `log.channelShop.channel_id` (uuid) | |
| `store_id` | `log.channelShop.id` (uuid) | |
| `active_store` | `log.channelShop.is_active` | badge "Online"/aktif |
| `channel_url` | mapping `channel_url` fallback `ChannelUrlBuilder` | link nama toko |
| ➕ `can_reupload` | `!success` | enable/disable tombol Re-upload |
| `products[]` | `log.product.variants` → `{item_name, item_code}` | lihat 2.2 |

### 2.2 `products[]` ← `product_variants`
| Field | Sumber |
|---|---|
| `item_name` | `product.name` |
| `item_code` | `variant.sku` |

> `upload.json` saat ini akan disesuaikan menambah `id`, `channel_code`,
> `channel_name`, `can_reupload`. Field numerik Jubelio (`channel_id=64`) diganti
> UUID + `channel_code` (konsisten Master Feed).

---

## 3. Endpoint

| Method & Path | Fungsi |
|---|---|
| `GET /v1/upload-histories` | **Refactor** → bentuk `upload.json` + UI (list, filter, search, paginate). `meta.total` = badge Total. |
| `POST /v1/upload-histories/{id}/re-upload` | Re-trigger upload (hanya untuk entri gagal) → dispatch `SyncProductToChannelJob('push')` + catat log baru. |
| `DELETE /v1/upload-histories/{id}` | Hapus satu entri riwayat. |
| `POST /v1/upload-histories/bulk-delete` | Hapus massal (checkbox). |

> `download-histories` **ditunda** (tetap bentuk lama, di luar scope ini).

---

## 4. Fase Implementasi

### Fase 1 — Repository (Spatie QueryBuilder)
`UploadHistoryRepository::paginate()`:
- `QueryBuilder::for(ProductSyncLog::class)->where('action', ACTION_UPLOAD)`
- **Retensi list**: sembunyikan sukses > 30 hari
  `->where(fn($q) => $q->where('status','!=','success')->orWhere('created_at','>=', now()->subDays(30)))`
- `allowedFilters`: `status`, channel (`AllowedFilter::callback` → relasi
  `channelShop.channel.code`), `shop_id`, `date_from`/`date_to`.
- `allowedSearch('product.name','product.sku')` (callback ke relasi produk).
- Eager-load anti-N+1:
  ```php
  ->with([
    'product:id,name',
    'product.variants:id,product_id,sku',
    'product.media',
    'product.channelMappings:id,product_id,channel_shop_id,channel_url',
    'channelShop.channel',
  ])
  ```
- `->defaultSort('-created_at')->paginate(request('per_page',10))->appends(request()->query())`

### Fase 2 — Service
`UploadHistoryService`:
- `paginate(array $filters)` → repository.
- `reupload(string $logId)` → validasi entri **gagal**, ambil product+shop,
  dispatch `SyncProductToChannelJob('push')`, lalu **update baris log yang sama**
  (`status=pending`, `error_message=null`, `updated_at` baru). Lempar
  `DomainException` bila entri sukses (tak boleh re-upload).
- `delete(string $logId)` / `bulkDelete(array $ids)`.
- `pruneExpired()` → hapus `action=upload AND status=success AND created_at < now()-30d`.

### Fase 3 — Resource
`UploadHistoryResource` (lihat §2) — helper `statusMessage()`, `thumbnail()`,
`channelUrlFor($shopId)` (cari mapping produk untuk shop + fallback `ChannelUrlBuilder`),
`products` dari `product.variants`, `can_reupload`.

### Fase 4 — Controller & Route
- Refactor `ProductSyncLogController::uploadHistories` → pakai
  `UploadHistoryService` + `UploadHistoryResource` (`successPaginatedResponse`).
- Tambah `reupload`, `destroy`, `bulkDestroy`. Bungkus `DomainException` → 422
  (pola `ProductMergeController::guard`).
- Route baru di `routes/api.php` (lihat §3).

### Fase 5 — Retensi terjadwal
- Command `php artisan products:prune-upload-histories` → `service->pruneExpired()`.
- Jadwalkan harian di scheduler (`app/Console` / module service provider).

### Fase 6 — Tests
`UploadHistoryFeedTest`:
- Struktur entri cocok kontrak (+`id`, `channel_code`, `can_reupload`, `products[]`).
- `success`/`status_message` (sukses & gagal), `products[]` = jumlah varian.
- Filter status/channel/search, default `per_page=10`, `meta.total`.
- **Retensi**: sukses >30 hari tak muncul; gagal >30 hari tetap muncul.
- **Re-upload**: entri gagal → 200 + job ter-dispatch + baris log jadi `pending`
  (`error_message` null); entri sukses → 422.
- **Hapus** satu & massal.
- `prune-upload-histories` command menghapus sukses >30 hari saja.

---

## 5. Keputusan Desain

1. **Grouping → per-event** (tiap baris log = satu entri; sesuai UI yang
   menampilkan beberapa upload untuk produk yang sama lintas store/waktu).
2. **Endpoint → refactor** `GET /v1/upload-histories` (bukan endpoint baru).
3. **`download-histories` → ditunda.**
4. **`status_message`**: `success`→`"Sukses"`; `failed`→`error_message` (fallback
   `"Gagal"`); `pending`→`"Sedang diproses"`.
5. **`channel_id`/`store_id` → UUID** + `channel_code`/`channel_name` (ikon & label).
6. **`max` = `shop_name`** (key dipertahankan demi paritas `upload.json`).
7. **`channel_url`** reuse `ChannelUrlBuilder` + kolom tersimpan.
8. **Retensi 30 hari**: ganda — (a) filter list menyembunyikan sukses >30 hari
   agar UI langsung konsisten, (b) command terjadwal benar-benar menghapus.
   Entri **gagal tidak pernah auto-terhapus** (perlu Re-upload).
9. **agents.md**: Service-Repository ✅ · Spatie QueryBuilder di Repository ✅ ·
   `allowedSearch`/`?search=` ✅ · default 10 `per_page` + `appends` ✅ ·
   ApiResponse + Resource ✅.

---

## 6. Checklist Artefak

- [ ] `app/Repositories/UploadHistoryRepository.php`
- [ ] `app/Services/UploadHistoryService.php`
- [ ] `app/Http/Resources/UploadHistoryResource.php`
- [ ] `app/Console/Commands/PruneUploadHistories.php` + jadwal harian
- [ ] Refactor `app/Http/Controllers/ProductSyncLogController.php` (+ reupload/destroy/bulkDestroy)
- [ ] Route di `routes/api.php`
- [ ] (opsional) Update sample `Modules/Product/upload.json` ke kontrak baru
- [ ] `tests/Feature/UploadHistoryFeedTest.php`
- [ ] (tanpa migration)

---

## 7. Edge Cases

- **Produk tanpa varian** → `products: []`.
- **Log tanpa `channel_shop`** → `max/channel_*/store_id/active_store` = `null`.
- **`channel_url` kosong** → `null`.
- **Produk soft-deleted** → log tetap; `item_group_name`/`thumbnail` bisa `null`.
- **Re-upload entri yang produknya sudah dihapus** → 422 (produk tak tersedia).
- **`pending`** → `success=false`, `can_reupload=false` (sedang diproses).
- **Retensi**: hanya `status=success`; `failed`/`pending` tetap ditampilkan.

---

## 8. Keputusan Final (terkunci)

1. **Aksi massal → bulk-delete saja** (tanpa bulk re-upload).
2. **Retensi → hard delete row log** sukses >30 hari (command terjadwal benar-benar
   menghapus baris). Entri gagal/pending tidak pernah auto-hapus.
3. **Re-upload → update baris log yang sama** (`status=pending`, `error_message`
   dikosongkan); bukan membuat baris baru.

---

## 9. Urutan Eksekusi

```
Fase 1 (repo) → Fase 2 (service) → Fase 3 (resource)
→ Fase 4 (controller/route) → Fase 5 (retensi/command) → Fase 6 (tests)
```
