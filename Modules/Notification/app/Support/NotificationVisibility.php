<?php

namespace Modules\Notification\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NotificationVisibility
{
    private const MANUAL_SOURCES = [
        '',
        'manual',
        'offline',
    ];

    private const CHANNEL_ORDER_TYPE = 'order_new';

    public static function shouldSuppress(string $type, array $data): bool
    {
        if ($type !== self::CHANNEL_ORDER_TYPE) {
            return false;
        }

        return ! in_array(self::sourceFromData($data), self::MANUAL_SOURCES, true);
    }

    public static function applyVisible(Builder $query): Builder
    {
        $driver = $query->getModel()->getConnection()->getDriverName();

        return $query->where(function (Builder $visible) use ($driver): void {
            $visible->where('type', '!=', self::CHANNEL_ORDER_TYPE)
                ->orWhereNull('data');

            $sourceExpression = match ($driver) {
                'pgsql' => "LOWER(COALESCE(NULLIF(data->>'source', ''), NULLIF(data->>'marketplace', ''), ''))",
                'mysql', 'mariadb' => "LOWER(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(data, '$.source')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(data, '$.marketplace')), ''), ''))",
                default => "LOWER(COALESCE(NULLIF(json_extract(data, '$.source'), ''), NULLIF(json_extract(data, '$.marketplace'), ''), ''))",
            };

            $visible->orWhere(function (Builder $order) use ($sourceExpression): void {
                $order->where('type', self::CHANNEL_ORDER_TYPE)
                    ->whereIn(DB::raw($sourceExpression), self::MANUAL_SOURCES);
            });
        });
    }

    private static function sourceFromData(array $data): string
    {
        $source = $data['source'] ?? $data['marketplace'] ?? '';

        return strtolower(trim((string) $source));
    }
}
