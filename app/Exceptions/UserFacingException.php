<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class UserFacingException extends RuntimeException
{
    protected string $title;

    protected int $status;

    protected ?array $errors;

    public function __construct(
        string $title,
        string $message,
        int $status = 422,
        ?array $errors = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->title = $title;
        $this->status = $status;
        $this->errors = $errors;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrors(): ?array
    {
        return $this->errors;
    }
}
