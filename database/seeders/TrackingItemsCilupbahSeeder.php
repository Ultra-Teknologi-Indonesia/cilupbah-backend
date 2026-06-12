<?php

namespace Database\Seeders;

use App\Models\TrackingItem;
use Illuminate\Database\Seeder;

/**
 * Endpoint NATIVE cilupbah-be yang sudah terimplementasi tetapi belum terdata
 * di Dev Tracker (tracker awal hanya memuat spec Jubelio + Epik + Omnichannel).
 * Contoh: domain Authentication hanya punya 1 item (POST /login) padahal
 * implementasinya mencakup logout, profile, roles, permissions, users, dst.
 *
 * Endpoint yang merupakan IMPLEMENTASI dari item Jubelio yang sudah terdata
 * (mis. /products/master ↔ /inventory/items/masters) TIDAK dimasukkan lagi
 * agar tidak dobel hitung.
 *
 * Idempotent: kunci unik (method, endpoint); status/notes/pic hasil edit user
 * TIDAK ditimpa. Source: "cilupbah".
 */
class TrackingItemsCilupbahSeeder extends Seeder
{
    public function run(): void
    {
        $created = 0;
        $updated = 0;

        foreach ($this->items() as $row) {
            $item = TrackingItem::firstOrNew([
                'method' => $row['method'],
                'endpoint' => $row['endpoint'],
            ]);

            $item->domain = $row['domain'];
            $item->function_id = $row['function_id'];
            $item->source = 'cilupbah';
            $item->priority = null;

            if (! $item->exists) {
                $item->status = $row['status'];
                $item->baseline_status = $row['status'];
                $item->pic = $row['pic'];
                $created++;
            } else {
                $updated++;
            }

            $item->save();
        }

        $this->command?->info("TrackingItems (cilupbah): +$created baru, $updated diperbarui (metadata).");
    }

    /** @return array<int,array<string,string>> */
    private function items(): array
    {
        $d = 'Darriel';
        $r = 'Rasyid';

        return [
            // ── Authentication ──────────────────────────────────────────────
            ['domain' => 'Authentication', 'method' => 'POST', 'endpoint' => '/auth/logout', 'function_id' => 'Logout & cabut token akses', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'GET', 'endpoint' => '/profile', 'function_id' => 'Ambil profil user terautentikasi (roles + permissions)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'PUT', 'endpoint' => '/profile/avatar', 'function_id' => 'Set/lepas avatar user (referensi media terpusat)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'GET', 'endpoint' => '/roles', 'function_id' => 'Ambil daftar role (paginated, search, users_count)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'POST', 'endpoint' => '/roles', 'function_id' => 'Buat role baru', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'GET', 'endpoint' => '/roles/{id}', 'function_id' => 'Ambil detail role beserta permissions', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'PUT', 'endpoint' => '/roles/{id}', 'function_id' => 'Ubah role (owner dilindungi)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'DELETE', 'endpoint' => '/roles/{id}', 'function_id' => 'Hapus role (ditolak 422 bila masih dipakai user)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'PUT', 'endpoint' => '/roles/{id}/permissions', 'function_id' => 'Sinkronkan permission sebuah role', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'GET', 'endpoint' => '/permissions', 'function_id' => 'Ambil daftar seluruh permission', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'GET', 'endpoint' => '/users', 'function_id' => 'Ambil daftar pengguna (filter role/warehouse, FTS search)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'POST', 'endpoint' => '/users', 'function_id' => 'Buat pengguna baru + assign role', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'GET', 'endpoint' => '/users/{id}', 'function_id' => 'Ambil detail pengguna', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'PUT', 'endpoint' => '/users/{id}', 'function_id' => 'Ubah pengguna (role owner dilindungi)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'DELETE', 'endpoint' => '/users/{id}', 'function_id' => 'Hapus pengguna (guard hapus diri sendiri & owner)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'GET', 'endpoint' => '/users/export', 'function_id' => 'Export daftar pengguna ke Excel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'GET', 'endpoint' => '/users/{id}/histories', 'function_id' => 'Ambil riwayat aksi pengguna', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'POST', 'endpoint' => '/users/{id}/force-logout', 'function_id' => 'Putus paksa sesi satu pengguna', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Authentication', 'method' => 'POST', 'endpoint' => '/users/bulk-force-logout', 'function_id' => 'Putus paksa sesi banyak pengguna sekaligus', 'status' => 'done', 'pic' => $d],

            // ── Media (file terpusat → Cloudflare R2) ───────────────────────
            ['domain' => 'Media', 'method' => 'POST', 'endpoint' => '/media/upload', 'function_id' => 'Unggah file (semua tipe) ke storage terpusat R2', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Media', 'method' => 'GET', 'endpoint' => '/media/upload/{uuid}', 'function_id' => 'Ambil metadata & URL media per UUID', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Media', 'method' => 'PUT', 'endpoint' => '/media/upload/{uuid}', 'function_id' => 'Ganti file media (UUID & referensi tetap)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Media', 'method' => 'DELETE', 'endpoint' => '/media/upload/{uuid}', 'function_id' => 'Hapus media dari storage & database', 'status' => 'done', 'pic' => $d],

            // ── Channels — TikTok ───────────────────────────────────────────
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/tiktok/auth', 'function_id' => 'Mulai OAuth otorisasi toko TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/tiktok/callback', 'function_id' => 'Callback OAuth TikTok (tukar code → token)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/tiktok/stores', 'function_id' => 'Ambil daftar toko TikTok terhubung', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/tiktok/stores/{id}', 'function_id' => 'Ambil detail toko TikTok + status token', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'DELETE', 'endpoint' => '/tiktok/stores/{id}', 'function_id' => 'Putuskan koneksi toko TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/stores/{id}/refresh-token', 'function_id' => 'Refresh access token toko TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/sync/pull', 'function_id' => 'Tarik order TikTok (pull manual)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/sync/accept', 'function_id' => 'Terima order TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/sync/decline', 'function_id' => 'Tolak order TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/cancel-product', 'function_id' => 'Batalkan order di TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/tiktok/cancel-reasons', 'function_id' => 'Ambil daftar alasan pembatalan TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/sync/products/push', 'function_id' => 'Push satu produk ke TikTok (create listing)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/sync/products/bulk-push', 'function_id' => 'Push banyak produk ke TikTok sekaligus', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/sync/products/sync', 'function_id' => 'Sinkronkan update produk ke TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/auto-sync/pull-orders', 'function_id' => 'Auto-sync: tarik order semua toko TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/auto-sync/pull-products', 'function_id' => 'Auto-sync: tarik produk semua toko TikTok', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/tiktok/webhook', 'function_id' => 'Webhook masuk TikTok (order/produk, verifikasi signature)', 'status' => 'done', 'pic' => $d],

            // ── Channels — Lazada ───────────────────────────────────────────
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/lazada/auth', 'function_id' => 'Mulai OAuth otorisasi toko Lazada', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/lazada/callback', 'function_id' => 'Callback OAuth Lazada (tukar code → token)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/lazada/stores', 'function_id' => 'Ambil daftar toko Lazada terhubung', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/lazada/stores/{id}', 'function_id' => 'Ambil detail toko Lazada + status token', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'DELETE', 'endpoint' => '/lazada/stores/{id}', 'function_id' => 'Putuskan koneksi toko Lazada', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/lazada/cancel-reasons', 'function_id' => 'Ambil daftar alasan pembatalan Lazada', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/lazada/logistics', 'function_id' => 'Ambil opsi logistik/kurir Lazada', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/lazada/webhook', 'function_id' => 'Webhook masuk Lazada (order push, verifikasi signature)', 'status' => 'done', 'pic' => $d],

