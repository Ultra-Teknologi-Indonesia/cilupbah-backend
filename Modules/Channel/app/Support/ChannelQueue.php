<?php

declare(strict_types=1);

namespace Modules\Channel\Support;

use InvalidArgumentException;

final class ChannelQueue
{
    private const CHANNELS = ['shopee', 'tiktok', 'lazada'];

    public static function for(string $channel, string $stage): string
    {
        $channel = strtolower(trim($channel));

        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Channel queue tidak didukung: {$channel}");
        }

        return match ($stage) {
            'cancellation' => (string) config("queue.names.{$channel}_cancellation"),
            'fulfillment' => (string) config("queue.names.{$channel}_fulfillment"),
            'awb_request' => (string) config("queue.routing.label_awb_request.queues.{$channel}"),
            'awb_poll' => (string) config("queue.routing.label_awb_poll.queues.{$channel}"),
            'label_download' => (string) config("queue.routing.label_download.queues.{$channel}"),
            default => throw new InvalidArgumentException("Tahap queue tidak didukung: {$stage}"),
        };
    }

    public static function isSupported(string $channel): bool
    {
        return in_array(strtolower(trim($channel)), self::CHANNELS, true);
    }
}
