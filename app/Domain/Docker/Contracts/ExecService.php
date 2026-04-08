<?php

declare(strict_types=1);

namespace App\Domain\Docker\Contracts;

interface ExecService
{
    /**
     * Creates a Docker exec instance on the given container.
     * The command is always hardcoded to an interactive shell.
     * Returns the exec instance ID.
     */
    public function createExec(string $containerId): string;

    /**
     * Starts the exec instance and returns the raw hijacked socket.
     * The caller is responsible for closing the socket.
     *
     * @return resource
     */
    public function startExec(string $execId);

    /**
     * Resizes the TTY for an exec instance.
     */
    public function resizeExec(string $execId, int $cols, int $rows): void;
}
