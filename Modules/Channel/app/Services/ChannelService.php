<?php

namespace Modules\Channel\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public function updateStoreFlags(string $id, array $data): ChannelShop
    {
        $shop = $this->channelShopRepository->findById($id);

        if (! $shop || $shop->disconnected_at !== null) {
            throw new ModelNotFoundException('Toko tidak ditemukan.');
        }

        $this->channelShopRepository->updateShop($shop, $data);

        return $shop->fresh('channel');
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
