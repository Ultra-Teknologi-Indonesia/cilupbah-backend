<?php

namespace Modules\Channel\Services;

use Modules\Channel\Repositories\ChannelRepository;

class ChannelService
{
    protected ChannelRepository $channelRepository;

    public function __construct(ChannelRepository $channelRepository)
    {
        $this->channelRepository = $channelRepository;
    }

    /**
     * Get paginated channels with shops.
     */
    public function getPaginatedChannels()
    {
        return $this->channelRepository->getPaginatedChannels();
    }
}
