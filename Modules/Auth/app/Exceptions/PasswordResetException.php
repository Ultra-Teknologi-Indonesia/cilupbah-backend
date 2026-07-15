<?php

namespace Modules\Auth\Exceptions;

use RuntimeException;

class PasswordResetException extends RuntimeException
{
    public function __construct(
        public readonly string $title,
        public readonly string $userMessage,
        public readonly int $statusCode = 422,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($userMessage);
    }
}
