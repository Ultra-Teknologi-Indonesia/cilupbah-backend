<?php

namespace Modules\Bantuan\Services;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Menghasilkan narasi Indonesia (summary, purpose, description) untuk sebuah
 * endpoint berdasar route name, HTTP method, FormRequest, roles, dsb.
 * Tujuan: menghapus flag needs_doc walau controller tidak punya PHPDoc.
 */
class RouteNarrator
{
    /** Nama resource → nama Indonesia + gender/tag. */
    private const RESOURCE_MAP = [
        // Sales
        'orders'                 => 'pesanan penjualan',
        'sales'                  => 'pesanan penjualan',
        'manual'                 => 'pesanan manual',
        'invoices'               => 'invoice penjualan',
        'sales-invoices'         => 'invoice penjualan',
        'payments'               => 'pembayaran',
        'sales-payments'         => 'pembayaran penjualan',
        'settlements'            => 'settlement',
        'returns'                => 'retur penjualan',
        'sales-returns'          => 'retur penjualan',
        'return-settings'        => 'pengaturan retur',
        'return-settlements'     => 'settlement retur',
        'internal-stores'        => 'toko internal',
        'toko-internal'          => 'toko internal',
        'courier-pickup'         => 'bukti pickup kurir',
        'driver-call'            => 'panggilan driver',

        // Inventory
        'inventories'            => 'inventori',
        'bin-transfers'          => 'transfer bin',
        'transfers'              => 'transfer stok',
        'transfer-out'           => 'transfer keluar',
        'transfer-in'            => 'transfer masuk',
        'movements'              => 'mutasi stok',
        'stock-adjustments'      => 'penyesuaian stok',
        'adjustments'            => 'penyesuaian stok',
        'stock-opnames'          => 'stok opname',
        'opnames'                => 'stok opname',
        'reserved-stocks'        => 'stok reserved',
        'putaway'                => 'penempatan',
        'positions'              => 'posisi stok',
        'stock-positions'        => 'posisi stok',
        'monitor'                => 'monitor stok',

        // Product
        'products'               => 'produk',
        'variants'               => 'varian produk',
        'bundles'                => 'bundle produk',
        'categories'             => 'kategori produk',
        'brands'                 => 'merek produk',
        'catalog-listings'       => 'listing katalog',
        'mappings'               => 'mapping channel',
        'master-feeds'           => 'master feed',
        'upload-histories'       => 'riwayat upload',
        'download-histories'     => 'riwayat download',
        'channel-monitor'        => 'monitor channel produk',

        // Warehouse / Location
        'locations'              => 'lokasi',
        'location-bins'          => 'rak (bin)',
        'bins'                   => 'rak',
        'zones'                  => 'zona lokasi',
        'warehouse-settings'     => 'pengaturan gudang',

        // Inbound / Outbound
        'inbounds'               => 'penerimaan barang',
        'picklists'              => 'picklist',
        'packlists'              => 'packlist',
        'shipments'              => 'pengiriman',
        'couriers'               => 'kurir',
        'labels'                 => 'label resi',
        'shipping-labels'        => 'label resi',
        'fulfillments'           => 'fulfillment',

        // Purchase
        'purchase-orders'        => 'pesanan pembelian (PO)',
        'purchase-bills'         => 'tagihan pembelian',
        'bills'                  => 'tagihan',
        'purchase-payments'      => 'pembayaran pembelian',

        // Supplier
        'suppliers'              => 'pemasok',
        'contacts'               => 'kontak',
        'customers'              => 'pelanggan',

        // Auth
        'users'                  => 'pengguna',
        'roles'                  => 'peran',
        'permissions'            => 'permission',
        'profile'                => 'profil',
        'sessions'               => 'sesi login',
        'password'               => 'password',
        'two-factor'             => 'autentikasi dua faktor',
        'user-histories'         => 'riwayat aktivitas pengguna',
        'histories'              => 'riwayat',

        // Channel
        'channels'               => 'channel marketplace',
        'shops'                  => 'toko marketplace',
        'shopee'                 => 'Shopee',
        'lazada'                 => 'Lazada',
        'tiktok'                 => 'TikTok Shop',
        'woocommerce'            => 'WooCommerce',
        'attributes'             => 'atribut kategori',

        // Report / Finance / Tax / Notification / Region / Warranty
        'reports'                => 'laporan',
        'cashbank'               => 'kas & bank',
        'journals'               => 'jurnal',
        'account-mappings'       => 'mapping akun',
        'accounts'               => 'akun COA',
        'taxes'                  => 'pajak',
        'notifications'          => 'notifikasi',
        'regions'                => 'wilayah',
        'provinces'              => 'provinsi',
        'cities'                 => 'kota',
        'districts'              => 'kecamatan',
        'villages'               => 'kelurahan',
        'warranties'             => 'garansi produk',
        'webhooks'               => 'webhook',
        'webhook-subscriptions'  => 'subscription webhook',
        'webhook-deliveries'     => 'delivery webhook',

        // Misc
        'issues'                 => 'isu (issue tracker)',
        'settings'               => 'pengaturan',
        'systemsetting'          => 'pengaturan sistem',
        'impex'                  => 'aktivitas import/export',
        'impex-activities'       => 'aktivitas import/export',
        'exports'                => 'ekspor',
        'imports'                => 'impor',
    ];

