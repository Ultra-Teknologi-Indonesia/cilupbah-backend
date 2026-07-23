<?php

namespace Modules\Report\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Repositories\ReportRepository;

class SalesProductReportService
{
    public function __construct(
        protected ReportRepository $repository,
    ) {}

    public function query(array $filters): Builder
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        return $this->repository->salesProductQuery($filters);
    }

    public function skuOptions(?string $search, int $perPage = 20): array
    {
        $paginator = $this->repository->salesProductSkuOptions($search, $perPage);

        return [
            'data' => collect($paginator->items())->map(fn (ProductVariant $v) => [
                'id'        => $v->id,
                'sku'       => $v->sku,
                'name'      => $v->product?->name ?? $v->sku,
                'image_url' => $this->imageUrl($v),
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }

    private function imageUrl(ProductVariant $variant): ?string
    {
        return $this->firstMediaUrl($variant->media)
            ?? $this->firstMediaUrl($variant->product?->media);
    }

    private function firstMediaUrl($media): ?string
    {
        if (! $media || $media->isEmpty()) {
            return null;
        }

        return ($media->firstWhere('is_primary', true) ?? $media->first())->url;
    }
}
