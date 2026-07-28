<?php

namespace Modules\Channel\Exceptions;

use Modules\Channel\Support\TikTokErrorCatalog;

class TikTokApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $category,
        string $message,
        public readonly ?string $rawMessage = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->category === TikTokErrorCatalog::RETRYABLE;
    }

    public function isUserFixable(): bool
    {
        return $this->category === TikTokErrorCatalog::USER_FIXABLE;
    }

    public function isFatal(): bool
    {
        return $this->category === TikTokErrorCatalog::FATAL;
    }

    public function context(): array
    {
        return [
            'error_code' => $this->errorCode,
            'category' => $this->category,
            'raw_message' => $this->rawMessage,
        ];
    }
}