            // ── Channels — lintas channel ───────────────────────────────────
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/channel-warehouses', 'function_id' => 'Ambil pemetaan gudang lokal ↔ gudang channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/channel-warehouses', 'function_id' => 'Buat pemetaan gudang lokal ↔ gudang channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'DELETE', 'endpoint' => '/channel-warehouses/{id}', 'function_id' => 'Hapus pemetaan gudang channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/{channel}/download', 'function_id' => 'Download satu produk dari channel ke lokal (async)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/{channel}/download/bulk', 'function_id' => 'Download banyak produk dari channel sekaligus', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/download-transactions', 'function_id' => 'Ambil riwayat transaksi download produk channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/download-transactions/{id}', 'function_id' => 'Ambil detail satu transaksi download', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/{channel}/products', 'function_id' => 'Ambil daftar produk per channel/toko', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'POST', 'endpoint' => '/{channel}/products', 'function_id' => 'Buat produk lokal & push ke channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/{channel}/products/{id}', 'function_id' => 'Ambil detail produk channel (per external id)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'PUT', 'endpoint' => '/{channel}/products/{id}', 'function_id' => 'Ubah produk channel & sinkronkan', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'DELETE', 'endpoint' => '/{channel}/products/{id}', 'function_id' => 'Hapus produk dari channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'PUT', 'endpoint' => '/{channel}/products/{id}/activate', 'function_id' => 'Aktifkan listing produk di channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'PUT', 'endpoint' => '/{channel}/products/{id}/deactivate', 'function_id' => 'Nonaktifkan listing produk di channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'PUT', 'endpoint' => '/{channel}/products/{id}/stock', 'function_id' => 'Update stok produk di channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'PUT', 'endpoint' => '/{channel}/products/{id}/price', 'function_id' => 'Update harga produk di channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'DELETE', 'endpoint' => '/{channel}/products/{id}/link', 'function_id' => 'Putus koneksi produk dari channel (produk lokal tetap)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/{channel}/categories', 'function_id' => 'Ambil kategori channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/{channel}/categories/{categoryId}/attributes', 'function_id' => 'Ambil atribut kategori channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/channel-monitor', 'function_id' => 'Pantauan status sync produk semua toko channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/channel-monitor/summary', 'function_id' => 'Ringkasan pantauan sync per channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/channel-monitor/{shop_id}', 'function_id' => 'Detail pantauan sync satu toko', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels', 'method' => 'GET', 'endpoint' => '/channel-monitor/{shop_id}/products', 'function_id' => 'Daftar produk + status sync satu toko', 'status' => 'done', 'pic' => $d],

