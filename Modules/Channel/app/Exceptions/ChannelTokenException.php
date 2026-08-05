<?php

namespace Modules\Channel\Exceptions;

use RuntimeException;

class ChannelTokenException extends RuntimeException
{
    public function __construct(
        string $userMessage,
        public readonly bool $permanent,
        public readonly ?string $channelCode = null,
        public readonly string $rawMessage = '',
    ) {
        parent::__construct($userMessage);
    }
}
