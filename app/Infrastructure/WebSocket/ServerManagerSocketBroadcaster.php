<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Logs\Contracts\LogBroadcaster;
use App\Domain\Logs\DTOs\NormalizedLogPayloadDTO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ServerManagerSocketBroadcaster implements LogBroadcaster
{
    private WebSocketClient $client;

    public function __construct(
        private string $endpoint,
        private ?string $token,
        private int $connectTimeout,
        private int $timeout,
        private LoggerInterface $logger,
        private bool $logSocketErrors,
    ) {
        $headers = [];

        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        $this->client = new WebSocketClient($this->endpoint, $this->connectTimeout, $this->timeout, $headers);
    }

    public function broadcast(NormalizedLogPayloadDTO $payload): void
    {
        if ($this->endpoint === '') {
            throw new RuntimeException('Upstream websocket endpoint is not configured.');
        }

        $encoded = json_encode($payload->toArray(), JSON_THROW_ON_ERROR);

        try {
            $this->client->send($encoded);
        } catch (Throwable $exception) {
            if ($this->logSocketErrors) {
                $this->logger->warning('Upstream socket send failed.', [
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->client->close();

            throw $exception;
        }
    }
}
