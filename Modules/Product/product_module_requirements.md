# Product Module — System Requirements & Business Logic
**Document Type:** System Analysis & Requirements Specification  
**Module:** Product Management (Marketplace Integration)  
**Version:** 1.0  
**Status:** Draft for Review  

---

## Daftar Isi
1. [Overview & Konteks Bisnis](#1-overview--konteks-bisnis)
2. [Arsitektur Data](#2-arsitektur-data)
3. [State Machine Produk](#3-state-machine-produk)
4. [Tab Master](#4-tab-master)
5. [Tab In Review](#5-tab-in-review)
6. [Tab Naikkan Produk](#6-tab-naikkan-produk)
7. [Tab Download](#7-tab-download)
8. [Tab Arsip](#8-tab-arsip)
9. [Bug Aktif yang Harus Diperbaiki](#9-bug-aktif-yang-harus-diperbaiki)
10. [Use Cases — Positif & Negatif](#10-use-cases--positif--negatif)
11. [API Contract](#11-api-contract)
12. [Prioritas Implementasi](#12-prioritas-implementasi)

---

## 1. Overview & Konteks Bisnis

### 1.1 Tujuan Sistem
Sistem ini mengelola lifecycle produk seller dari berbagai marketplace (TikTok Shop, Shopee, Tokopedia, Lazada, dll) dalam satu platform terpadu. Seller dapat menarik produk dari marketplace, memverifikasi data, mengelola sebagai data induk, lalu menaikkan ke marketplace lainnya.

### 1.2 Flow Bisnis Utama
```
[Marketplace] ──pull──► [Download] ──review──► [In Review] ──approve──► [Master]
                                                                              │
                                                    [Arsip] ◄──archive────── │
                                                                              │
                                              [Naikkan Produk] ◄─────────────┘
                                                      │
                                                [Marketplace]
```

### 1.3 Aktor
| Aktor | Peran |
|-------|-------|
| Seller | Mengelola produk, upload/download, approve/arsip |
| System | Auto-sync via webhook, job queue, scheduler |
| Marketplace API | TikTok Shop, Shopee, Tokopedia, Lazada, dll |

### 1.4 Batasan Sistem
- Satu produk dapat terhubung ke banyak channel/toko (relasi melalui `product_channel_mappings`)
- Penghapusan koneksi channel (unlink) **tidak** menghapus produk dari katalog lokal
- Status produk mengikuti state machine yang ketat — tidak bisa loncat status sembarangan
- Setiap perubahan status harus tercatat (audit trail)

---

## 2. Arsitektur Data

### 2.1 Tabel Utama: `products`

```sql
products
├── id                uuid PK
├── name              string
├── description       text nullable
├── status            enum('download','in_review','master','archived') DEFAULT 'master'
├── is_draft          boolean DEFAULT false
├── is_active         boolean DEFAULT true
├── verified_at       timestamp nullable   -- diisi saat approve ke master
├── verified_by       uuid nullable FK→users
├── archived_at       timestamp nullable   -- diisi saat diarsip
├── archived_by       uuid nullable FK→users
├── archive_reason    string nullable
├── category_id       uuid nullable FK→categories
├── brand_id          uuid nullable FK→brands
├── created_at        timestamp
└── updated_at        timestamp

-- KOLOM YANG SUDAH DI-DROP (JANGAN DIPAKAI LAGI):
-- ❌ channel_product_id
-- ❌ channel_shop_id
-- ❌ source
```

### 2.2 Tabel Pivot: `product_channel_mappings`

```sql
product_channel_mappings
├── id                    uuid PK
├── product_id            uuid FK→products (cascade delete)
├── channel_shop_id       uuid FK→channel_shops (cascade delete)
├── external_product_id   string nullable   -- ID produk di marketplace
├── sync_status           enum('pending','syncing','synced','failed','deactivated')
├── error_message         text nullable
├── last_synced_at        timestamp nullable
├── created_at            timestamp
└── updated_at            timestamp

UNIQUE(product_id, channel_shop_id)
INDEX(sync_status)
INDEX(external_product_id)
```

### 2.3 Tabel Pivot: `product_variant_channel_mappings`

```sql
product_variant_channel_mappings
├── id                          uuid PK
├── product_channel_mapping_id  uuid FK→product_channel_mappings (cascade)
├── variant_id                  uuid FK→product_variants (cascade)
├── external_sku_id             string nullable
├── synced_price                decimal nullable
├── synced_stock                int nullable
├── created_at                  timestamp
└── updated_at                  timestamp

UNIQUE(product_channel_mapping_id, variant_id)
```

### 2.4 Tabel Log: `product_sync_logs` *(Perlu Dibuat)*

```sql
product_sync_logs
├── id              uuid PK
├── product_id      uuid FK→products
├── channel_shop_id uuid FK→channel_shops
├── action          enum('upload','download','sync_price','sync_stock','unlink')
├── status          enum('success','failed','pending')
├── payload         json nullable    -- data yang dikirim/diterima
├── response        json nullable    -- response dari marketplace
├── error_message   text nullable
├── executed_by     uuid nullable FK→users  -- null jika system/scheduler
├── created_at      timestamp
└── updated_at      timestamp

INDEX(product_id)
INDEX(channel_shop_id)
INDEX(action, status)
INDEX(created_at)
```

### 2.5 Tabel Draft: `product_channel_drafts` *(Perlu Dibuat)*

```sql
product_channel_drafts
├── id                    uuid PK
├── product_id            uuid FK→products (cascade)
├── channel_shop_id       uuid FK→channel_shops (cascade)
├── channel_category_id   string nullable
├── attribute_mapping     json nullable   -- mapping atribut produk↔channel
├── price_override        decimal nullable
├── status                enum('draft','ready','cancelled') DEFAULT 'draft'
├── created_by            uuid FK→users
├── created_at            timestamp
└── updated_at            timestamp

UNIQUE(product_id, channel_shop_id)
```

---

## 3. State Machine Produk

### 3.1 Diagram State

```
                    ┌─────────────────────────────────────────┐
                    │                                         │
                    ▼                                         │
              [DOWNLOAD]                                      │
            (baru dari pull)                                  │
                    │                                         │
                    │ submit for review                       │
                    ▼                                         │
            [IN REVIEW]                                       │
          (menunggu verifikasi)                               │
                 │      │                                     │
          approve│      │reject                               │
                 │      │                                     │
                 ▼      ▼                                     │
             [MASTER]  [DOWNLOAD]                             │
           (data induk)                                       │
               │  │                                          │
        archive│  │naikkan                                   │
               │  │                                          │
               ▼  ▼                                          │
          [ARCHIVED]  [Marketplace]                           │
               │                                             │
               └─────────────── restore ─────────────────────┘
```

### 3.2 Aturan Transisi State

| Dari | Ke | Aksi | Siapa |
|------|----|------|-------|
| download | in_review | submit_review | Seller |
| in_review | master | approve | Seller |
| in_review | download | reject | Seller |
| master | archived | archive | Seller |
| master | in_review | submit_review (re-review) | Seller |
| archived | master | restore | Seller |
| *(any)* | download | pull dari marketplace | System |

### 3.3 Aturan Bisnis State
- Produk dengan status `download` **tidak bisa** langsung dinaikkan ke marketplace
- Produk dengan status `archived` **tidak bisa** dinaikkan ke marketplace
- Produk dengan status `in_review` **tidak bisa** dinaikkan ke marketplace
- Hanya produk `master` yang bisa dinaikkan ke marketplace
- Produk baru yang dibuat manual oleh seller langsung berstatus `master`
- Produk yang di-pull dari marketplace masuk sebagai `download`

---

## 4. Tab Master

### 4.1 Definisi
Tab yang menampilkan semua produk yang telah diverifikasi dan menjadi **data induk** di sistem. Produk master adalah sumber kebenaran tunggal (single source of truth) untuk data produk.

### 4.2 Business Rules
- Hanya produk dengan `status = 'master'` yang tampil
- Produk master dapat diedit langsung
- Edit produk master tidak otomatis sinkronisasi ke marketplace — harus eksplisit melalui tab Naikkan Produk
- Produk master yang sudah terhubung ke channel akan menampilkan jumlah channel terhubung
- Pencarian menggunakan Full-Text Search (FTS) pada name, description, SKU

### 4.3 Business Flow
```
Seller buka tab Master
        │
        ▼
Sistem load GET /products?status=master
        │
        ▼
Tampilkan list produk dengan:
- Nama, gambar utama, SKU
- Jumlah channel terhubung
- Status sync (synced/pending/failed)
- Harga range (min-max dari variants)
        │
        ├──► Seller klik produk → GET /products/{id} → detail produk
        │
        ├──► Seller edit produk → PUT /products/{id} → update data
        │
        ├──► Seller arsip produk → POST /products/{id}/archive
        │
        └──► Seller naikkan produk → [Tab Naikkan Produk]
```

### 4.4 Validasi
| Field | Rule |
|-------|------|
| name | required, min:3, max:255 |
| description | required, min:10 |
| category_id | required, must exist |
| variants | required, min 1 variant |
| variants[].sku | required, unique per product |
| variants[].price | required, min:0 |

### 4.5 Response Data yang Dibutuhkan
```json
{
  "id": "uuid",
  "name": "string",
  "status": "master",
  "is_active": true,
  "primary_image": "url",
  "sku": "string (dari model/sku produk)",
  "price_range": { "min": 0, "max": 0 },
  "category": { "id": "uuid", "name": "string" },
  "brand": { "id": "uuid", "name": "string" },
  "channels_count": 5,
  "channel_mappings": [
    {
      "channel_shop_id": "uuid",
      "channel_name": "string",
      "shop_name": "string",
      "external_product_id": "string",
      "sync_status": "synced|pending|failed|deactivated",
      "last_synced_at": "datetime"
    }
  ],
  "variants": [...],
  "verified_at": "datetime",
  "created_at": "datetime",
  "updated_at": "datetime"
}
```

---

## 5. Tab In Review

### 5.1 Definisi
Tab yang menampilkan produk yang **menunggu verifikasi** sebelum menjadi Master. Produk masuk In Review ketika: (1) baru di-pull dari marketplace dan seller memilih untuk review dulu, atau (2) produk master yang di-re-submit untuk review ulang.

### 5.2 Business Rules
- Hanya produk dengan `status = 'in_review'` yang tampil
- Seller harus review data produk (nama, deskripsi, gambar, atribut) sebelum approve
- Approve → status berubah ke `master`, `verified_at` diisi timestamp sekarang
- Reject → status kembali ke `download`, produk bisa di-edit lalu submit ulang
- Produk `in_review` tidak bisa diedit langsung — harus reject dulu

### 5.3 Business Flow
```
Produk masuk In Review (dari pull marketplace)
        │
        ▼
Seller buka tab In Review
GET /products?status=in_review
        │
        ▼
Seller pilih produk → lihat detail
GET /products/{id}
        │
        ├──► Data sudah benar?
        │         │ YES
        │         ▼
        │    POST /products/{id}/approve
        │         │
        │         ▼
        │    status → 'master'
        │    verified_at = now()
        │    verified_by = user_id
        │
        └──► Data perlu diperbaiki?
                  │ YES
                  ▼
             POST /products/{id}/reject
                  │
                  ▼
             status → 'download'
             Seller edit → submit review ulang
```

### 5.4 Validasi Approve
- Produk harus memiliki minimal 1 variant
- Setiap variant harus memiliki SKU dan harga
- Nama produk tidak boleh kosong
- Harus ada minimal 1 gambar

### 5.5 Response Data
```json
{
  "id": "uuid",
  "name": "string",
  "status": "in_review",
  "source_channel": "tiktok|shopee|tokopedia|manual",
  "source_shop": "string",
  "primary_image": "url",
  "submitted_at": "datetime",
  "variants": [...],
  "channel_mappings": [...]
}
```

---

## 6. Tab Naikkan Produk

### 6.1 Definisi
Tab untuk **menaikkan/upload produk** dari katalog Jubelio ke marketplace. Terbagi menjadi dua sub-tab:
- **Draft**: produk yang sudah dikonfigurasi untuk upload tapi belum dieksekusi
- **Hasil**: log hasil upload (berhasil & gagal)

### 6.2 Business Rules
- Hanya produk `master` yang bisa dinaikkan
- Produk yang sudah ada di channel (sudah punya mapping) akan melakukan **update**, bukan create baru
- Satu produk bisa dinaikkan ke beberapa channel sekaligus
- Proses upload dijalankan via **Job Queue** (async) — tidak blocking
- Jika upload gagal, error harus dicatat di `product_sync_logs` dan mapping ditandai `failed`
- Draft listing menyimpan konfigurasi per-channel: kategori channel, mapping atribut, harga override

### 6.3 Business Flow — Upload Baru

```
Seller pilih produk master
        │
        ▼
GET /products/uploadable?channel=tiktok&shop_id=xxx
(produk master yang BELUM punya mapping ke shop tsb)
        │
        ▼
Seller pilih produk → konfigurasi listing
GET /products/{id}/channel-drafts (cek draft existing)
        │
        ▼
Seller isi form:
- Pilih kategori channel
- Mapping atribut produk → atribut channel
- Set harga per channel (optional override)
        │
        ▼
POST /products/{id}/channel-drafts
(simpan konfigurasi, status = 'draft')
        │
        ▼
Seller review draft → klik Upload
        │
        ▼
POST /{channel}/products (dispatch Job)
        │
        ▼
Job berjalan:
1. Ambil data produk + draft config
2. Transform ke format channel
3. Call marketplace API
        │
        ├──► SUCCESS:
        │    - Upsert product_channel_mappings
        │    - Simpan external_product_id
        │    - markAsSynced()
        │    - Catat ke product_sync_logs (success)
        │    - Update draft status = 'ready'
        │
        └──► FAILED:
             - markAsFailed($errorMessage)
             - Catat ke product_sync_logs (failed)
             - Notify seller
```

### 6.4 Business Flow — Sub-tab Draft
```
GET /products/channel-drafts?status=draft
→ list semua draft yang belum dieksekusi

Aksi yang tersedia:
- Edit draft (ubah konfigurasi)
- Upload (eksekusi)
- Cancel (hapus draft)
```

### 6.5 Business Flow — Sub-tab Hasil
```
GET /upload-histories
→ list riwayat upload dengan filter:
  - status: success | failed | pending
  - channel: tiktok | shopee | dll
  - date range
  - search by nama produk / SKU

Per item menampilkan:
- Nama produk & SKU
- Channel & toko tujuan
- Status upload
- Error message (jika gagal)
- Waktu upload
- Tombol Retry (jika gagal)
```

### 6.6 Validasi
| Kondisi | Rule |
|---------|------|
| Status produk | Harus `master` |
| Channel | Harus channel yang aktif dan terhubung ke akun seller |
| Kategori channel | Harus dipilih sebelum upload |
| Harga | Tidak boleh 0 atau negatif |
| Gambar | Minimal 1 gambar |
| SKU | Tidak boleh duplikat di channel yang sama |

### 6.7 Query "Belum Upload"
```sql
-- Produk master yang BELUM punya mapping ke shop tertentu
SELECT p.*
FROM products p
WHERE p.status = 'master'
AND p.id NOT IN (
    SELECT pcm.product_id
    FROM product_channel_mappings pcm
    WHERE pcm.channel_shop_id = :shop_id
)
```

---

## 7. Tab Download

### 7.1 Definisi
Tab untuk **menarik/mengunduh** data produk dari marketplace ke dalam sistem Jubelio. Terbagi menjadi:
- **Proses**: antrian download yang sedang berjalan
- **Hasil**: daftar produk yang sudah berhasil di-download

### 7.2 Business Rules
- Download bisa dilakukan per-produk (satuan) atau massal (bulk)
- Produk hasil download masuk dengan status `download` — belum langsung jadi Master
- Jika produk sudah ada di sistem (berdasarkan external_product_id + channel_shop_id), lakukan **update** bukan insert baru
- Proses download berjalan **async** via Job Queue
- External product ID dari marketplace **harus** disimpan di `product_channel_mappings.external_product_id`
- Download tidak otomatis approve ke Master — seller harus review dulu (kecuali ada setting auto-approve)

### 7.3 Business Flow — Download Satuan
```
Seller buka tab Download → klik Tambah Baru → pilih Download Satuan
        │
        ▼
Seller masukkan SKU / nama produk
(SKU untuk: Tokopedia, Lazada, TikTok, AladinMall)
(Nama untuk: Shopee dan platform lain)
        │
        ▼
Seller pilih channel/toko
        │
        ▼
POST /channels/{channel}/download
{ shop_id, identifier, identifier_type: 'sku'|'name' }
        │
        ▼
System call marketplace API → cari produk
        │
        ├──► FOUND:
        │    Job: pull product detail
        │    Upsert ke products (status = 'download')
        │    Upsert ke product_channel_mappings
        │    Simpan external_product_id
        │    Catat ke product_sync_logs
        │
        └──► NOT FOUND:
             Return error: "Produk tidak ditemukan di marketplace"
```

### 7.4 Business Flow — Download Massal
```
Seller pilih beberapa produk dari daftar hasil pencarian
        │
        ▼
Seller klik "Download Produk" (bulk)
        │
        ▼
POST /channels/{channel}/download/bulk
{ shop_id, product_ids: [...] }
        │
        ▼
Dispatch multiple Jobs (1 job per produk)
        │
        ▼
Seller lihat progres di sub-tab "Proses"
GET /download-histories?status=pending
        │
        ▼
Selesai → muncul di sub-tab "Hasil"
GET /download-histories?status=success|failed
```

### 7.5 Business Flow — Sub-tab Proses
```
GET /download-histories?status=pending
→ list download yang sedang antri / berjalan
→ auto-refresh setiap 30 detik
→ menampilkan: nama produk, channel, status, progress
```

### 7.6 Business Flow — Sub-tab Hasil
```
GET /download-histories
→ list semua hasil download dengan filter:
  - status: success | failed
  - channel: tiktok | shopee | dll
  - date range

Per item menampilkan:
- Nama produk & SKU
- Sumber channel & toko
- Jumlah channel tempat produk tersedia ("Tampil di X Channel")
- Status download
- Error message (jika gagal)
- Waktu download
- Tombol: Lihat Produk | Retry (jika gagal)
```

### 7.7 Upsert Logic saat Download
```
1. Cek apakah product_channel_mappings sudah ada
   WHERE channel_shop_id = :shop_id
   AND external_product_id = :external_id

2a. BELUM ADA → insert produk baru (status='download')
    + insert product_channel_mappings
    + insert product_variant_channel_mappings

2b. SUDAH ADA → update data produk
    + update product_channel_mappings (last_synced_at)
    + update product_variant_channel_mappings (synced_price, synced_stock)
    + JANGAN ubah status jika sudah 'master'
```

---

## 8. Tab Arsip

### 8.1 Definisi
Tab yang menampilkan produk yang **diarsipkan** — tidak aktif sementara tapi tidak dihapus dari sistem. Berbeda dengan deactivate di marketplace, arsip adalah konsep **lokal di Jubelio**.

### 8.2 Business Rules
- Hanya produk dengan `status = 'archived'` yang tampil
- Mengarsipkan produk di Jubelio **tidak otomatis** menonaktifkan produk di marketplace — seller harus deactivate manual di marketplace jika diperlukan
- Produk arsip **tidak bisa** dinaikkan ke marketplace sampai di-restore
- Restore produk arsip mengembalikan status ke `master`
- Produk yang sudah terhubung ke channel saat diarsip, koneksinya tetap ada — tidak otomatis unlink

### 8.3 Business Flow
```
Seller pilih produk di tab Master
        │
        ▼
Seller klik "Arsipkan"
        │
        ▼
Sistem tampilkan konfirmasi:
"Produk ini memiliki koneksi ke X channel.
Mengarsipkan produk tidak akan menonaktifkan
produk di marketplace secara otomatis.
Lanjutkan?"
        │
        ├──► Seller konfirmasi YA
        │         │
        │         ▼
        │    POST /products/{id}/archive
        │    { reason: "string" (optional) }
        │         │
        │         ▼
        │    status → 'archived'
        │    archived_at = now()
        │    archived_by = user_id
        │    archive_reason = reason
        │
        └──► Seller batalkan → tidak ada perubahan
```

### 8.4 Business Flow — Restore
```
Seller buka tab Arsip
GET /products?status=archived
        │
        ▼
Seller pilih produk → klik "Restore"
        │
        ▼
POST /products/{id}/restore
        │
        ▼
status → 'master'
archived_at = null
archived_by = null
archive_reason = null
        │
        ▼
Produk muncul kembali di tab Master
```

### 8.5 Response Data
```json
{
  "id": "uuid",
  "name": "string",
  "status": "archived",
  "primary_image": "url",
  "archived_at": "datetime",
  "archived_by": { "id": "uuid", "name": "string" },
  "archive_reason": "string|null",
  "channels_count": 3,
  "channel_mappings": [...]
}
```

---

## 9. Bug Aktif yang Harus Diperbaiki

### 9.1 Prioritas CRITICAL — Harus Selesai Sebelum Development Fitur Baru

#### BUG-001: Kolom Lama Masih Dipakai di Kode
**Lokasi:** `ProductService.php:38,83`  
**Masalah:** `Arr::only([..., 'channel_shop_id', 'source'])` mencoba tulis ke kolom yang sudah di-DROP  
**Dampak:** Create & update produk error SQL  
**Fix:** Buang `channel_shop_id` dan `source` dari `Arr::only()`

---

#### BUG-002: Query Pakai Kolom yang Sudah Di-DROP
**Lokasi:** `ChannelProductRepository.php:21`  
**Masalah:** `whereNull('channel_product_id')` — kolom tidak ada  
**Dampak:** Query "Belum Upload" error SQL  
**Fix:** Ganti ke query pivot:
```php
// SEBELUM (rusak):
->whereNull('channel_product_id')

// SESUDAH (benar):
->whereDoesntHave('channelMappings', function ($q) use ($shopId) {
    $q->where('channel_shop_id', $shopId);
})
```

---

#### BUG-003: Update ID Channel ke Kolom yang Sudah Di-DROP
**Lokasi:** `ChannelProductRepository.php:49-53`  
**Masalah:** `update(['channel_product_id' => ...])` — kolom tidak ada  
**Dampak:** Push produk tidak menyimpan external ID  
**Fix:** Ganti ke upsert pivot:
```php
// SEBELUM (rusak):
$product->update(['channel_product_id' => $externalId]);

// SESUDAH (benar):
$mapping = ProductChannelMapping::where('product_id', $product->id)
    ->where('channel_shop_id', $shopId)
    ->firstOrFail();
$mapping->markAsSynced($externalId);
```

---

#### BUG-004: TikTokProductService Baca Kolom Null
**Lokasi:** `TikTokProductService.php:173,228,269-279,311,333,344-388`  
**Masalah:** `$product->channel_product_id` selalu null karena kolom sudah di-DROP  
**Dampak:** Semua operasi push/sync/activate/deactivate/delete ke TikTok gagal dengan pesan "not synced yet"  
**Fix:**
```php
// SEBELUM (rusak):
$externalId = $product->channel_product_id;

// SESUDAH (benar):
$mapping = $product->channelMappings()
    ->where('channel_shop_id', $shopId)
    ->first();

if (!$mapping || !$mapping->external_product_id) {
    throw new ProductNotSyncedException("Produk belum tersinkronisasi ke channel ini");
}

$externalId = $mapping->external_product_id;
```

---

#### BUG-005: Download Tidak Buat Record Mapping
**Lokasi:** `pullProducts() / upsertFromChannel()`  
**Masalah:** Saat download dari TikTok, produk disimpan tapi `ProductChannelMapping` tidak dibuat  
**Dampak:** `external_product_id` TikTok hilang, produk tidak terhubung ke channel asal  
**Fix:** Setelah upsert produk, selalu buat/update mapping:
```php
$product = $this->productService->upsertFromChannel($data);

ProductChannelMapping::updateOrCreate(
    [
        'product_id'      => $product->id,
        'channel_shop_id' => $channelShopId,
    ],
    [
        'external_product_id' => $data['external_product_id'],
        'sync_status'         => 'synced',
        'last_synced_at'      => now(),
    ]
);
```

---

#### BUG-006: updateAndPushProduct Tidak Dispatch Job
**Lokasi:** `ChannelProductService.php:41-51`  
**Masalah:** Pesan response bilang "antrean sinkronisasi dijalankan" tapi Job tidak di-dispatch  
**Dampak:** Update produk tidak pernah sampai ke marketplace  
**Fix:**
```php
// SEBELUM (rusak):
// ... update lokal ...
return response()->json(['message' => 'antrean sinkronisasi dijalankan']);

// SESUDAH (benar):
// ... update lokal ...
dispatch(new SyncProductToChannelJob($product, $channelShop));
return response()->json(['message' => 'antrean sinkronisasi dijalankan']);
```

---

#### BUG-007: Migration No-Op Rusak
**Lokasi:** `...094003_drop_unused_tiktok_columns_from_products_and_variants`  
**Masalah:** Body migration kosong, target tabel `products_and_variants` tidak ada  
**Dampak:** Migration akan error jika dijalankan ulang  
**Fix:** Hapus file migration ini

---

#### BUG-008: GET /products/{id} Return HTML
**Lokasi:** `ProductController@show`  
**Masalah:** Return `view('product::show')` bukan JSON  
**Dampak:** API client tidak bisa mengambil detail produk  
**Fix:** Implementasi JSON response dengan ProductResource

---

#### BUG-009: Channel Routes Tanpa Auth
**Lokasi:** `routes/api.php:35-48`  
**Masalah:** Komentar "Temporarily Unprotected" — tanpa `auth:sanctum`  
**Dampak:** Security vulnerability — siapapun bisa akses  
**Fix:** Pasang middleware auth

---

## 10. Use Cases — Positif & Negatif

### UC-01: Download Produk dari Marketplace

| # | Skenario | Input | Expected Output | Status Code |
|---|----------|-------|-----------------|-------------|
| P1 | Download produk valid dari TikTok | SKU valid + shop_id valid | Produk tersimpan, mapping dibuat, status=download | 201 |
| P2 | Download produk yang sudah ada (update) | SKU yang sudah ada di DB | Data produk terupdate, mapping terupdate | 200 |
| P3 | Download bulk 10 produk | Array 10 product_ids valid | 10 jobs di-dispatch, response immediate | 202 |
| N1 | SKU tidak ditemukan di marketplace | SKU tidak ada | Error: "Produk tidak ditemukan" | 404 |
| N2 | Shop tidak terhubung | shop_id tidak valid | Error: "Toko tidak ditemukan atau tidak aktif" | 422 |
| N3 | Token marketplace expired | — | Error: "Koneksi marketplace bermasalah, harap reconnect" | 503 |
| N4 | Request tanpa auth | — | Error: "Unauthorized" | 401 |
| N5 | Download produk yang sudah di-arsip | — | Produk terupdate (status tidak berubah dari archived) | 200 |
| N6 | Marketplace API timeout | — | Job di-retry max 3x, jika tetap gagal catat failed | 202 |
| N7 | Data produk tidak lengkap dari marketplace | Produk tanpa gambar | Tersimpan dengan peringatan, field kosong dibiarkan null | 201 |

---

### UC-02: Approve Produk ke Master

| # | Skenario | Input | Expected Output | Status Code |
|---|----------|-------|-----------------|-------------|
| P1 | Approve produk lengkap | product_id valid, status=in_review | status=master, verified_at=now() | 200 |
| P2 | Approve produk dengan semua field terisi | — | Produk muncul di tab Master | 200 |
| N1 | Approve produk bukan in_review | product_id dengan status=master | Error: "Produk tidak dalam status In Review" | 422 |
| N2 | Approve produk tanpa variant | — | Error: "Produk harus memiliki minimal 1 variant" | 422 |
| N3 | Approve produk tanpa gambar | — | Error: "Produk harus memiliki minimal 1 gambar" | 422 |
| N4 | Approve produk tidak ditemukan | product_id tidak ada | Error: "Produk tidak ditemukan" | 404 |
| N5 | Seller approve produk milik seller lain | — | Error: "Unauthorized" | 403 |
| N6 | Approve produk yang diarsip | — | Error: "Produk tidak dalam status In Review" | 422 |

---

### UC-03: Upload/Naikkan Produk ke Marketplace

| # | Skenario | Input | Expected Output | Status Code |
|---|----------|-------|-----------------|-------------|
| P1 | Upload produk baru ke channel | product_id master + shop_id | Job dispatched, mapping dibuat pending | 202 |
| P2 | Upload produk yang sudah ada (update) | product_id yang sudah punya mapping | Job dispatched, mapping update | 202 |
| P3 | Bulk upload semua produk ke 1 toko | shop_id | Semua produk master di-queue | 202 |
| P4 | Upload berhasil (Job success) | — | mapping.sync_status=synced, log=success | async |
| N1 | Upload produk in_review | — | Error: "Hanya produk Master yang bisa dinaikkan" | 422 |
| N2 | Upload produk arsip | — | Error: "Hanya produk Master yang bisa dinaikkan" | 422 |
| N3 | Upload ke shop tidak aktif | — | Error: "Toko tidak ditemukan atau tidak aktif" | 422 |
| N4 | Upload gagal di marketplace (Job failed) | — | sync_status=failed, error dicatat, seller dinotifikasi | async |
| N5 | Upload tanpa kategori channel dipilih | — | Error: "Kategori channel harus dipilih sebelum upload" | 422 |
| N6 | SKU duplikat di channel | — | Error dari marketplace, dicatat sebagai failed | async |
| N7 | Rate limit marketplace API | — | Job di-retry dengan exponential backoff | async |
| N8 | Produk tidak ditemukan saat Job jalan | — | Job failed, log dicatat, tidak retry | async |

---

### UC-04: Arsip & Restore Produk

| # | Skenario | Input | Expected Output | Status Code |
|---|----------|-------|-----------------|-------------|
| P1 | Arsip produk master | product_id master | status=archived, archived_at=now() | 200 |
| P2 | Arsip dengan alasan | product_id + reason | status=archived, archive_reason tersimpan | 200 |
| P3 | Restore produk arsip | product_id archived | status=master, archived_at=null | 200 |
| P4 | Arsip produk dengan koneksi channel | — | Produk diarsip, mapping tetap ada (tidak unlink) | 200 |
| N1 | Arsip produk in_review | — | Error: "Hanya produk Master yang bisa diarsipkan" | 422 |
| N2 | Arsip produk yang sudah diarsip | — | Error: "Produk sudah dalam status Arsip" | 422 |
| N3 | Restore produk yang bukan arsip | — | Error: "Produk tidak dalam status Arsip" | 422 |
| N4 | Arsip produk tidak ditemukan | — | Error: "Produk tidak ditemukan" | 404 |
| N5 | Restore produk milik seller lain | — | Error: "Unauthorized" | 403 |

---

### UC-05: Unlink Produk dari Channel

| # | Skenario | Input | Expected Output | Status Code |
|---|----------|-------|-----------------|-------------|
| P1 | Unlink produk dari 1 channel | product_id + shop_id | Row mapping dihapus, produk lokal tetap ada | 200 |
| P2 | Unlink produk yang sudah failed | — | Row mapping dihapus | 200 |
| N1 | Unlink produk yang tidak punya mapping | — | Error: "Produk tidak terhubung ke channel ini" | 404 |
| N2 | Unlink produk yang sedang syncing | — | Error: "Tidak bisa unlink saat proses sync berjalan" | 409 |
| N3 | Delete (bukan unlink) produk dari channel | — | Produk lokal ikut terhapus (perilaku saat ini — HARUS DIPISAH) | — |

---

### UC-06: Sinkronisasi Harga & Stok

| # | Skenario | Input | Expected Output | Status Code |
|---|----------|-------|-----------------|-------------|
| P1 | Update harga ke channel | product_id + shop_id + price | Job dispatched, synced_price terupdate | 202 |
| P2 | Update stok ke channel | product_id + shop_id + stock | Job dispatched, synced_stock terupdate | 202 |
| N1 | Update harga 0 | price: 0 | Error: "Harga tidak boleh 0 atau negatif" | 422 |
| N2 | Update stok negatif | stock: -1 | Error: "Stok tidak boleh negatif" | 422 |
| N3 | Produk belum tersinkronisasi ke channel | — | Error: "Produk belum dinaikkan ke channel ini" | 422 |
| N4 | Channel API error | — | Job failed, sync_status=failed, retry | async |

---

## 11. API Contract

### 11.1 Endpoint Baru yang Perlu Dibuat

```
# PRODUK - FILTER STATUS
GET    /api/v1/products?status={master|in_review|download|archived}
GET    /api/v1/products/{id}                    ← fix stub
PUT    /api/v1/products/{id}                    ← fix stub

# LIFECYCLE
POST   /api/v1/products/{id}/approve            ← NEW
POST   /api/v1/products/{id}/reject             ← NEW
POST   /api/v1/products/{id}/archive            ← NEW
POST   /api/v1/products/{id}/restore            ← NEW

# UPLOAD
GET    /api/v1/products/uploadable              ← NEW (belum upload per channel)
GET    /api/v1/products/{id}/channel-drafts     ← NEW
POST   /api/v1/products/{id}/channel-drafts     ← NEW
PUT    /api/v1/products/{id}/channel-drafts/{draft_id} ← NEW
DELETE /api/v1/products/{id}/channel-drafts/{draft_id} ← NEW

# UNLINK (pisah dari DELETE)
DELETE /api/v1/{channel}/products/{id}/link     ← NEW

# DOWNLOAD GENERIC
POST   /api/v1/{channel}/download               ← NEW (generalisasi pullProducts)
POST   /api/v1/{channel}/download/bulk          ← NEW

# HISTORIES
GET    /api/v1/upload-histories                 ← NEW
GET    /api/v1/download-histories               ← NEW
```

### 11.2 Query Parameters Standard

```
GET /api/v1/products
  ?status=master|in_review|download|archived
  &page=1
  &limit=20
  &search=keyword
  &sort=created_at|name|updated_at
  &order=asc|desc
  &filter[is_active]=true|false
  &filter[channel]=tiktok|shopee|tokopedia
  &filter[shop_id]=uuid

GET /api/v1/products/uploadable
  ?channel=tiktok|shopee|tokopedia (required)
  &shop_id=uuid (required)
  &page=1
  &limit=20
  &search=keyword

GET /api/v1/upload-histories
  ?status=success|failed|pending
  &channel=tiktok|shopee
  &shop_id=uuid
  &date_from=YYYY-MM-DD
  &date_to=YYYY-MM-DD
  &page=1
  &limit=20
```

### 11.3 Standard Response Format

```json
// Success - Single
{
  "status": "success",
  "data": { ... },
  "message": "string"
}

// Success - Collection
{
  "status": "success",
  "data": [...],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 100,
    "last_page": 5
  }
}

// Success - Async (Job dispatched)
{
  "status": "success",
  "message": "Proses sedang berjalan di background",
  "data": {
    "job_id": "uuid"
  }
}

// Error - Validation
{
  "status": "error",
  "message": "Validasi gagal",
  "errors": {
    "field_name": ["pesan error"]
  }
}

// Error - Business Logic
{
  "status": "error",
  "message": "Pesan error yang jelas untuk user",
  "code": "PRODUCT_WRONG_STATUS|PRODUCT_NOT_FOUND|..."
}
```

---

## 12. Prioritas Implementasi

### Phase 1 — Fix Bug Aktif *(Minggu 1)*
> Tidak ada fitur baru, murni perbaikan. Setelah ini Download & Naikkan Produk bisa jalan.

- [ ] BUG-001: Buang kolom lama dari ProductService
- [ ] BUG-002: Rewrite getUnsyncedProducts ke pivot
- [ ] BUG-003: Rewrite updateChannelProductId ke pivot
- [ ] BUG-004: Refactor TikTokProductService baca external_id dari mapping
- [ ] BUG-005: Download buat record ProductChannelMapping
- [ ] BUG-006: Fix dispatch Job di updateAndPushProduct
- [ ] BUG-007: Hapus migration no-op
- [ ] BUG-009: Pasang auth pada channel routes

### Phase 2 — Fondasi Status *(Minggu 1-2)*
> Satu migration + filter endpoint. Setelah ini semua tab bisa dibedakan.

- [x] Migration: tambah kolom `status`, `verified_at`, `verified_by`, `archived_at`, `archived_by`, `archive_reason`
- [x] Implementasi `GET /products?status=` (top-level query, + validasi nilai status)
- [x] Perkaya ProductResource (status, sku, primary_image, price_range, category, brand, channels_count, channel_mappings, verified_at, archived_*)
- [x] BUG-008: Fix GET /products/{id} return JSON (+ guard id non-UUID → 404)

### Phase 3 — Tab Master & Arsip *(Minggu 2)*
- [ ] PUT /products/{id} implementasi update
- [ ] POST /products/{id}/archive
- [ ] POST /products/{id}/restore
- [ ] POST /products/{id}/approve
- [ ] POST /products/{id}/reject

### Phase 4 — Tab Naikkan Produk & Download *(Minggu 3)*
- [ ] GET /products/uploadable
- [ ] DELETE /{channel}/products/{id}/link (unlink)
- [ ] POST /{channel}/download (generic)
- [ ] POST /{channel}/download/bulk

### Phase 5 — Draft & Logging *(Minggu 4)*
- [ ] Migration: tabel product_channel_drafts
- [ ] Migration: tabel product_sync_logs
- [ ] CRUD /products/{id}/channel-drafts
- [ ] GET /upload-histories
- [ ] GET /download-histories

---

*Dokumen ini adalah living document — perlu diupdate seiring development berlangsung.*  
*Last Updated: 2026-06-08*
