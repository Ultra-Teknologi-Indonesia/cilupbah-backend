<?php

namespace Modules\Channel\Contracts;

interface ChunkedDownloadable
{
    public function listProductIds(string $shopId): array;

    public function downloadProductIds(string $shopId, array $externalIds): array;
}
