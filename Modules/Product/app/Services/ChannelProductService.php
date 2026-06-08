<?php

namespace Modules\Product\Services;

use Modules\Product\Repositories\ProductRepository;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Illuminate\Pagination\LengthAwarePaginator;

class ChannelProductService
{
    protected ProductRepository $productRepo;

    public function __construct(ProductRepository $productRepo)
    {
        $this->productRepo = $productRepo;
    }

    public function getChannelProducts(string $shopId): LengthAwarePaginator
    {
        return $this->productRepo->getPaginatedProductsByChannel($shopId);
    }

    public function getProductDetail(string $externalId, string $shopId)
    {
        return $this->productRepo->findByExternalId($externalId, $shopId);
    }

    public function createAndPushProduct(array $data, string $shopId): array
    {
        $productService = app(ProductService::class);
        $productId = $productService->createProduct($data);

        SyncProductToChannelJob::dispatch($productId, $shopId, 'push');

        return [
            'id' => $productId,
            'message' => 'Produk dibuat dan antrean sinkronisasi telah dijalankan'
        ];
    }

    public function updateAndPushProduct(string $externalId, string $shopId, array $data): array
    {
        $product = $this->productRepo->findByExternalId($externalId, $shopId);

        $product->update($data);

        SyncProductToChannelJob::dispatch($product->id, $shopId, 'update');

        return [
            'id' => $product->id,
            'message' => 'Produk diperbarui dan antrean sinkronisasi telah dijalankan'
        ];
    }

    public function deleteProduct(string $externalId, string $shopId): void
    {
        $product = $this->productRepo->findByExternalId($externalId, $shopId);

        SyncProductToChannelJob::dispatch($product->id, $shopId, 'delete');

        $product->delete();
    }

    public function activateProduct(string $externalId, string $shopId): void
    {
        $product = $this->productRepo->findByExternalId($externalId, $shopId);
        $product->update(['is_active' => true]);

        SyncProductToChannelJob::dispatch($product->id, $shopId, 'activate');
    }

    public function deactivateProduct(string $externalId, string $shopId): void
    {
        $product = $this->productRepo->findByExternalId($externalId, $shopId);
        $product->update(['is_active' => false]);

        SyncProductToChannelJob::dispatch($product->id, $shopId, 'deactivate');
    }

    public function updateStock(string $externalId, string $shopId): void
    {
        $product = $this->productRepo->findByExternalId($externalId, $shopId);
        SyncProductToChannelJob::dispatch($product->id, $shopId, 'sync_price_stock');
    }

    public function updatePrice(string $externalId, string $shopId): void
    {
        $product = $this->productRepo->findByExternalId($externalId, $shopId);
        SyncProductToChannelJob::dispatch($product->id, $shopId, 'sync_price_stock');
    }
}
