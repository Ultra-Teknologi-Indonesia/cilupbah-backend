<?php

namespace Modules\Product\Observers;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;

class ProductObserver
{
    /**
     * Kolom lifecycle/arsip internal. Perubahan yang HANYA menyentuh kolom ini
     * (arsip, pulihkan, approve/verifikasi, ubah status) tidak boleh dipropagasi
     * ke marketplace — sesuai perilaku Jubelio: hapus/arsip produk di internal
     * tidak menghapus/menonaktifkan listing yang sudah tayang di channel.
     */
    private const LIFECYCLE_FIELDS = [
        'status',
        'archived_at',
        'archived_by',
        'archive_reason',
        'verified_at',
        'verified_by',
        'deleted_at',
        'updated_at',
    ];

    public function updated(Product $product): void
    {
        // Produk yang berstatus Arsip disembunyikan dari master stok Jubelio —
        // jangan pernah push perubahannya ke marketplace.
        if ($product->status === Product::STATUS_ARCHIVED) {
            return;
        }

        // Lewati bila perubahan hanya menyentuh kolom lifecycle/arsip (mis. aksi
        // arsip/pulihkan/approve), bukan konten produk yang perlu disinkronkan.
        $changed = array_keys($product->getChanges());
        if ($changed !== [] && array_diff($changed, self::LIFECYCLE_FIELDS) === []) {
            return;
        }

        $debounceKey = "product_sync_debounce:{$product->id}";
        if (Cache::has($debounceKey)) {
            return;
        }
        Cache::put($debounceKey, true, 5);

        // Hanya sinkronkan ke mapping yang aktif. Mapping 'pending' belum pernah
        // di-upload, dan 'deactivated' sengaja dimatikan user — keduanya tidak
        // boleh ikut ter-update agar konsisten dengan SyncStockToChannelsJob.
        $mappings = $product->channelMappings()
            ->whereNotIn('sync_status', [
                ProductChannelMapping::STATUS_PENDING,
                ProductChannelMapping::STATUS_DEACTIVATED,
            ])
            ->get();

        foreach ($mappings as $mapping) {
            SyncProductToChannelJob::dispatch($product->id, $mapping->channel_shop_id, 'update');
        }
    }
}