    /** Sinonim kata kerja standar Laravel. */
    private const VERB_MAP = [
        'index'   => 'Ambil daftar',
        'all'     => 'Ambil semua (tanpa paginasi)',
        'show'    => 'Ambil detail',
        'store'   => 'Buat',
        'update'  => 'Ubah',
        'destroy' => 'Hapus',
        'delete'  => 'Hapus',
        'create'  => 'Buat',
        'edit'    => 'Persiapkan form edit',
    ];

    /** Kata kerja custom (action) → narasi. */
    private const ACTION_MAP = [
        'cancel'          => 'Batalkan',
        'restore'         => 'Kembalikan',
        'archive'         => 'Arsipkan',
        'unarchive'       => 'Batalkan arsip',
        'activate'        => 'Aktifkan',
        'deactivate'      => 'Nonaktifkan',
        'approve'         => 'Setujui',
        'reject'          => 'Tolak',
        'submit'          => 'Ajukan',
        'revert'          => 'Batalkan (revert)',
        'export'          => 'Ekspor ke file',
        'import'          => 'Impor dari file',
        'download'        => 'Unduh',
        'upload'          => 'Unggah',
        'sync'            => 'Sinkronisasi',
        'refresh'         => 'Refresh',
        'refresh-token'   => 'Refresh token',
        'assign'          => 'Assign ke petugas',
        'unassign'        => 'Lepas assignment',
        'reserve'         => 'Reservasi',
        'unreserve'       => 'Lepas reservasi',
        'ship'            => 'Kirim',
        'receive'         => 'Terima',
        'pick'            => 'Pick (ambil dari rak)',
        'pack'            => 'Kemas',
        'putaway'         => 'Tempatkan ke rak',
        'transfer'        => 'Transfer',
        'adjust'          => 'Sesuaikan',
        'reconcile'       => 'Rekonsiliasi',
        'settle'          => 'Selesaikan settlement',
        'print'           => 'Cetak',
        'bulk'            => 'Batch (bulk)',
        'reset'           => 'Reset',
        'logout'          => 'Logout',
        'login'           => 'Login',
        'verify'          => 'Verifikasi',
        'resend'          => 'Kirim ulang',
        'lookup'          => 'Cari (lookup)',
        'search'          => 'Cari',
        'counts'          => 'Hitung jumlah per tab',
        'summary'         => 'Ringkasan',
        'stats'           => 'Statistik',
        'kpi'             => 'KPI',
        'redownload'      => 'Unduh ulang',
        'retry'           => 'Coba ulang',
    ];

    /**
     * @return array{summary:string,purpose:string,description:string,needs_doc:bool}
     */
    public function narrate(Route $route, string $httpMethod, ?string $action, ?string $formRequestClass, array $roles): array
    {
        $name = (string) $route->getName();
        $uri  = $route->uri();

        $resourceSlug = $this->extractResourceSlug($uri, $name);
        $resourceLabel = self::RESOURCE_MAP[$resourceSlug] ?? $this->humanizeSlug($resourceSlug);

        $verb = $this->verbFor($httpMethod, $action, $name, $uri);

        $summary = trim("{$verb} {$resourceLabel}");

        $purposeParts = [];
        if ($formRequestClass) {
            $purposeParts[] = 'Menerima payload yang divalidasi (lihat Body Schema).';
        }
        if ($roles) {
            $rolesTxt = implode(', ', array_slice($roles, 0, 5));
            $purposeParts[] = "Membutuhkan izin: {$rolesTxt}.";
        }
        switch ($httpMethod) {
            case 'GET':
                if ($this->looksLikeList($uri, $action)) {
                    $purposeParts[] = 'Mendukung filter, sort, search, dan pagination (Spatie Query Builder — lihat section Filter/Sort/Search).';
                } else {
                    $purposeParts[] = 'Mengembalikan data untuk ditampilkan di UI.';
                }
                break;
            case 'POST':
                $purposeParts[] = 'Membuat resource baru atau memicu aksi domain.';
                break;
            case 'PUT':
            case 'PATCH':
                $purposeParts[] = 'Memperbarui resource yang sudah ada.';
                break;
            case 'DELETE':
                $purposeParts[] = 'Menghapus resource. Ikuti aturan revert/soft-delete yang berlaku.';
                break;
        }
        $purpose = implode(' ', $purposeParts);

        $description = $this->composeDescription($httpMethod, $resourceLabel, $verb, $roles);

        return [
            'summary'    => $summary,
            'purpose'    => $purpose,
            'description'=> $description,
            'needs_doc'  => false, // narrator sudah menghasilkan narasi meaningful
        ];
    }

