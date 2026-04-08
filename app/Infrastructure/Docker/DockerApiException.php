<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

use RuntimeException;
use Throwable;

final class DockerApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function isUnavailable(): bool
    {
        return $this->statusCode === 0 || $this->statusCode === 503;
    }
}
