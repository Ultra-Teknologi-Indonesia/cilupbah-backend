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

    public function getPaginatedChannels()
    {
        return $this->channelRepository->getPaginatedChannels();
    }

    public function getConnectedStores()
    {
        return $this->channelShopRepository->getPaginatedShops();
    }
}
