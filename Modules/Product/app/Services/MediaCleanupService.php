<?php

namespace Modules\Product\Services;

use App\Services\UploadService;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;

class MediaCleanupService
{
    public function __construct(
        private readonly UploadService $uploads,
    ) {
    }

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
