<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Services;

use App\Domain\Logs\Contracts\LogBroadcaster;
use App\Domain\Logs\DTOs\NormalizedLogPayloadDTO;
use App\Domain\Metrics\Contracts\SystemMetricsProvider;
use App\Domain\Metrics\DTOs\SystemMetricsSnapshotDTO;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

final class HostMetricsService
{
    private ?int $previousCpuTotal = null;
    private ?int $previousCpuIdle = null;

    public function __construct(
        private SystemMetricsProvider $provider,
        private LogBroadcaster $broadcaster,
        private LoggerInterface $logger,
    ) {
    }

    public function collectAndBroadcast(string $swarmKey): void
    {
        $snapshot = $this->provider->snapshot();
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $cpuPercent = $this->calculateCpuPercent($snapshot);
        $memoryTotal = $snapshot->memoryTotalBytes;
        $memoryAvailable = $snapshot->memoryAvailableBytes;
        $memoryUsed = $this->calculateUsed($memoryTotal, $memoryAvailable);
        $diskTotal = $snapshot->diskTotalBytes;
        $diskFree = $snapshot->diskFreeBytes;
        $diskUsed = $this->calculateUsed($diskTotal, $diskFree);

        $payload = new NormalizedLogPayloadDTO([
            'event' => 'host.metrics',
            'timestamp' => $timestamp->format(DateTimeImmutable::ATOM),
            'swarm' => [
                'key' => $swarmKey,
            ],
            'host' => [
                'hostname' => gethostname() ?: null,
            ],
            'metrics' => [
                'cpu_percent' => $cpuPercent,
                'memory' => [
                    'total_bytes' => $memoryTotal,
                    'used_bytes' => $memoryUsed,
                ],
                'disk' => [
                    'path' => '/',
                    'total_bytes' => $diskTotal,
                    'used_bytes' => $diskUsed,
                ],
                'load_avg' => $snapshot->loadAverage,
                'process_count' => $snapshot->processCount,
            ],
        ], $timestamp);

        $this->broadcaster->broadcast($payload);
    }

    private function calculateUsed(?int $total, ?int $available): ?int
    {
        if ($total === null || $available === null) {
            return null;
        }

        return max(0, $total - $available);
    }

    private function calculateCpuPercent(SystemMetricsSnapshotDTO $snapshot): ?float
    {
        if ($snapshot->cpu === null) {
            return null;
        }

        $total = $snapshot->cpu[0] ?? null;
        $idle = $snapshot->cpu[1] ?? null;

        if ($total === null || $idle === null) {
            return null;
        }

        if ($this->previousCpuTotal === null || $this->previousCpuIdle === null) {
            $this->previousCpuTotal = $total;
            $this->previousCpuIdle = $idle;

            $this->logger->info('Host metrics CPU baseline recorded.');

            return null;
        }

        $deltaTotal = $total - $this->previousCpuTotal;
        $deltaIdle = $idle - $this->previousCpuIdle;

        $this->previousCpuTotal = $total;
        $this->previousCpuIdle = $idle;

        if ($deltaTotal <= 0) {
            return null;
        }

        $usage = (1 - ($deltaIdle / $deltaTotal)) * 100;

        return round(max(0, min(100, $usage)), 2);
    }
}
