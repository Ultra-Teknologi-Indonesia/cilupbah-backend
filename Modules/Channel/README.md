# Channel Module

Integrasi marketplace channel (TikTok Shop, dll).

## Artisan Commands

### `tiktok:sync-attributes`

Sinkronisasi atribut produk dari TikTok API ke database lokal untuk semua kategori yang sudah di-mapping, atau satu kategori spesifik.

**Kapan harus dijalankan:**

- Setelah deploy pertama ke environment baru (staging/production)
- Setelah ada fix terkait parsing atribut (misal fix typo `is_requried`)
- Setelap mapping kategori baru ditambahkan

**Usage:**

```bash
# Sync semua kategori yang di-mapping
php artisan tiktok:sync-attributes {shop_id}

# Sync satu kategori spesifik (by external_id)
php artisan tiktok:sync-attributes {shop_id} --category=601756
```

**Parameter:**

| Parameter | Wajib | Keterangan |
|-----------|-------|------------|
| `shop_id` | Ya | `shop_id` dari tabel `channel_shops` (TikTok shop ID, bukan internal UUID) |
| `--category` | Tidak | External ID kategori TikTok. Jika tidak diisi, sync semua kategori yang ada di `category_channel_mappings` |

**Contoh:**

```bash
# Production
php artisan tiktok:sync-attributes 7494685794425930858

# Sync hanya kategori Laptop (601756)
php artisan tiktok:sync-attributes 7494685794425930858 --category=601756
```

**Apa yang dilakukan:**

1. Query `category_channel_mappings` untuk mendapatkan daftar kategori TikTok yang di-mapping
2. Hit TikTok API `GET /product/202309/categories/{id}/attributes` per kategori
3. Upsert ke tabel `channel_attributes` (nama, `is_required`, `is_sale_prop`, dll)
4. Upsert ke tabel `channel_attribute_options` (opsi value per atribut)

**Catatan penting:**

- `shop_id` adalah ID TikTok (contoh: `7494685794425930858`), bukan UUID internal dari tabel `channel_shops`
- Command ini aman dijalankan berulang kali (idempotent) - data yang sudah ada akan di-update, bukan duplikat
- Membutuhkan koneksi ke TikTok API (access token harus valid)
