<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;

class ChannelShopRepository
{
    public function findByShopId(string $shopId)
    {
        return DB::table('channel_shops')->where('shop_id', $shopId)->first();
    }

    public function getActiveShops()
    {
        return DB::table('channel_shops')->where('is_active', true)->get();
    }

    public function updateOrCreateShop(string $shopId, array $data)
    {
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

    public function findById(int $id)
    {
        return ChannelShop::find($id);
    }

    public function disconnectShop(int $id): bool
    {
        return ChannelShop::where('id', $id)->update([
            'is_active' => false,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ]) > 0;
    }

    public function updateTokens(int $id, array $tokenData): bool
    {
        return ChannelShop::where('id', $id)->update($tokenData) > 0;
    }
}
