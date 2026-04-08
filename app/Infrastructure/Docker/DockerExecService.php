<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

use App\Domain\Docker\Contracts\ExecService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DockerExecService implements ExecService
{
    public function __construct(
        private DockerHttpClient $docker,
        private LoggerInterface $logger,
    ) {}

    /**
     * Creates a Docker exec instance on the given container.
     * Returns the exec instance ID.
     *
     * The command is always hardcoded to an interactive shell (/bin/sh).
     * Callers cannot specify an arbitrary command.
     */
    public function createExec(string $containerId): string
    {
        try {
            $response = $this->docker->postJson("/containers/{$containerId}/exec", [
                'AttachStdin' => true,
                'AttachStdout' => true,
                'AttachStderr' => true,
                'Tty' => true,
                'Cmd' => ['/bin/sh'],
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Docker exec create failed.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                sprintf('Failed to create exec instance for container %s.', $containerId),
                0,
                $exception,
            );
        }

        $execId = $response['Id'] ?? null;

        if (! is_string($execId) || $execId === '') {
            throw new RuntimeException(
                sprintf('Docker exec create returned no ID for container %s.', $containerId),
            );
        }

        $this->logger->info('Docker exec instance created.', [
            'container_id' => $containerId,
            'exec_id' => $execId,
        ]);

        return $execId;
    }

    /**
     * Starts the exec instance and returns the raw hijacked socket.
     * The socket carries a raw byte stream (TTY mode).
     * Caller is responsible for closing the socket.
     *
     * @return resource
     */
    public function startExec(string $execId)
    {
        try {
            $socket = $this->docker->openHijackedStream("/exec/{$execId}/start", [
                'Detach' => false,
                'Tty' => true,
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Docker exec start failed.', [
                'exec_id' => $execId,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                sprintf('Failed to start exec instance %s.', $execId),
                0,
                $exception,
            );
        }

        return $socket;
    }
}
