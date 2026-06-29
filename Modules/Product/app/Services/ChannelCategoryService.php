<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Channel\Models\Channel;
use Modules\Product\Repositories\ChannelCategoryRepository;
use Ramsey\Uuid\Uuid;

class ChannelCategoryService
{
    protected ChannelCategoryRepository $repository;

    public function __construct(ChannelCategoryRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getPaginated(string $channelId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getPaginated($this->resolveChannelId($channelId), $perPage);
    }

    public function getAll(string $channelId): Collection
    {
        return $this->repository->getAll($this->resolveChannelId($channelId));
    }

    protected function resolveChannelId(string $channelId): string
    {
        if (Uuid::isValid($channelId)) {
            return $channelId;
        }

        $channel = Channel::where('code', $channelId)->first();

        if (! $channel) {
            abort(404, "Channel '{$channelId}' tidak ditemukan");
        }

        return $channel->id;
    }
}