    private function extractResourceSlug(string $uri, string $name): string
    {
        // Prefer segment terakhir sebelum param, dari URI
        $segments = array_values(array_filter(explode('/', $uri), fn ($s) => $s !== '' && ! str_starts_with($s, '{') && $s !== 'api' && $s !== 'v1'));

        // Skip modul prefix
        if (count($segments) >= 2) {
            // Gunakan segment yang cocok RESOURCE_MAP kalau ada
            foreach (array_reverse($segments) as $s) {
                if (isset(self::RESOURCE_MAP[$s])) return $s;
            }
        }

        // Fallback dari route name segment terakhir sebelum verb
        $parts = explode('.', $name);
        if (count($parts) >= 2) {
            for ($i = count($parts) - 2; $i >= 0; $i--) {
                if (isset(self::RESOURCE_MAP[$parts[$i]])) return $parts[$i];
            }
        }

        // Ambil segment paling deskriptif
        return $segments[count($segments) - 1] ?? ($parts[count($parts) - 2] ?? 'resource');
    }

    private function verbFor(string $httpMethod, ?string $action, string $name, string $uri): string
    {
        // Prioritas: action name persis di ACTION_MAP
        if ($action) {
            $kebab = Str::of($action)->snake()->replace('_', '-');
            if (isset(self::ACTION_MAP[(string) $kebab])) {
                return self::ACTION_MAP[(string) $kebab];
            }
            if (isset(self::VERB_MAP[$action])) {
                return self::VERB_MAP[$action];
            }
            // Method dengan prefix (mis. downloadInvoice, bulkPrint) → cari kata kunci
            foreach (self::ACTION_MAP as $key => $verb) {
                if (str_contains(strtolower($action), $key)) return $verb;
            }
        }

        // Dari uri segment terakhir (mis. /orders/{id}/cancel)
        $last = null;
        foreach (array_reverse(explode('/', $uri)) as $seg) {
            if ($seg === '' || str_starts_with($seg, '{')) continue;
            $last = $seg;
            break;
        }
        if ($last && isset(self::ACTION_MAP[$last])) return self::ACTION_MAP[$last];

        // Fallback ke HTTP method
        return match ($httpMethod) {
            'GET'    => str_contains($uri, '{') ? 'Ambil detail' : 'Ambil daftar',
            'POST'   => 'Buat',
            'PUT', 'PATCH' => 'Ubah',
            'DELETE' => 'Hapus',
            default  => 'Akses',
        };
    }

    private function looksLikeList(string $uri, ?string $action): bool
    {
        if (str_contains($uri, '{')) return false;
        return in_array($action, ['index', 'all', 'list', null], true) || preg_match('#/(all|list|index)$#', $uri) === 1;
    }

    private function composeDescription(string $httpMethod, string $resourceLabel, string $verb, array $roles): string
    {
        $line1 = "{$verb} {$resourceLabel}.";
        $line2 = match ($httpMethod) {
            'GET'    => "Response: envelope ApiResponse `{success, message, data}` (jika daftar: dengan meta pagination).",
            'POST'   => "Response: 201 dengan data resource yang baru dibuat. Trigger side-effects sesuai domain (event/job/notifikasi) — lihat section Side Effects.",
            'PUT', 'PATCH' => "Response: 200 dengan data resource yang sudah diperbarui.",
            'DELETE' => "Response: 200/204. Perhatikan aturan revert/soft-delete yang berlaku pada resource ini.",
            default  => "Response: envelope ApiResponse standar.",
        };
        $line3 = $roles
            ? "Otorisasi: minimal satu izin dari [" . implode(', ', array_slice($roles, 0, 6)) . "]."
            : "Otorisasi: default policy modul.";
        return "{$line1}\n{$line2}\n{$line3}";
    }

    private function humanizeSlug(string $slug): string
    {
        return (string) Str::of($slug)->replace('-', ' ')->replace('_', ' ');
    }
}
