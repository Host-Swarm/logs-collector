<?php

declare(strict_types=1);

namespace App\Domain\Auth\Contracts;

interface TokenValidator
{
    /**
     * Validates a bearer token against the parent app, scoped to the given container ID.
     *
     * Returns true if the token is valid and was issued for this container.
     * Returns false on any validation failure (expired, wrong scope, network error).
     *
     * Implementations must NOT cache the result. Each call performs a fresh validation.
     * Implementations must NOT log the token value at any log level.
     */
    public function validate(string $token, string $containerId): bool;
}
