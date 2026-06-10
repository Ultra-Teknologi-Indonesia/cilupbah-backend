# Planning — Tab Produk Channel (Channel Product Listing)

## 1. Tujuan

Menyediakan endpoint daftar **Produk Channel** = koneksi SKU antara katalog internal (master) dan marketplace, dengan **struktur respons identik `channel-product.json`**.

Satu baris = satu **listing di marketplace** (`channel_group_id`), berisi daftar `product[]` yang memetakan **master_sku** (SKU internal) ↔ **channel_sku** (SKU di marketplace). Ini menggantikan endpoint Jubelio `inventory/v2/items/product-channel/`.

## 2. Pemetaan data (JSON ↔ skema kita)

Sumber utama: `ProductChannelMapping` (listing channel) + relasi `variantMappings` (`ProductVariantChannelMapping`).

### Item luar (`data[]`)
| Field JSON | Sumber | Catatan |
|---|---|---|
| `channel_group_id` | `pcm.external_product_id` | ID listing di marketplace |
| `store_id` | `pcm.channel_shop_id` | **UUID** (Jubelio pakai int) |
| `item_group_name` | `pcm.product.name` | nama produk |
| `min` | `pcm.channelShop.shop_name` | nama toko (penamaan Jubelio aneh; kita replikasi) |
| `channel_id` | `pcm.channelShop.channel_id` | **UUID** channel kita |
| `channel_url` | `pcm.channel_url` ?: `ChannelUrlBuilder::build(...)` | reuse builder yang ada |
| `product` | `pcm.variantMappings[]` | lihat di bawah |
| `has_product_data` | `pcm.variantMappings->isNotEmpty()` | boolean |

### Item dalam (`product[]`) — koneksi SKU per varian
| Field JSON | Sumber | Catatan |
|---|---|---|
| `store_id` | `pcm.channel_shop_id` | UUID |
| `max_price` / `min_price` | `vcm.override_price ?? variant.sell_price` | harga efektif channel; sama bila tak ada override |
| `thumbnail` | media varian → fallback media produk | reuse logika thumbnail varian |
| `channel_id` | `pcm.channelShop.channel_id` | UUID |
| `master_sku` | `vcm.variant.sku` | SKU internal |
| `store_name` | `pcm.channelShop.shop_name` | |
| `channel_sku` | `vcm.external_sku_id` ?: `vcm.variant.sku` | SKU di marketplace |
| `product_variation` | `vcm.variant.options` digabung `", "` | mis. "AGS Sky Blue, 13 Pro Max" |

### Tambahan kita (di luar shape Jubelio, opsional tapi berguna FE)
- `channel_code` (tiktok/shopee) di item luar — Jubelio hanya kirim `channel_id` numerik; kita kirim juga `channel_code` agar FE tak perlu mapping angka.
- `sync_status` per listing (synced/failed/pending) — supaya tab bisa menandai koneksi bermasalah.

## 3. Keputusan desain

### 3.1 Endpoint
- `GET /v1/products/channel-products` — daftar listing (paginated).
- `GET /v1/products/channel-products/{id}` — detail 1 listing (by `ProductChannelMapping.id`), shape item yang sama.

Diletakkan **sebelum** `apiResource('products')` di `routes/api.php` (agar tak tertangkap `products/{id}`), berdampingan dengan master/reviews/archives.

> Catatan penamaan: `ChannelProductController` (per-channel CRUD `v1/{channel}/products`) **sudah ada** dan berbeda peran. Fitur ini pakai kelas baru ber-prefix `ChannelProductListing*` agar tidak bentrok.

### 3.2 Konvensi query (Spatie / agents.md — bukan gaya Jubelio)
Konsisten dengan endpoint lain yang sudah dibangun:
- `search=` (FTS: nama produk & SKU) — *bukan* `q`
- `per_page=` default **10** — *bukan* `page_size`
- `sort=-created_at` (default) memetakan "download_date DESC" Jubelio — *bukan* `sort_by`/`sort_direction`
- `filter[channel]=tiktok`, `filter[shop_id]=...`, `filter[sync_status]=synced`
- Envelope: `successPaginatedResponse` (`data` + `meta`). `totalCount` Jubelio ⇒ `meta.total`.

### 3.3 Sumber & paginasi
- Paginasi atas baris `ProductChannelMapping` (1 baris = 1 listing).
- `whereHas('variantMappings')` opsional? **Tidak** — Jubelio juga menampilkan listing dengan `has_product_data:false`. Jadi tampilkan semua listing; `has_product_data` menandai yang kosong.
- Eager load: `product`, `channelShop.channel`, `variantMappings.variant.options.attribute`, `variantMappings.variant.media` (atau `product.media`).

### 3.4 agents.md (WAJIB)
Query DB hanya di repository; Spatie QueryBuilder; `ApiResponse` trait; `AllowedFilter::callback`/`exact`, `AllowedSort`; **tanpa komentar** di kode.

## 4. Perubahan file

### 4.1 Baru
1. **`app/Http/Resources/ChannelProductListingResource.php`**
   Bentuk item luar + nested `product[]` (lewat helper privat `connections()` yang map `variantMappings`). Reuse `ChannelUrlBuilder` untuk `channel_url`. Guard `relationLoaded()` di setiap relasi (pola sama seperti `MasterItemResource`).

