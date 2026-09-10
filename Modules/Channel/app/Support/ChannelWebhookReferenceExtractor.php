<?php

declare(strict_types=1);

namespace Modules\Channel\Support;

final class ChannelWebhookReferenceExtractor
{
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
