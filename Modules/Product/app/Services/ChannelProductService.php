<?php

namespace Modules\Product\Services;

use Modules\Product\Repositories\ProductRepository;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;
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

    public function getChannelProducts(string $shopId, int $limit = 20, ?string $syncStatus = null): LengthAwarePaginator
    {
        $channelShopId = $this->resolveChannelShopId($shopId);

        return $this->productRepo->getPaginatedProductsByChannel($channelShopId ?? '', $limit, $syncStatus);
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

        ProductSyncLog::record([
            'product_id' => $productId,
            'channel_shop_id' => $channelShopId,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_PENDING,
        ]);

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

        ProductSyncLog::record([
            'product_id' => $product->id,
            'channel_shop_id' => $channelShopId,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_PENDING,
        ]);

        return [
            'id' => $product->id,
            'message' => 'Produk diperbarui dan antrean sinkronisasi telah dijalankan'
        ];
    }

    public function deleteProduct(string $externalId, string $shopId): void
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $product = $this->productRepo->findByExternalId($externalId, $channelShopId);

        // Sesuai perilaku Jubelio: menghapus produk di internal TIDAK menghapus
        // listing di marketplace. Cukup hapus data master lokal; produk yang
        // sudah tayang di channel tetap ada (hapus permanen dilakukan manual di
        // Seller Centre masing-masing marketplace).
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

    public function unlinkProduct(string $externalId, string $shopId): void
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        $mapping = ProductChannelMapping::where('external_product_id', $externalId)
            ->where('channel_shop_id', $channelShopId)
            ->first();

        if (!$mapping) {
            throw new \RuntimeException('Produk tidak terhubung ke channel ini', 404);
        }

        if ($mapping->sync_status === 'syncing') {
            throw new \RuntimeException('Tidak bisa unlink saat proses sync berjalan', 409);
        }

        $productId = $mapping->product_id;

        $mapping->delete();

        ProductSyncLog::record([
            'product_id' => $productId,
            'channel_shop_id' => $channelShopId,
            'action' => ProductSyncLog::ACTION_UNLINK,
            'status' => ProductSyncLog::STATUS_SUCCESS,
        ]);
    }

    protected function resolveChannelShopId(string $shopId): ?string
    {
        if ($shopId === '') {
            return null;
        }

        return ChannelShop::where('shop_id', $shopId)->value('id');
    }

    protected function requireChannelShopId(string $shopId): string
    {
        $channelShopId = $this->resolveChannelShopId($shopId);

        if (!$channelShopId) {
            throw new \RuntimeException("Toko tidak ditemukan atau tidak aktif untuk shop_id: {$shopId}");
        }

        return $channelShopId;
    }
}