2. **`app/Repositories/ChannelProductListingRepository.php`**
   `QueryBuilder::for(ProductChannelMapping::class)` + RELATIONS, `allowedSearch` (nama produk + sku via leftJoin bila perlu — lihat 5.1), `allowedFilters(channel, shop_id, sync_status)`, `allowedSorts(created_at, last_synced_at)`, `defaultSort('-created_at')`, `paginate(per_page,10)`. Method `find($id)`.

3. **`app/Services/ChannelProductListingService.php`**
   `paginate()` & `find($id)` delegasi ke repo.

4. **`app/Http/Controllers/ChannelProductListingController.php`**
   `index()` (validate; map ke resource; `successPaginatedResponse`) & `show()` (find / `errorResponse 404 "Listing produk channel tidak ditemukan"`).

### 4.2 Diubah
5. **`Modules/Product/routes/api.php`** — tambah 2 route + `use ...ChannelProductListingController;`.

### 4.3 Mungkin perlu
6. **Relasi model** — pastikan `ProductVariant` punya relasi `media` & `options` ter-load. Sudah dipakai di MasterItemResource (`variants.options.attribute`, `media` di level product). Untuk thumbnail per varian, ikuti pola `MasterItemResource::variantThumbnail()` (media produk difilter `variant_id`). Tidak ada migrasi baru.

## 5. Catatan teknis

### 5.1 Search nama + SKU
`ProductChannelMapping` tak punya kolom `name`/`sku`. Dua opsi:
- **(A, rekomendasi)** `allowedSearch` macro pada kolom hasil `leftJoin('products', ...)` + subquery `whereHas('variantMappings.variant', sku LIKE)` — konsisten dgn `UploadHistoryRepository` yang sudah leftJoin products.
- (B) filter terpisah `filter[product_name]` & `filter[sku]` callback. Lebih sederhana tapi beda UX.
Pilih **A** agar 1 kotak search seperti Jubelio.

### 5.2 download_date
Tak ada kolom `download_date`. `created_at` listing = waktu mapping dibuat (saat produk di-download/di-link) → padanan paling dekat. Default sort `-created_at`.

### 5.3 max/min price
Per varian, `override_price ?? sell_price`. Field `max_price`/`min_price` Jubelio per baris identik; kita isi sama. (Range nyata hanya relevan bila 1 channel_sku memetakan banyak harga — tak terjadi di model kita.)

## 6. Bentuk respons (ringkas)
```jsonc
{
  "data": [
    {
      "channel_group_id": "1735676684505023778",
      "store_id": "uuid-shop",
      "item_group_name": "CILUPBAH ... Case",
      "min": "Cilupbah ID Mall",
      "channel_id": "uuid-channel",
      "channel_code": "tiktok",          // tambahan kita
      "channel_url": "https://shop.tiktok.com/view/product/1735676684505023778",
      "sync_status": "synced",           // tambahan kita
      "has_product_data": true,
      "product": [
        {
          "store_id": "uuid-shop",
          "max_price": 200000,
          "min_price": 200000,
          "thumbnail": "https://...jpeg",
          "channel_id": "uuid-channel",
          "master_sku": "AGS-WHITE-IP-15-PROMAX",
          "store_name": "Cilupbah ID Mall",
          "channel_sku": "AGS-WHITE-IP-15-PROMAX",
          "product_variation": "AGS Butter Yellow, 15 Pro Max"
        }
      ]
    }
  ],
  "meta": { "per_page": 10, "total": 2694, ... }
}
```

## 7. Test — `ChannelProductListingTest` (PHPUnit + RefreshDatabase + withoutMiddleware)
1. `test_list_returns_listings_grouped_by_channel_with_sku_connections` — struktur lengkap + `product[]` memetakan master_sku↔channel_sku + `product_variation` dari opsi varian.
2. `test_default_pagination_is_ten`.
3. `test_has_product_data_false_when_no_variant_mappings`.
4. `test_channel_url_built_when_empty` (reuse ChannelUrlBuilder).
5. `test_search_by_product_name_and_sku`.
6. `test_filter_by_channel_and_shop_id`.
7. `test_filter_by_sync_status`.
8. `test_default_sort_is_created_at_desc`.
9. `test_show_returns_single_listing`.
10. `test_show_unknown_returns_404`.

## 8. Urutan eksekusi
- **Fase 1** — Resource + Repository.
- **Fase 2** — Service + Controller + routes.
- **Fase 3** — Test, `rtk php artisan test --filter=ChannelProductListing`, lalu regresi modul Product.
- **Fase 4** — commit `feat(product): tab Produk Channel (koneksi SKU master ↔ marketplace)` + push.

## 9. Di luar scope (klarifikasi "mengelola")
Endpoint ini **read/list**. Aksi kelola koneksi SKU sudah/tinggal pakai yang ada:
- Putus koneksi: `DELETE v1/{channel}/products/{id}/link` (`ChannelProductController::unlink`) — sudah ada.
- Ubah harga/stok channel: `PUT .../price`, `.../stock` — sudah ada.
- **Belum ada** (kandidat fase lanjutan bila diminta): "re-link / ubah channel_sku ↔ master_sku" eksplisit (edit `ProductVariantChannelMapping.external_sku_id`). Tidak dikerjakan sekarang kecuali diminta.
