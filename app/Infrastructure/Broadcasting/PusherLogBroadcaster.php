<?php

declare(strict_types=1);

namespace App\Infrastructure\Broadcasting;

use App\Domain\Logs\Contracts\LogBroadcaster;
use App\Domain\Logs\DTOs\NormalizedLogPayloadDTO;
use Illuminate\Support\Facades\Broadcast;
use Psr\Log\LoggerInterface;
use Throwable;

final class PusherLogBroadcaster implements LogBroadcaster
{
    public function __construct(
        private string $fallbackChannel,
        private ?string $serverId,
        private ?string $event,
        private LoggerInterface $logger,
        private bool $logSocketErrors,
    ) {}

    public function broadcast(NormalizedLogPayloadDTO $payload): void
    {
        $event = $this->event ?: (string) ($payload->toArray()['event'] ?? 'log');
        $channel = $this->resolveChannel($payload);

        try {
            Broadcast::connection('pusher')->broadcast([$channel], $event, $payload->toArray());
        } catch (Throwable $exception) {
            if ($this->logSocketErrors) {
                $this->logger->warning('Pusher broadcast failed.', [
                    'channel' => $channel,
                    'event' => $event,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function resolveChannel(NormalizedLogPayloadDTO $payload): string
    {
        $serverId = trim((string) $this->serverId);

        if ($serverId === '') {
            return $this->fallbackChannel;
        }

        $event = (string) ($payload->toArray()['event'] ?? 'log');
        $prefix = $event === 'host.metrics' ? 'server' : 'logs.server';

        return sprintf('%s.%s', $prefix, $serverId);
    }
}
