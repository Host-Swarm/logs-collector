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
        private string $channel,
        private ?string $event,
        private LoggerInterface $logger,
        private bool $logSocketErrors,
    ) {
    }

    public function broadcast(NormalizedLogPayloadDTO $payload): void
    {
        $event = $this->event ?: (string) ($payload->toArray()['event'] ?? 'log');

        try {
            Broadcast::connection('pusher')->broadcast([$this->channel], $event, $payload->toArray());
        } catch (Throwable $exception) {
            if ($this->logSocketErrors) {
                $this->logger->warning('Pusher broadcast failed.', [
                    'channel' => $this->channel,
                    'event' => $event,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
