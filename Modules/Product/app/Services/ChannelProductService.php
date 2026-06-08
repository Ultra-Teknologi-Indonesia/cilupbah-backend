<?php

namespace Modules\Product\Services;

use Modules\Product\Repositories\ProductRepository;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelShop;
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
        // shopId di sini adalah shop_id marketplace; pivot menyimpan channel_shops.id (UUID).
        $channelShopId = $this->resolveChannelShopId($shopId);

        return $this->productRepo->getPaginatedProductsByChannel($channelShopId ?? '');
    }

    public function getProductDetail(string $externalId, string $shopId)
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        return $this->productRepo->findByExternalId($externalId, $channelShopId);
    }

    public function createAndPushProduct(array $data, string $shopId): array
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $productService = app(ProductService::class);
        $productId = $productService->createProduct($data);

        SyncProductToChannelJob::dispatch($productId, $channelShopId, 'push');

        return [
            'id' => $productId,
            'message' => 'Produk dibuat dan antrean sinkronisasi telah dijalankan'
        ];
    }

    public function updateAndPushProduct(string $externalId, string $shopId, array $data): array
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $product = $this->productRepo->findByExternalId($externalId, $channelShopId);

        $product->update($data);

        SyncProductToChannelJob::dispatch($product->id, $channelShopId, 'update');

        return [
            'id' => $product->id,
            'message' => 'Produk diperbarui dan antrean sinkronisasi telah dijalankan'
        ];
    }

    public function deleteProduct(string $externalId, string $shopId): void
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $product = $this->productRepo->findByExternalId($externalId, $channelShopId);

        SyncProductToChannelJob::dispatch($product->id, $channelShopId, 'delete');

        $product->delete();
    }

    public function activateProduct(string $externalId, string $shopId): void
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $product = $this->productRepo->findByExternalId($externalId, $channelShopId);
        $product->update(['is_active' => true]);

        SyncProductToChannelJob::dispatch($product->id, $channelShopId, 'activate');
    }

    public function deactivateProduct(string $externalId, string $shopId): void
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $product = $this->productRepo->findByExternalId($externalId, $channelShopId);
        $product->update(['is_active' => false]);

        SyncProductToChannelJob::dispatch($product->id, $channelShopId, 'deactivate');
    }

    public function updateStock(string $externalId, string $shopId): void
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $product = $this->productRepo->findByExternalId($externalId, $channelShopId);
        SyncProductToChannelJob::dispatch($product->id, $channelShopId, 'sync_price_stock');
    }

    public function updatePrice(string $externalId, string $shopId): void
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $product = $this->productRepo->findByExternalId($externalId, $channelShopId);
        SyncProductToChannelJob::dispatch($product->id, $channelShopId, 'sync_price_stock');
    }

    /**
     * Konversi shop_id marketplace → channel_shops.id (UUID) yang dipakai tabel pivot & Job.
     * Mengembalikan null jika shop_id kosong.
     */
    protected function resolveChannelShopId(string $shopId): ?string
    {
        if ($shopId === '') {
            return null;
        }

        return ChannelShop::where('shop_id', $shopId)->value('id');
    }

    /**
     * Sama seperti resolveChannelShopId() tapi melempar error bila toko tidak ditemukan.
     */
    protected function requireChannelShopId(string $shopId): string
    {
        $channelShopId = $this->resolveChannelShopId($shopId);

        if (!$channelShopId) {
            throw new \RuntimeException("Toko tidak ditemukan atau tidak aktif untuk shop_id: {$shopId}");
        }

        return $channelShopId;
    }
}
