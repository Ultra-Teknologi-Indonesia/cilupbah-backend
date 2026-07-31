<?php

namespace Modules\Channel\Exceptions;

use RuntimeException;

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
