<?php

namespace Modules\Channel\Exceptions;

use RuntimeException;

/**
 * Dilempar channel service saat pembatalan seller-initiated GAGAL di sisi marketplace.
 *
 * $retryable = false  -> error final (mis. status sudah RTS/shipped, reason tidak match
 *                        status, order sudah cancelled). Job JANGAN retry.
 * $retryable = true   -> error transien (network/5xx/timeout). Job boleh retry.
 */
class ChannelCancelException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?string $channelCode = null,
    ) {
        parent::__construct($message);
    }

    public static function final(string $message, ?string $channelCode = null): self
    {
        return new self($message, retryable: false, channelCode: $channelCode);
    }

    public static function transient(string $message, ?string $channelCode = null): self
    {
        return new self($message, retryable: true, channelCode: $channelCode);
    }
}
