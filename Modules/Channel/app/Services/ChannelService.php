<?php

namespace Modules\Channel\Services;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Channel\Jobs\ResyncShopStockJob;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelRepository;
use Modules\Channel\Repositories\ChannelShopRepository;

class ChannelService
{
    protected ChannelRepository $channelRepository;

    protected ChannelShopRepository $channelShopRepository;

    public function __construct(
        ChannelRepository $channelRepository,
        ChannelShopRepository $channelShopRepository,
    ) {
        $this->channelRepository = $channelRepository;
        $this->channelShopRepository = $channelShopRepository;
    }

    public function getPaginatedChannels()
    {
        return $this->channelRepository->getPaginatedChannels();
    }

    public function getConnectedStores()
    {
        return $this->channelShopRepository->getPaginatedShops();
    }

    public function findConnectedStoreByUrl(string $url)
    {
        return $this->channelShopRepository->findConnectedByStoreUrl($url);
    }

    public function getPaginatedStores(string $channel)
    {
        return $this->channelShopRepository->getPaginatedShops($channel);
    }

    public function syncOrdersForAllStores(string $channel, Closure $pullOrders): array
    {
        $total = 0;
        $stores = [];

        foreach ($this->channelShopRepository->getShopsByChannelCode($channel) as $shop) {
            if (! $shop->is_active || ! $shop->access_token) {
                continue;
            }

            try {
                $count = (int) $pullOrders($shop->shop_id);
                $total += $count;
                $this->channelShopRepository->markOrderSyncOk($shop->id);
                $stores[] = [
                    'shop_id' => $shop->shop_id,
                    'shop_name' => $shop->shop_name,
                    'status' => 'success',
                    'pulled_count' => $count,
                ];
            } catch (\Throwable $e) {
                $this->channelShopRepository->markOrderSyncProblem($shop->id, $e->getMessage());
                $stores[] = [
                    'shop_id' => $shop->shop_id,
                    'shop_name' => $shop->shop_name,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['total' => $total, 'stores' => $stores];
    }

    public function runForAllActiveStores(string $channel, Closure $operation): array
    {
        $stores = [];

        foreach ($this->channelShopRepository->getShopsByChannelCode($channel) as $shop) {
            if (! $shop->is_active || ! $shop->access_token) {
                continue;
            }

            try {
                $stores[] = [
                    'shop_id' => $shop->shop_id,
                    'shop_name' => $shop->shop_name,
                    'status' => 'success',
                    'result' => $operation($shop->shop_id),
                ];
            } catch (\Throwable $e) {
                $stores[] = [
                    'shop_id' => $shop->shop_id,
                    'shop_name' => $shop->shop_name,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $stores;
    }

    public function queueProductPush(string $productId, string $channelShopId): void
    {
        SyncProductToChannelJob::dispatch($productId, $channelShopId, 'push');
    }

    public function getStockAllocationStores()
    {
        return $this->channelShopRepository->getPaginatedShopsForStockAllocation();
    }

    public function updateStoreFlags(string $id, array $data): ChannelShop
    {
        $shop = $this->channelShopRepository->findById($id);

        if (! $shop || $shop->disconnected_at !== null) {
            throw new ModelNotFoundException('Toko tidak ditemukan.');
        }

        $data = $this->mapStockSourceFields($data);
        $data = $this->applyShadowCutoff($data, $shop);

        $stockSourceChanged =(array_key_exists('stock_source_mode', $data) && $data['stock_source_mode'] !== $shop->stock_source_mode)
            || (array_key_exists('stock_source_location_id', $data) && $data['stock_source_location_id'] !== $shop->stock_source_location_id);

        $this->channelShopRepository->updateShop($shop, $data);

        if ($stockSourceChanged) {
            ResyncShopStockJob::dispatch($shop->id)->afterCommit();
        }

        return $shop->fresh('channel');
    }

    private function applyShadowCutoff(array $data, ChannelShop $shop): array
    {
        if (! array_key_exists('is_shadow_mode', $data)) {
            return $data;
        }

        $turningOn = (bool) $data['is_shadow_mode'] && ! $shop->is_shadow_mode;

        if ($turningOn) {
            $data['shadow_started_at'] = now();
            $data['shadow_last_pulled_at'] = null;
            $data['stock_push_enabled'] = false;
            $data['catalog_push_enabled'] = false;
            $data['fulfillment_push_enabled'] = false;
        }

        return $data;
    }

    private function mapStockSourceFields(array $data): array
    {
        if (array_key_exists('location_id', $data)) {
            $data['stock_source_location_id'] = $data['location_id'];
            unset($data['location_id']);
        }

        if (($data['stock_source_mode'] ?? null) === 'total') {
            $data['stock_source_location_id'] = null;
        }

        return $data;
    }

    public function disconnectStore(string $id): void
    {
        $shop = $this->channelShopRepository->findById($id);

        if (! $shop || $shop->disconnected_at !== null) {
            throw new ModelNotFoundException('Toko tidak ditemukan.');
        }

        $this->channelShopRepository->disconnectShop($id);

    }
}
