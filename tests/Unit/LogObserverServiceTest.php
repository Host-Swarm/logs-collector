<?php

declare(strict_types=1);

use App\Domain\Logs\Contracts\LogBroadcaster;
use App\Domain\Logs\Contracts\LogStreamService;
use App\Domain\Logs\DTOs\DiscoveredContainerDTO;
use App\Domain\Logs\DTOs\LogStreamOptionsDTO;
use App\Domain\Logs\DTOs\NormalizedLogPayloadDTO;
use App\Domain\Logs\Services\LogNormalizerService;
use App\Domain\Logs\Services\LogObserverService;
use Psr\Log\AbstractLogger;

it('broadcasts normalized log payloads', function () {
    $stream = new class implements LogStreamService
    {
        public function stream(DiscoveredContainerDTO $container, LogStreamOptionsDTO $options, callable $onFrame): void
        {
            $onFrame('stdout', '2026-03-10T10:20:30+00:00 Application started');
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

    $logger = new MemoryLogger;

    $observer = new LogObserverService(
        streamService: $stream,
        normalizer: new LogNormalizerService,
        broadcaster: $broadcaster,
        logger: $logger,
        logDevelopmentFormat: true,
    );

    $observer->observe(buildContainer(), new LogStreamOptionsDTO(
        tail: 100,
        follow: false,
        timestamps: true,
        stdout: true,
        stderr: true,
    ));

    expect($broadcaster->payloads)->toHaveCount(1);
    expect($broadcaster->payloads[0]->toArray()['log']['message'])->toBe('Application started');
    expect($logger->records[0]['message'])->toContain('host-swarm||server-manager||task-789||running||Application started');
});

it('continues when upstream broadcast fails', function () {
    $stream = new class implements LogStreamService
    {
        public function stream(DiscoveredContainerDTO $container, LogStreamOptionsDTO $options, callable $onFrame): void
        {
            $onFrame('stdout', '2026-03-10T10:20:30+00:00 First');
            $onFrame('stdout', '2026-03-10T10:20:31+00:00 Second');
        }
    };

    $broadcaster = new class implements LogBroadcaster
    {
        private int $count = 0;

        public function broadcast(NormalizedLogPayloadDTO $payload): void
        {
            $this->count += 1;

            if ($this->count === 1) {
                throw new RuntimeException('upstream down');
            }
        }
    };

    $logger = new MemoryLogger;

    $observer = new LogObserverService(
        streamService: $stream,
        normalizer: new LogNormalizerService,
        broadcaster: $broadcaster,
        logger: $logger,
        logDevelopmentFormat: false,
    );

    $observer->observe(buildContainer(), new LogStreamOptionsDTO(
        tail: 100,
        follow: false,
        timestamps: true,
        stdout: true,
        stderr: true,
    ));

    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['level'])->toBe('warning');
});

function buildContainer(): DiscoveredContainerDTO
{
    return new DiscoveredContainerDTO(
        swarmKey: 'main-swarm',
        serviceId: 'service-123',
        serviceName: 'server-manager',
        serviceLabels: ['com.docker.stack.namespace' => 'host-swarm'],
        serviceMode: 'replicated',
        taskId: 'task-789',
        taskSlot: 1,
        desiredState: 'running',
        taskState: 'running',
        nodeId: 'node-1',
        nodeHostname: 'manager-01',
        containerId: 'container-456',
        containerName: 'server-manager.1.abcd',
        containerLabels: ['com.docker.swarm.service.name' => 'server-manager'],
        containerState: 'running',
        containerStatus: 'running',
        containerImage: 'server-manager:latest',
        containerTty: false,
        stackName: 'host-swarm',
        discoveredAt: new \DateTimeImmutable('2026-03-10T10:20:00+00:00'),
    );
}

final class MemoryLogger extends AbstractLogger
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  string|Stringable  $message
     * @param  array<string, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
