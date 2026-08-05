<?php

namespace Modules\Product\Services;

use App\Services\UploadService;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;

/**
 * Membersihkan media (Upload + file di object storage/R2) yang menjadi yatim
 * setelah produk/varian/media dihapus, agar storage tidak menumpuk.
 *
 * Pola pemakaian aman: SNAPSHOT media_uuid sebelum operasi hapus/ubah, jalankan
 * operasinya (dalam transaksi), lalu pruneOrphans(snapshot) SETELAH commit —
 * hanya media_uuid yang sudah tidak direferensikan product_media mana pun yang
 * dihapus (ref-count guard), jadi media yang masih dipakai produk lain aman.
 */
class MediaCleanupService
{
    public function __construct(
        private readonly UploadService $uploads,
    ) {
    }

    /**
     * Semua media_uuid milik satu produk (level produk + seluruh variannya).
     *
     * @return array<int, string>
     */
    public function collectByProduct(string $productId): array
    {
        $variantIds = ProductVariant::where('product_id', $productId)->pluck('id')->all();

        return ProductMedia::query()
            ->where('product_id', $productId)
            ->when($variantIds !== [], fn ($q) => $q->orWhereIn('variant_id', $variantIds))
            ->pluck('media_uuid')
            ->filter()
            ->map(fn ($v) => (string) $v)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Hapus Upload (beserta file R2) untuk media_uuid yang sudah tak direferensikan
     * product_media mana pun. Idempoten & aman diulang. Mengembalikan jumlah terhapus.
     *
     * @param  iterable<string|null>  $mediaUuids
     */
    public function pruneOrphans(iterable $mediaUuids): int
    {
        $deleted = 0;
        $seen = [];

        foreach ($mediaUuids as $uuid) {
            $uuid = (string) $uuid;
            if ($uuid === '' || isset($seen[$uuid])) {
                continue;
            }
            $seen[$uuid] = true;

            if (ProductMedia::where('media_uuid', $uuid)->exists()) {
                continue;
            }

            if ($this->uploads->delete($uuid)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