            // ── Product (lifecycle & CRUD native) ───────────────────────────
            ['domain' => 'Product', 'method' => 'GET', 'endpoint' => '/products', 'function_id' => 'Ambil daftar produk (filter status lifecycle, FTS search)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products', 'function_id' => 'Buat produk lengkap (varian, media, spesifikasi, harga)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'GET', 'endpoint' => '/products/{id}', 'function_id' => 'Ambil detail produk + relasi channel mapping', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'PUT', 'endpoint' => '/products/{id}', 'function_id' => 'Ubah produk & varian', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'DELETE', 'endpoint' => '/products/{id}', 'function_id' => 'Hapus produk (hanya dead stock)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/{id}/submit-review', 'function_id' => 'Lifecycle: ajukan produk Download → In Review', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/{id}/approve', 'function_id' => 'Lifecycle: setujui produk In Review → Master', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/{id}/reject', 'function_id' => 'Lifecycle: tolak produk In Review → Download', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/{id}/archive', 'function_id' => 'Lifecycle: arsipkan produk Master', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/{id}/restore', 'function_id' => 'Lifecycle: pulihkan produk Arsip → Master', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/import/single', 'function_id' => 'Import produk satuan dari Excel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/import/bundle', 'function_id' => 'Import produk bundle dari Excel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'GET', 'endpoint' => '/products/import/template/single', 'function_id' => 'Unduh template Excel import produk satuan', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'GET', 'endpoint' => '/products/import/template/bundle', 'function_id' => 'Unduh template Excel import produk bundle', 'status' => 'done', 'pic' => $d],

            // ── Product — Merge & Auto-Merge ────────────────────────────────
            ['domain' => 'Product', 'method' => 'GET', 'endpoint' => '/products/merge/catalog', 'function_id' => 'Katalog produk lintas toko untuk merge', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'GET', 'endpoint' => '/products/merge/suggestions', 'function_id' => 'Saran pasangan produk serupa untuk merge', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'GET', 'endpoint' => '/products/merge/applied', 'function_id' => 'Daftar merge yang sudah diterapkan', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/merge/auto', 'function_id' => 'Auto-merge produk serupa lintas channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/merge/apply', 'function_id' => 'Terapkan satu merge produk', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/merge/bulk', 'function_id' => 'Terapkan banyak merge sekaligus', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/merge/bulk-unmerge', 'function_id' => 'Batalkan banyak merge sekaligus', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'DELETE', 'endpoint' => '/products/merge/{product}', 'function_id' => 'Batalkan merge satu produk', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'DELETE', 'endpoint' => '/products/merge/master', 'function_id' => 'Bubarkan grup merge dari sisi master', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/merge/hide', 'function_id' => 'Sembunyikan produk dari katalog merge', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product', 'method' => 'POST', 'endpoint' => '/products/merge/unhide', 'function_id' => 'Tampilkan kembali produk di katalog merge', 'status' => 'done', 'pic' => $d],

            // ── Product Listing (draft, raise, riwayat upload) ──────────────
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/products/uploadable', 'function_id' => 'Daftar produk Master yang belum ter-upload ke toko', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/products/channel-products', 'function_id' => 'Daftar listing produk per channel (tab Produk Channel)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/products/channel-products/{id}', 'function_id' => 'Detail satu listing produk channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/products/channel-drafts', 'function_id' => 'Daftar semua draft listing channel (tab Draft)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'POST', 'endpoint' => '/products/channel-drafts/bulk-upload', 'function_id' => 'Upload banyak draft listing ke channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'POST', 'endpoint' => '/products/channel-drafts/{draft}/upload', 'function_id' => 'Upload satu draft listing ke channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/products/{id}/channel-drafts', 'function_id' => 'Daftar draft listing per produk', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'POST', 'endpoint' => '/products/{id}/channel-drafts', 'function_id' => 'Buat draft listing produk untuk channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'PUT', 'endpoint' => '/products/{id}/channel-drafts/{draft}', 'function_id' => 'Ubah draft listing produk', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'DELETE', 'endpoint' => '/products/{id}/channel-drafts/{draft}', 'function_id' => 'Hapus draft listing produk', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/raise-products', 'function_id' => 'Daftar batch naikkan produk (raise) ke channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'POST', 'endpoint' => '/raise-products', 'function_id' => 'Buat batch naikkan produk', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/raise-products/{id}', 'function_id' => 'Detail batch naikkan produk + item', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'POST', 'endpoint' => '/raise-products/{id}/raise', 'function_id' => 'Eksekusi naikkan produk ke channel (async)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'POST', 'endpoint' => '/raise-products/{id}/products', 'function_id' => 'Tambah produk ke batch raise', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'PATCH', 'endpoint' => '/raise-products/{id}/products/{detailId}', 'function_id' => 'Ubah item dalam batch raise', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'DELETE', 'endpoint' => '/raise-products/{id}/products/{detailId}', 'function_id' => 'Hapus item dari batch raise', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'DELETE', 'endpoint' => '/raise-products/{id}', 'function_id' => 'Hapus batch naikkan produk', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/upload-histories', 'function_id' => 'Riwayat upload listing produk ke channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'POST', 'endpoint' => '/upload-histories/{id}/re-upload', 'function_id' => 'Upload ulang listing yang gagal', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'POST', 'endpoint' => '/upload-histories/bulk-delete', 'function_id' => 'Hapus banyak riwayat upload sekaligus', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'DELETE', 'endpoint' => '/upload-histories/{id}', 'function_id' => 'Hapus satu riwayat upload', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Listing', 'method' => 'GET', 'endpoint' => '/download-histories', 'function_id' => 'Riwayat download produk dari channel', 'status' => 'done', 'pic' => $d],

            // ── Webhooks (registri outbound) ────────────────────────────────
            ['domain' => 'Webhooks', 'method' => 'GET', 'endpoint' => '/webhooks', 'function_id' => 'Ambil daftar registrasi webhook outbound', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Webhooks', 'method' => 'POST', 'endpoint' => '/webhooks', 'function_id' => 'Registrasi webhook outbound baru (URL + events)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Webhooks', 'method' => 'GET', 'endpoint' => '/webhooks/{id}', 'function_id' => 'Ambil detail satu registrasi webhook', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Webhooks', 'method' => 'PUT', 'endpoint' => '/webhooks/{id}', 'function_id' => 'Ubah registrasi webhook (URL/events/aktif)', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Webhooks', 'method' => 'DELETE', 'endpoint' => '/webhooks/{id}', 'function_id' => 'Hapus registrasi webhook', 'status' => 'done', 'pic' => $d],

            // ── System Setting — Tax CRUD ───────────────────────────────────
            ['domain' => 'System Setting', 'method' => 'POST', 'endpoint' => '/taxes', 'function_id' => 'Buat pajak baru', 'status' => 'done', 'pic' => $d],
            ['domain' => 'System Setting', 'method' => 'GET', 'endpoint' => '/taxes/{id}', 'function_id' => 'Ambil detail pajak', 'status' => 'done', 'pic' => $d],
            ['domain' => 'System Setting', 'method' => 'PUT', 'endpoint' => '/taxes/{id}', 'function_id' => 'Ubah pajak', 'status' => 'done', 'pic' => $d],
            ['domain' => 'System Setting', 'method' => 'DELETE', 'endpoint' => '/taxes/{id}', 'function_id' => 'Hapus pajak', 'status' => 'done', 'pic' => $d],

            // ── Location & The Rack Plan ────────────────────────────────────
            ['domain' => 'Location & The Rack Plan', 'method' => 'GET', 'endpoint' => '/locations/{locationId}/zones', 'function_id' => 'Ambil zona rak per lokasi', 'status' => 'done', 'pic' => $d],

            // ── Ais ──────────────────────────────────────────────
            ['domain' => 'Ais', 'method' => 'GET', 'endpoint' => '/api/v1/ais', 'function_id' => 'Ambil daftar ais', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Ais', 'method' => 'POST', 'endpoint' => '/api/v1/ais', 'function_id' => 'Buat data ais', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Ais', 'method' => 'GET', 'endpoint' => '/api/v1/ais/{ai}', 'function_id' => 'Ambil detail ais', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Ais', 'method' => 'PUT', 'endpoint' => '/api/v1/ais/{ai}', 'function_id' => 'Ubah data ais', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Ais', 'method' => 'PATCH', 'endpoint' => '/api/v1/ais/{ai}', 'function_id' => 'Ubah data ais', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Ais', 'method' => 'DELETE', 'endpoint' => '/api/v1/ais/{ai}', 'function_id' => 'Hapus data ais', 'status' => 'done', 'pic' => $d],

            // ── Authentication ──────────────────────────────────────────────
            ['domain' => 'Authentication', 'method' => 'POST', 'endpoint' => '/api/v1/auth/login', 'function_id' => 'Buat data login', 'status' => 'done', 'pic' => $d],

            // ── Bins ──────────────────────────────────────────────
            ['domain' => 'Bins', 'method' => 'POST', 'endpoint' => '/api/v1/bins', 'function_id' => 'Buat data bins', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Bins', 'method' => 'DELETE', 'endpoint' => '/api/v1/bins/{id}', 'function_id' => 'Hapus data bins', 'status' => 'done', 'pic' => $d],

            // ── Channels Master ──────────────────────────────────────────────
            ['domain' => 'Channels Master', 'method' => 'GET', 'endpoint' => '/api/v1/channels', 'function_id' => 'Ambil daftar channels', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels Master', 'method' => 'POST', 'endpoint' => '/api/v1/channels', 'function_id' => 'Buat data channels', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels Master', 'method' => 'GET', 'endpoint' => '/api/v1/channels/{channel}', 'function_id' => 'Ambil detail channels', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels Master', 'method' => 'PUT', 'endpoint' => '/api/v1/channels/{channel}', 'function_id' => 'Ubah data channels', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels Master', 'method' => 'PATCH', 'endpoint' => '/api/v1/channels/{channel}', 'function_id' => 'Ubah data channels', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Channels Master', 'method' => 'DELETE', 'endpoint' => '/api/v1/channels/{channel}', 'function_id' => 'Hapus data channels', 'status' => 'done', 'pic' => $d],

            // ── Finance & Tax ──────────────────────────────────────────────
            ['domain' => 'Finance & Tax', 'method' => 'GET', 'endpoint' => '/api/v1/finances', 'function_id' => 'Ambil daftar finances', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Finance & Tax', 'method' => 'POST', 'endpoint' => '/api/v1/finances', 'function_id' => 'Buat data finances', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Finance & Tax', 'method' => 'GET', 'endpoint' => '/api/v1/finances/{finance}', 'function_id' => 'Ambil detail finances', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Finance & Tax', 'method' => 'PUT', 'endpoint' => '/api/v1/finances/{finance}', 'function_id' => 'Ubah data finances', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Finance & Tax', 'method' => 'PATCH', 'endpoint' => '/api/v1/finances/{finance}', 'function_id' => 'Ubah data finances', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Finance & Tax', 'method' => 'DELETE', 'endpoint' => '/api/v1/finances/{finance}', 'function_id' => 'Hapus data finances', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Finance & Tax', 'method' => 'PATCH', 'endpoint' => '/api/v1/taxes/{tax}', 'function_id' => 'Ubah data taxes', 'status' => 'done', 'pic' => $d],

            // ── General Settings ──────────────────────────────────────────────
            ['domain' => 'General Settings', 'method' => 'GET', 'endpoint' => '/api/v1/notifications', 'function_id' => 'Ambil daftar notifications', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'POST', 'endpoint' => '/api/v1/notifications', 'function_id' => 'Buat data notifications', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'GET', 'endpoint' => '/api/v1/notifications/{notification}', 'function_id' => 'Ambil detail notifications', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'PUT', 'endpoint' => '/api/v1/notifications/{notification}', 'function_id' => 'Ubah data notifications', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'PATCH', 'endpoint' => '/api/v1/notifications/{notification}', 'function_id' => 'Ubah data notifications', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'DELETE', 'endpoint' => '/api/v1/notifications/{notification}', 'function_id' => 'Hapus data notifications', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'GET', 'endpoint' => '/api/v1/regions/cities/{province_id}', 'function_id' => 'Ambil detail cities', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'GET', 'endpoint' => '/api/v1/regions/districts/{city_id}', 'function_id' => 'Ambil detail districts', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'GET', 'endpoint' => '/api/v1/regions/provinces', 'function_id' => 'Ambil daftar provinces', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'GET', 'endpoint' => '/api/v1/regions/villages/{district_id}', 'function_id' => 'Ambil detail villages', 'status' => 'done', 'pic' => $d],
            ['domain' => 'General Settings', 'method' => 'GET', 'endpoint' => '/api/v1/suppliers', 'function_id' => 'Ambil daftar suppliers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'General Settings', 'method' => 'POST', 'endpoint' => '/api/v1/suppliers', 'function_id' => 'Buat data suppliers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'General Settings', 'method' => 'GET', 'endpoint' => '/api/v1/suppliers/{supplier}', 'function_id' => 'Ambil detail suppliers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'General Settings', 'method' => 'PUT', 'endpoint' => '/api/v1/suppliers/{supplier}', 'function_id' => 'Ubah data suppliers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'General Settings', 'method' => 'PATCH', 'endpoint' => '/api/v1/suppliers/{supplier}', 'function_id' => 'Ubah data suppliers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'General Settings', 'method' => 'DELETE', 'endpoint' => '/api/v1/suppliers/{supplier}', 'function_id' => 'Hapus data suppliers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'General Settings', 'method' => 'PATCH', 'endpoint' => '/api/v1/webhooks/{webhook}', 'function_id' => 'Ubah data webhooks', 'status' => 'done', 'pic' => $d],

            // ── Inbound & Putaway ──────────────────────────────────────────────
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/inbounds', 'function_id' => 'Ambil daftar inbounds', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds', 'function_id' => 'Buat data inbounds', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds/assignments/{assignmentId}/start', 'function_id' => 'Endpoint POST /api/v1/inbounds/assignments/{assignmentId}/start', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/inbounds/my-assignments', 'function_id' => 'Ambil daftar my-assignments', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/inbounds/received-items', 'function_id' => 'Ambil daftar received-items', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds/scan-putaway', 'function_id' => 'Buat data scan-putaway', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/inbounds/scan/{qrCode}', 'function_id' => 'Ambil detail scan', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/inbounds/{id}', 'function_id' => 'Ambil detail inbounds', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds/{id}/assign', 'function_id' => 'Endpoint POST /api/v1/inbounds/{id}/assign', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/inbounds/{id}/assignments', 'function_id' => 'Ambil detail {id}', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds/{id}/auto-putaway', 'function_id' => 'Endpoint POST /api/v1/inbounds/{id}/auto-putaway', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/inbounds/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds/{id}/close-receiving', 'function_id' => 'Endpoint POST /api/v1/inbounds/{id}/close-receiving', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/inbounds/{id}/pending-putaway', 'function_id' => 'Ambil detail {id}', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds/{id}/putaway', 'function_id' => 'Endpoint POST /api/v1/inbounds/{id}/putaway', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/inbounds/{id}/receive', 'function_id' => 'Endpoint POST /api/v1/inbounds/{id}/receive', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/putaway', 'function_id' => 'Ambil daftar putaway', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/putaway/assign-staff', 'function_id' => 'Buat data assign-staff', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/putaway/completed', 'function_id' => 'Ambil daftar completed', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/putaway/in-progress', 'function_id' => 'Ambil daftar in-progress', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/putaway/not-started', 'function_id' => 'Ambil daftar not-started', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/putaway/{id}', 'function_id' => 'Ambil detail putaway', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/putaway/{id}/complete', 'function_id' => 'Endpoint POST /api/v1/putaway/{id}/complete', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'GET', 'endpoint' => '/api/v1/putaway/{id}/items', 'function_id' => 'Ambil detail {id}', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/putaway/{id}/items/{itemId}/process', 'function_id' => 'Endpoint POST /api/v1/putaway/{id}/items/{itemId}/process', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inbound & Putaway', 'method' => 'POST', 'endpoint' => '/api/v1/putaway/{id}/start', 'function_id' => 'Endpoint POST /api/v1/putaway/{id}/start', 'status' => 'done', 'pic' => $r],

            // ── Inventory Lanjutan ──────────────────────────────────────────────
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/adjustments/documents', 'function_id' => 'Buat data documents', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/adjustments/documents/{id}', 'function_id' => 'Ambil detail documents', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'DELETE', 'endpoint' => '/api/v1/inventory/adjustments/documents/{id}', 'function_id' => 'Hapus data documents', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/adjustments/documents/{id}/approve', 'function_id' => 'Endpoint POST /api/v1/inventory/adjustments/documents/{id}/approve', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/adjustments/documents/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/inventory/adjustments/documents/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/history', 'function_id' => 'Ambil daftar history', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/items/by-location/{locationId}', 'function_id' => 'Ambil detail by-location', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/movements', 'function_id' => 'Ambil daftar movements', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'DELETE', 'endpoint' => '/api/v1/inventory/promotions/{id}', 'function_id' => 'Hapus data promotions', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/purchase-order/items', 'function_id' => 'Ambil daftar items', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/putaway', 'function_id' => 'Buat data putaway', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/reserved-stocks', 'function_id' => 'Ambil daftar reserved-stocks', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/reserved-stocks', 'function_id' => 'Buat data reserved-stocks', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/reserved-stocks/{id}', 'function_id' => 'Ambil detail reserved-stocks', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/reserved-stocks/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/inventory/reserved-stocks/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/revaluations', 'function_id' => 'Ambil daftar revaluations', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/revaluations/{id}', 'function_id' => 'Ambil detail revaluations', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/revaluations/{id}/approve', 'function_id' => 'Endpoint POST /api/v1/inventory/revaluations/{id}/approve', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/revaluations/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/inventory/revaluations/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'DELETE', 'endpoint' => '/api/v1/inventory/stock-opname/{id}', 'function_id' => 'Hapus data stock-opname', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/stock-opname/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/inventory/stock-opname/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/stock-opname/{id}/finalize', 'function_id' => 'Endpoint POST /api/v1/inventory/stock-opname/{id}/finalize', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/stock-opname/{id}/items', 'function_id' => 'Ambil detail {id}', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/stock-opname/{id}/items/filtered', 'function_id' => 'Ambil detail items', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/stock-opname/{id}/items/{itemId}/count', 'function_id' => 'Endpoint POST /api/v1/inventory/stock-opname/{id}/items/{itemId}/count', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/stock-opname/{id}/mark-printed', 'function_id' => 'Endpoint POST /api/v1/inventory/stock-opname/{id}/mark-printed', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/stock-opname/{id}/start', 'function_id' => 'Endpoint POST /api/v1/inventory/stock-opname/{id}/start', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/stock-products', 'function_id' => 'Ambil daftar stock-products', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/stocks', 'function_id' => 'Ambil daftar stocks', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/stocks/{itemId}', 'function_id' => 'Ambil detail stocks', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'GET', 'endpoint' => '/api/v1/inventory/transfers', 'function_id' => 'Ambil daftar transfers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'DELETE', 'endpoint' => '/api/v1/inventory/transfers/{id}', 'function_id' => 'Hapus data transfers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Inventory Lanjutan', 'method' => 'POST', 'endpoint' => '/api/v1/inventory/transfers/{id}/receive', 'function_id' => 'Endpoint POST /api/v1/inventory/transfers/{id}/receive', 'status' => 'done', 'pic' => $r],

            // ── Lazada ──────────────────────────────────────────────
            ['domain' => 'Lazada', 'method' => 'POST', 'endpoint' => '/api/v1/lazada/auto-sync/pull-orders', 'function_id' => 'Buat data pull-orders', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Lazada', 'method' => 'POST', 'endpoint' => '/api/v1/lazada/stores/{id}/refresh-token', 'function_id' => 'Endpoint POST /api/v1/lazada/stores/{id}/refresh-token', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Lazada', 'method' => 'POST', 'endpoint' => '/api/v1/lazada/sync/cancel', 'function_id' => 'Buat data cancel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Lazada', 'method' => 'POST', 'endpoint' => '/api/v1/lazada/sync/pack', 'function_id' => 'Buat data pack', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Lazada', 'method' => 'POST', 'endpoint' => '/api/v1/lazada/sync/pull', 'function_id' => 'Buat data pull', 'status' => 'done', 'pic' => $d],

            // ── Location & The Rack Plan ──────────────────────────────────────────────
            ['domain' => 'Location & The Rack Plan', 'method' => 'GET', 'endpoint' => '/api/v1/locations/{locationId}/bins', 'function_id' => 'Ambil detail {locationId}', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Location & The Rack Plan', 'method' => 'POST', 'endpoint' => '/api/v1/locations/{locationId}/bins/generate', 'function_id' => 'Endpoint POST /api/v1/locations/{locationId}/bins/generate', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Location & The Rack Plan', 'method' => 'POST', 'endpoint' => '/api/v1/locations/{locationId}/bins/preview', 'function_id' => 'Endpoint POST /api/v1/locations/{locationId}/bins/preview', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Location & The Rack Plan', 'method' => 'GET', 'endpoint' => '/api/v1/locations/{locationId}/default-bin', 'function_id' => 'Ambil detail {locationId}', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Location & The Rack Plan', 'method' => 'PUT', 'endpoint' => '/api/v1/locations/{location}', 'function_id' => 'Ubah data locations', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Location & The Rack Plan', 'method' => 'PATCH', 'endpoint' => '/api/v1/locations/{location}', 'function_id' => 'Ubah data locations', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Location & The Rack Plan', 'method' => 'DELETE', 'endpoint' => '/api/v1/locations/{location}', 'function_id' => 'Hapus data locations', 'status' => 'done', 'pic' => $d],

            // ── Miscellaneous ──────────────────────────────────────────────
            ['domain' => 'Miscellaneous', 'method' => 'GET', 'endpoint' => '/api/documentation', 'function_id' => 'Ambil daftar documentation', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Miscellaneous', 'method' => 'GET', 'endpoint' => '/api/oauth2-callback', 'function_id' => 'Ambil daftar oauth2-callback', 'status' => 'done', 'pic' => $d],

            // ── Outbound (WMS) ──────────────────────────────────────────────
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/couriers', 'function_id' => 'Ambil daftar couriers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/couriers', 'function_id' => 'Buat data couriers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/couriers/all', 'function_id' => 'Ambil daftar all', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/couriers/tenant/{tenantId}', 'function_id' => 'Ambil detail tenant', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/couriers/{id}', 'function_id' => 'Ambil detail couriers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'PUT', 'endpoint' => '/api/v1/outbound/couriers/{id}', 'function_id' => 'Ubah data couriers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'DELETE', 'endpoint' => '/api/v1/outbound/couriers/{id}', 'function_id' => 'Hapus data couriers', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/orders/change-location', 'function_id' => 'Buat data change-location', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/orders/get-by-no', 'function_id' => 'Buat data get-by-no', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/orders/move-to-ready-to-pick', 'function_id' => 'Buat data move-to-ready-to-pick', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/orders/move-to-ready-to-process', 'function_id' => 'Buat data move-to-ready-to-process', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/orders/request-cancel', 'function_id' => 'Buat data request-cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/orders/{stage}', 'function_id' => 'Ambil detail orders', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/packlists', 'function_id' => 'Ambil daftar packlists', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/packlists', 'function_id' => 'Buat data packlists', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/packlists/finish-pack', 'function_id' => 'Ambil daftar finish-pack', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/packlists/on-packing', 'function_id' => 'Ambil daftar on-packing', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/packlists/scan-order', 'function_id' => 'Ambil daftar scan-order', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/packlists/{id}', 'function_id' => 'Ambil detail packlists', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'DELETE', 'endpoint' => '/api/v1/outbound/packlists/{id}', 'function_id' => 'Hapus data packlists', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/packlists/{id}/assign-packer', 'function_id' => 'Endpoint POST /api/v1/outbound/packlists/{id}/assign-packer', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/packlists/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/outbound/packlists/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/packlists/{id}/complete', 'function_id' => 'Endpoint POST /api/v1/outbound/packlists/{id}/complete', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/packlists/{id}/items', 'function_id' => 'Ambil detail {id}', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/packlists/{id}/items/{itemId}/pack', 'function_id' => 'Endpoint POST /api/v1/outbound/packlists/{id}/items/{itemId}/pack', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/packlists/{id}/start', 'function_id' => 'Endpoint POST /api/v1/outbound/packlists/{id}/start', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/packlists/{id}/verify-barcode', 'function_id' => 'Endpoint POST /api/v1/outbound/packlists/{id}/verify-barcode', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/picklists', 'function_id' => 'Ambil daftar picklists', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/picklists', 'function_id' => 'Buat data picklists', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/picklists/on-picking', 'function_id' => 'Ambil daftar on-picking', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/picklists/{id}', 'function_id' => 'Ambil detail picklists', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'DELETE', 'endpoint' => '/api/v1/outbound/picklists/{id}', 'function_id' => 'Hapus data picklists', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/picklists/{id}/assign-picker', 'function_id' => 'Endpoint POST /api/v1/outbound/picklists/{id}/assign-picker', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/picklists/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/outbound/picklists/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/picklists/{id}/complete', 'function_id' => 'Endpoint POST /api/v1/outbound/picklists/{id}/complete', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/picklists/{id}/fail', 'function_id' => 'Endpoint POST /api/v1/outbound/picklists/{id}/fail', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/picklists/{id}/items', 'function_id' => 'Ambil detail {id}', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/picklists/{id}/items/{itemId}/pick', 'function_id' => 'Endpoint POST /api/v1/outbound/picklists/{id}/items/{itemId}/pick', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/picklists/{id}/start', 'function_id' => 'Endpoint POST /api/v1/outbound/picklists/{id}/start', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/shipments', 'function_id' => 'Ambil daftar shipments', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments', 'function_id' => 'Buat data shipments', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/shipments/by-courier/{courierCode}', 'function_id' => 'Ambil detail by-courier', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/shipments/completed/{type}/{courierIds}', 'function_id' => 'Ambil detail {type}', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/shipments/instant', 'function_id' => 'Ambil daftar instant', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments/instant', 'function_id' => 'Buat data instant', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments/scan', 'function_id' => 'Buat data scan', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/shipments/{id}', 'function_id' => 'Ambil detail shipments', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'DELETE', 'endpoint' => '/api/v1/outbound/shipments/{id}', 'function_id' => 'Hapus data shipments', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments/{id}/add-orders', 'function_id' => 'Endpoint POST /api/v1/outbound/shipments/{id}/add-orders', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/outbound/shipments/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments/{id}/hand-over', 'function_id' => 'Endpoint POST /api/v1/outbound/shipments/{id}/hand-over', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments/{id}/remove-orders', 'function_id' => 'Endpoint POST /api/v1/outbound/shipments/{id}/remove-orders', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments/{id}/save-awb', 'function_id' => 'Endpoint POST /api/v1/outbound/shipments/{id}/save-awb', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'POST', 'endpoint' => '/api/v1/outbound/shipments/{id}/update-handover-qty', 'function_id' => 'Endpoint POST /api/v1/outbound/shipments/{id}/update-handover-qty', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/wms/default-bin/{locationId}', 'function_id' => 'Ambil detail default-bin', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'PUT', 'endpoint' => '/api/v1/outbound/wms/default-bin/{locationId}', 'function_id' => 'Ubah data default-bin', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Outbound (WMS)', 'method' => 'GET', 'endpoint' => '/api/v1/outbound/wms/employee/{identifier}', 'function_id' => 'Ambil detail employee', 'status' => 'done', 'pic' => $r],

            // ── Product Master ──────────────────────────────────────────────
            ['domain' => 'Product Master', 'method' => 'GET', 'endpoint' => '/api/v1/attributes', 'function_id' => 'Ambil daftar attributes', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'POST', 'endpoint' => '/api/v1/attributes', 'function_id' => 'Buat data attributes', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'POST', 'endpoint' => '/api/v1/attributes/options/{option}/map-channel', 'function_id' => 'Endpoint POST /api/v1/attributes/options/{option}/map-channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'GET', 'endpoint' => '/api/v1/attributes/{attribute}', 'function_id' => 'Ambil detail attributes', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'PUT', 'endpoint' => '/api/v1/attributes/{attribute}', 'function_id' => 'Ubah data attributes', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'PATCH', 'endpoint' => '/api/v1/attributes/{attribute}', 'function_id' => 'Ubah data attributes', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'DELETE', 'endpoint' => '/api/v1/attributes/{attribute}', 'function_id' => 'Hapus data attributes', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'POST', 'endpoint' => '/api/v1/attributes/{attribute}/map-channel', 'function_id' => 'Endpoint POST /api/v1/attributes/{attribute}/map-channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'GET', 'endpoint' => '/api/v1/brands', 'function_id' => 'Ambil daftar brands', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'POST', 'endpoint' => '/api/v1/brands', 'function_id' => 'Buat data brands', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'GET', 'endpoint' => '/api/v1/brands/{brand}', 'function_id' => 'Ambil detail brands', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'PUT', 'endpoint' => '/api/v1/brands/{brand}', 'function_id' => 'Ubah data brands', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'PATCH', 'endpoint' => '/api/v1/brands/{brand}', 'function_id' => 'Ubah data brands', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'DELETE', 'endpoint' => '/api/v1/brands/{brand}', 'function_id' => 'Hapus data brands', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'GET', 'endpoint' => '/api/v1/categories', 'function_id' => 'Ambil daftar categories', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'POST', 'endpoint' => '/api/v1/categories', 'function_id' => 'Buat data categories', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'GET', 'endpoint' => '/api/v1/categories/{category}', 'function_id' => 'Ambil detail categories', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'PUT', 'endpoint' => '/api/v1/categories/{category}', 'function_id' => 'Ubah data categories', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'PATCH', 'endpoint' => '/api/v1/categories/{category}', 'function_id' => 'Ubah data categories', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'DELETE', 'endpoint' => '/api/v1/categories/{category}', 'function_id' => 'Hapus data categories', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'POST', 'endpoint' => '/api/v1/categories/{category}/map-channel', 'function_id' => 'Endpoint POST /api/v1/categories/{category}/map-channel', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'GET', 'endpoint' => '/api/v1/warranties', 'function_id' => 'Ambil daftar warranties', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'POST', 'endpoint' => '/api/v1/warranties', 'function_id' => 'Buat data warranties', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'GET', 'endpoint' => '/api/v1/warranties/{warranty}', 'function_id' => 'Ambil detail warranties', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'PUT', 'endpoint' => '/api/v1/warranties/{warranty}', 'function_id' => 'Ubah data warranties', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'PATCH', 'endpoint' => '/api/v1/warranties/{warranty}', 'function_id' => 'Ubah data warranties', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Product Master', 'method' => 'DELETE', 'endpoint' => '/api/v1/warranties/{warranty}', 'function_id' => 'Hapus data warranties', 'status' => 'done', 'pic' => $d],

            // ── Products ──────────────────────────────────────────────
            ['domain' => 'Products', 'method' => 'GET', 'endpoint' => '/api/v1/products/archives/{id}', 'function_id' => 'Ambil detail archives', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Products', 'method' => 'GET', 'endpoint' => '/api/v1/products/master/{id}', 'function_id' => 'Ambil detail master', 'status' => 'done', 'pic' => $d],
            ['domain' => 'Products', 'method' => 'PATCH', 'endpoint' => '/api/v1/products/{product}', 'function_id' => 'Ubah data products', 'status' => 'done', 'pic' => $d],

            // ── Purchase Orders ──────────────────────────────────────────────
            ['domain' => 'Purchase Orders', 'method' => 'DELETE', 'endpoint' => '/api/v1/purchase/orders/{id}', 'function_id' => 'Hapus data orders', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Purchase Orders', 'method' => 'POST', 'endpoint' => '/api/v1/purchase/orders/{id}/approve', 'function_id' => 'Endpoint POST /api/v1/purchase/orders/{id}/approve', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Purchase Orders', 'method' => 'POST', 'endpoint' => '/api/v1/purchase/orders/{id}/cancel', 'function_id' => 'Endpoint POST /api/v1/purchase/orders/{id}/cancel', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Purchase Orders', 'method' => 'POST', 'endpoint' => '/api/v1/purchase/orders/{id}/receive', 'function_id' => 'Endpoint POST /api/v1/purchase/orders/{id}/receive', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Purchase Orders', 'method' => 'GET', 'endpoint' => '/api/v1/purchase/return-settlements', 'function_id' => 'Ambil daftar return-settlements', 'status' => 'done', 'pic' => $r],

            // ── Sales & Return ──────────────────────────────────────────────
            ['domain' => 'Sales & Return', 'method' => 'POST', 'endpoint' => '/api/v1/sales', 'function_id' => 'Buat data sales', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'GET', 'endpoint' => '/api/v1/sales/returns', 'function_id' => 'Ambil daftar returns', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'POST', 'endpoint' => '/api/v1/sales/returns', 'function_id' => 'Buat data returns', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'GET', 'endpoint' => '/api/v1/sales/returns/unprocessed', 'function_id' => 'Ambil daftar unprocessed', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'GET', 'endpoint' => '/api/v1/sales/returns/{id}', 'function_id' => 'Ambil detail returns', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'POST', 'endpoint' => '/api/v1/sales/returns/{id}/accept', 'function_id' => 'Endpoint POST /api/v1/sales/returns/{id}/accept', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'POST', 'endpoint' => '/api/v1/sales/returns/{id}/complete', 'function_id' => 'Endpoint POST /api/v1/sales/returns/{id}/complete', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'POST', 'endpoint' => '/api/v1/sales/returns/{id}/reject', 'function_id' => 'Endpoint POST /api/v1/sales/returns/{id}/reject', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'GET', 'endpoint' => '/api/v1/sales/{id}', 'function_id' => 'Ambil detail sales', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'PUT', 'endpoint' => '/api/v1/sales/{id}', 'function_id' => 'Ubah data sales', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'PATCH', 'endpoint' => '/api/v1/sales/{id}', 'function_id' => 'Ubah data sales', 'status' => 'done', 'pic' => $r],
            ['domain' => 'Sales & Return', 'method' => 'DELETE', 'endpoint' => '/api/v1/sales/{id}', 'function_id' => 'Hapus data sales', 'status' => 'done', 'pic' => $r],

            // ── Systemsetting ──────────────────────────────────────────────
            ['domain' => 'Systemsetting', 'method' => 'GET', 'endpoint' => '/api/v1/systemsetting/webhook', 'function_id' => 'Ambil daftar webhook', 'status' => 'done', 'pic' => $d],

            // ── Tiktok ──────────────────────────────────────────────
            ['domain' => 'Tiktok', 'method' => 'GET', 'endpoint' => '/api/v1/tiktok/callback-debug', 'function_id' => 'Ambil daftar callback-debug', 'status' => 'done', 'pic' => $d],

            // ── {channel} ──────────────────────────────────────────────
            ['domain' => '{channel}', 'method' => 'PATCH', 'endpoint' => '/api/v1/{channel}/products/{product}', 'function_id' => 'Ubah data products', 'status' => 'done', 'pic' => $d],
        ];
    }
}
