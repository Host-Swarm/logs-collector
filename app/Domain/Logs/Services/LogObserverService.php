<?php

declare(strict_types=1);

namespace App\Domain\Logs\Services;

use App\Domain\Logs\Contracts\LogBroadcaster;
use App\Domain\Logs\Contracts\LogStreamService;
use App\Domain\Logs\DTOs\DiscoveredContainerDTO;
use App\Domain\Logs\DTOs\LogStreamOptionsDTO;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class LogObserverService
{
    public function __construct(
        private LogStreamService $streamService,
        private LogNormalizerService $normalizer,
        private LogBroadcaster $broadcaster,
        private LoggerInterface $logger,
        private bool $logDevelopmentFormat,
        private bool $logSocketErrors,
        private bool $logPayloads,
    ) {}

    public function observe(DiscoveredContainerDTO $container, LogStreamOptionsDTO $options): void
    {
        $this->streamService->stream($container, $options, function (string $channel, string $payload) use ($container, $options): void {
            [$timestamp, $message] = $this->parsePayload($payload, $options->timestamps);
            $normalized = $this->normalizer->normalize($container, $channel, $message, $timestamp);

            if ($this->logPayloads) {
                $this->logger->info('Broadcast payload', $normalized->toArray());
            }

            try {
                $this->broadcaster->broadcast($normalized);
            } catch (Throwable $exception) {
                if ($this->logSocketErrors) {
                    $this->logger->warning('Upstream socket broadcast failed.', [
                        'container_id' => $container->containerId,
                        'service_id' => $container->serviceId,
                        'stack_name' => $container->stackName,
                        'service_name' => $container->serviceName,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if ($this->logDevelopmentFormat) {
                $this->logger->info($this->formatDevelopmentLine($container, $channel, $message), [
                    'container_id' => $container->containerId,
                    'service_id' => $container->serviceId,
                    'stack_name' => $container->stackName,
                    'service_name' => $container->serviceName,
                ]);
            }
        });
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: string}
     */
    private function parsePayload(string $payload, bool $timestamps): array
    {
        if (! $timestamps) {
            return [null, $payload];
        }

        if (! preg_match('/^(?<timestamp>[^\s]+)\s+(?<message>.*)$/s', $payload, $matches)) {
            return [null, $payload];
        }

        try {
            $timestamp = new DateTimeImmutable($matches['timestamp']);
        } catch (Throwable) {
            return [null, $payload];
        }

        return [$timestamp, $matches['message']];
    }

    private function formatDevelopmentLine(DiscoveredContainerDTO $container, string $channel, string $message): string
    {
        $stack = $container->stackName ?? 'unknown';
        $service = $container->serviceName;
        $identifier = $container->taskId ?? $container->containerId;
        $status = $container->containerStatus ?? $container->taskState ?? 'unknown';

        return sprintf('%s||%s||%s||%s||%s', $stack, $service, $identifier, $status, $message);
    }
}
