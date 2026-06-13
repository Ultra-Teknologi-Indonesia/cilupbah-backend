<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChannelSyncService
{

    public function pushProduct(array $data, string $channel): string
    {
        Log::info("Pushing product to {$channel} channel...", $data);

        return 'tiktok-' . uniqid();
    }

    public function updateProduct(array $data, string $channel, string $externalId): bool
    {
        Log::info("Updating product {$externalId} in {$channel} channel...", $data);

        return true;
    }

    public function deleteProduct(string $channel, string $externalId): bool
    {
        Log::info("Deleting product {$externalId} from {$channel} channel...");

        return true;
    }

    public function activateProduct(string $channel, string $externalId): bool
    {
        Log::info("Activating product {$externalId} in {$channel} channel...");

        return true;
    }

    public function deactivateProduct(string $channel, string $externalId): bool
    {
        Log::info("Deactivating product {$externalId} in {$channel} channel...");

        return true;
    }

    public function updateStock(array $data, string $channel, string $externalId): bool
    {
        Log::info("Updating stock for product {$externalId} in {$channel} channel...", $data);

        return true;
    }

    public function updatePrice(array $data, string $channel, string $externalId): bool
    {
        Log::info("Updating price for product {$externalId} in {$channel} channel...", $data);

        return true;
    }
}
