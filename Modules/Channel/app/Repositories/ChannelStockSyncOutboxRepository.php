<?php

declare(strict_types=1);

namespace Modules\Channel\Repositories;

use Modules\Product\Models\ProductChannelMapping;

final class ChannelStockSyncOutboxRepository
{
    public function lockMapping(string $mappingId): void
    {
        ProductChannelMapping::query()->whereKey($mappingId)->lockForUpdate()->firstOrFail();
    }
}
