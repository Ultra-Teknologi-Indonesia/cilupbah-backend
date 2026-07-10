<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;

use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ChannelShopRepository
{
    public function getPaginatedShops()
    {
        return QueryBuilder::for(ChannelShop::class)
            ->with('channel')
            ->whereNull('disconnected_at')
            ->allowedSearch('shop_name')
            ->allowedFilters(
                'channel_id',
                'is_active'
            )
            ->allowedSorts('shop_name', 'created_at', 'id')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function getPaginatedShopsForStockAllocation()
    {
        return QueryBuilder::for(ChannelShop::class)
            ->with(['channel', 'stockSourceLocation'])
            ->whereNull('disconnected_at')
            ->allowedSearch('shop_name')
            ->allowedFilters('channel_id')
            ->allowedSorts('shop_name', 'created_at')
            ->defaultSort('channel_id', 'shop_name')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function findByShopId(string $shopId)
    {
        return DB::table('channel_shops')->where('shop_id', $shopId)->first();
    }

    public function findConnectedByShopId(string $shopId): ?ChannelShop
    {
        return ChannelShop::where('shop_id', $shopId)
            ->whereNull('disconnected_at')
            ->first();
    }

    public function findConnectedByStoreUrl(string $source): ?ChannelShop
    {
        if ($source === '') {
            return null;
        }

        $normalized = rtrim($source, '/');

        return ChannelShop::whereNull('disconnected_at')
            ->where(function ($q) use ($normalized) {
                $q->where('store_url', $normalized)
                    ->orWhere('store_url', $normalized . '/');
            })
            ->first();
    }

    public function getIdByShopId(string $shopId): ?string
    {
        return ChannelShop::where('shop_id', $shopId)->value('id');
    }

    public function getActiveShops()
    {
        return DB::table('channel_shops')
            ->where('is_active', true)
            ->whereNull('disconnected_at')
            ->get();
    }

    public function updateOrCreateShop(string $shopId, array $data)
    {

        $data['disconnected_at'] = null;

        return ChannelShop::updateOrCreate(
            ['shop_id' => $shopId],
            $data
        );
    }

    public function getAllTikTokShops()
    {
        $channelId = \Modules\Channel\Models\Channel::where('code', 'tiktok')->value('id');

        return ChannelShop::where('channel_id', $channelId)->orderBy('id', 'desc')->get();
    }

    public function getShopsByChannelCode(string $code)
    {
        $channelId = \Modules\Channel\Models\Channel::where('code', $code)->value('id');

        return ChannelShop::where('channel_id', $channelId)
            ->whereNull('disconnected_at')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function markIntegrationHealthy(string $id): void
    {
        ChannelShop::where('id', $id)->update([
            'integration_status' => 'normal',
            'last_error' => null,
            'last_synced_at' => now(),
        ]);
    }

    public function markIntegrationError(string $id, ?string $message): void
    {
        ChannelShop::where('id', $id)->update([
            'integration_status' => 'error',
            'last_error' => $message,
        ]);
    }

    public function findByUuid(string $id): ?ChannelShop
    {
        return ChannelShop::find($id);
    }

    public function updateShop(ChannelShop $shop, array $data): bool
    {
        return $shop->update($data);
    }

    public function findById(string $id)
    {
        return ChannelShop::find($id);
    }

    public function disconnectShop(string $id): bool
    {
        return ChannelShop::where('id', $id)->update([
            'disconnected_at' => now(),
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ]) > 0;
    }

    public function updateTokens(string $id, array $tokenData): bool
    {
        return ChannelShop::where('id', $id)->update($tokenData) > 0;
    }
}
