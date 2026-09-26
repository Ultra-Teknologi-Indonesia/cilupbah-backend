<?php

declare(strict_types=1);

namespace Modules\Channel\Support;

final class ChannelWebhookReferenceExtractor
{
    public static function orderReference(string $channel, array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $value = match (strtolower($channel)) {
            'shopee' => $data['ordersn'] ?? $data['order_sn'] ?? null,
            'tiktok' => $data['order_id'] ?? $data['main_order_id'] ?? null,
            'lazada' => $data['trade_order_id'] ?? $data['order_id'] ?? null,
            'woocommerce' => $payload['id'] ?? $payload['order_id'] ?? null,
            default => null,
        };

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : null;
    }

    public static function marketplaceStatus(string $channel, array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $value = match (strtolower($channel)) {
            'shopee' => $data['status'] ?? $data['order_status'] ?? null,
            'tiktok', 'lazada' => $data['order_status'] ?? $data['status'] ?? null,
            'woocommerce' => $payload['status'] ?? null,
            default => null,
        };

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : null;
    }

    public static function returnId(string $channel, array $payload): ?string
    {
        $keys = match (strtolower($channel)) {
            'shopee' => ['return_sn', 'refund_id', 'return_id'],
            'tiktok' => ['return_id', 'reverse_order_id'],
            'lazada' => ['reverse_order_id', 'return_id'],
            default => ['return_id', 'reverse_order_id'],
        };

        $nodes = [$payload];
        while ($nodes !== []) {
            $node = array_pop($nodes);
            if (! is_array($node)) {
                continue;
            }

            foreach ($keys as $key) {
                $value = $node[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $nodes[] = $value;
                }
            }
        }

        return null;
    }
}
