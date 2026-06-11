<?php

namespace Modules\Channel\Services;

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

    /**
     * Get paginated channels with shops.
     */
    public function getPaginatedChannels()
    {
        return $this->channelRepository->getPaginatedChannels();
    }

    /**
     * Jubelio: /marketplace/store — daftar toko marketplace yang terhubung.
     */
    public function getConnectedStores()
    {
        return $this->channelShopRepository->getPaginatedShops();
    }
}
