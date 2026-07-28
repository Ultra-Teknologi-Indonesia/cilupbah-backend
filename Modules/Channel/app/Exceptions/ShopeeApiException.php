<?php

namespace Modules\Channel\Exceptions;

use Modules\Channel\Support\ShopeeErrorCatalog;

class ShopeeApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $category,
        string $message,
        public readonly ?string $rawMessage = null,
        public readonly ?string $errorInfo = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->category === ShopeeErrorCatalog::RETRYABLE;
    }

    public function isUserFixable(): bool
    {
        return $this->category === ShopeeErrorCatalog::USER_FIXABLE;
    }

    public function isFatal(): bool
    {
        return $this->category === ShopeeErrorCatalog::FATAL;
    }

    public function context(): array
    {
        return [
            'error_code' => $this->errorCode,
            'category' => $this->category,
            'raw_message' => $this->rawMessage,
            'error_info' => $this->errorInfo,
        ];
    }
}
