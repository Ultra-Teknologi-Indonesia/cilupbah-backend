<?php

namespace Modules\Product\Support;

use Illuminate\Support\Facades\DB;

class MasterFeedContext
{
    /** @var array<int|string, string>|null */
    private static ?array $attributes = null;

    /** @var array<string, object>|null */
    private static ?array $shops = null;

    /** @var array<string, object>|null */
    private static ?array $channels = null;

    public static function warmup(): void
    {
        if (self::$attributes === null) {
            self::$attributes = DB::table('attributes')->pluck('name', 'id')->all();
        }

        if (self::$shops === null) {
            self::$shops = DB::table('channel_shops')
                ->select(['id', 'channel_id', 'shop_id', 'shop_name'])
                ->get()
                ->keyBy('id')
                ->all();
        }

        if (self::$channels === null) {
            self::$channels = DB::table('channels')
                ->select(['id', 'code', 'name'])
                ->get()
                ->keyBy('id')
                ->all();
        }
    }

    public static function reset(): void
    {
        self::$attributes = null;
        self::$shops = null;
        self::$channels = null;
    }

    public static function getAttributeName(int|string|null $attributeId): ?string
    {
        self::warmup();

        return $attributeId !== null ? (self::$attributes[$attributeId] ?? null) : null;
    }

    public static function getShop(?string $shopId): ?object
    {
        self::warmup();

        return $shopId !== null ? (self::$shops[$shopId] ?? null) : null;
    }

    public static function getChannel(?string $channelId): ?object
    {
        self::warmup();

        return $channelId !== null ? (self::$channels[$channelId] ?? null) : null;
    }
}
