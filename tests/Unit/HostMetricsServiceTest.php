<?php

declare(strict_types=1);

use App\Domain\Logs\Contracts\LogBroadcaster;
use App\Domain\Logs\DTOs\NormalizedLogPayloadDTO;
use App\Domain\Metrics\Contracts\SystemMetricsProvider;
use App\Domain\Metrics\DTOs\SystemMetricsSnapshotDTO;
use App\Domain\Metrics\Services\HostMetricsService;
use Psr\Log\NullLogger;

it('broadcasts host metrics payload', function () {
    $provider = new class implements SystemMetricsProvider
    {
        public function snapshot(): SystemMetricsSnapshotDTO
        {
            return new SystemMetricsSnapshotDTO(
                cpu: [1000, 200],
                memoryTotalBytes: 1000,
                memoryAvailableBytes: 400,
                diskTotalBytes: 2000,
                diskFreeBytes: 500,
                loadAverage: [0.1, 0.2, 0.3],
                processCount: 123,
            );
        }
    };

    $broadcaster = new class implements LogBroadcaster
    {
        /** @var array<int, NormalizedLogPayloadDTO> */
        public array $payloads = [];

        public function broadcast(NormalizedLogPayloadDTO $payload): void
        {
            $this->payloads[] = $payload;
        }
    };

    $service = new HostMetricsService($provider, $broadcaster, new NullLogger);

    $service->collectAndBroadcast('main-swarm');
    $service->collectAndBroadcast('main-swarm');

    expect($broadcaster->payloads)->toHaveCount(2);
    expect($broadcaster->payloads[1]->toArray()['event'])->toBe('host.metrics');
    expect($broadcaster->payloads[1]->toArray()['metrics']['memory']['used_bytes'])->toBe(600);
});
