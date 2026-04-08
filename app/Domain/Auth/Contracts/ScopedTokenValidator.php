<?php

declare(strict_types=1);

namespace App\Domain\Auth\Contracts;

interface ScopedTokenValidator
{
    /**
     * Validates an access token against the parent server, scoped to the given scope string.
     *
     * Returns true if the token is valid for the given scope.
     * Returns false on any validation failure (expired, wrong scope, network error).
     *
     * Implementations must NOT cache the result. Each call performs a fresh validation.
     * Implementations must NOT log the token value at any log level.
     */
    public function validate(string $token, string $scope): bool;
}
