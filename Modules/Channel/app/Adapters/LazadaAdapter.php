<?php

namespace Modules\Channel\Adapters;

use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaClient;
use Modules\Product\Models\Product;

/**
 * Adapter Lazada (Fase 2: kerangka; method produk diisi di Fase 3 plan Lazada Omnichannel).
 * Method yang belum tersedia mengembalikan success=false dengan pesan eksplisit — tidak melempar,
 * agar job sync generik mencatat kegagalan dengan rapi alih-alih error 500/job retry-loop.
 */
class LazadaAdapter implements MarketplaceAdapterInterface
{
    public function __construct(
        protected LazadaClient $client,
    ) {}

    public function getChannelCode(): string
    {
        return 'lazada';
    }

    // ==================== Product Sync (Fase 3) ====================

    public function pushProduct(Product $product, ChannelShop $shop): array
    {
        return $this->notImplemented('pushProduct');
    }

    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        return $this->notImplemented('updateProduct');
    }

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->notImplemented('deleteProduct');
    }

    public function activateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->notImplemented('activateProduct');
    }

    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->notImplemented('deactivateProduct');
    }

    public function syncPriceAndStock(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        return $this->notImplemented('syncPriceAndStock');
    }

    // ==================== Inbound Mapping (Fase 3) ====================

    public function mapInboundProduct(array $channelData, string $shopId): array
    {
        return [];
    }

    private function notImplemented(string $operation): array
    {
        return [
            'success' => false,
            'message' => "Lazada {$operation} belum diimplementasikan (Fase 3).",
        ];
    }
}
