<?php

declare(strict_types=1);

use App\Domain\Docker\DTOs\DiscoveredContainerDTO;
use App\Domain\Docker\Services\StackService;
use App\Domain\Docker\Services\SwarmDiscoveryService;

function makeContainer(string $stackName, string $serviceId, string $serviceName, string $containerId): DiscoveredContainerDTO
{
    return new DiscoveredContainerDTO(
        swarmKey: 'main-swarm',
        serviceId: $serviceId,
        serviceName: $serviceName,
        serviceLabels: ['com.docker.stack.namespace' => $stackName],
        serviceMode: 'Replicated',
        taskId: 'task-1',
        taskSlot: 1,
        desiredState: 'running',
        taskState: 'running',
        nodeId: 'node-1',
        nodeHostname: 'worker-1',
        containerId: $containerId,
        containerName: "{$serviceName}.1.abc",
        containerLabels: [],
        containerState: 'running',
        containerStatus: 'running',
        containerImage: 'nginx:latest',
        containerTty: false,
        stackName: $stackName,
        discoveredAt: new \DateTimeImmutable,
    );
}

it('groups containers into stacks by stack name', function (): void {
    $discovery = Mockery::mock(SwarmDiscoveryService::class);
    $discovery->shouldReceive('discover')
        ->with('main-swarm')
        ->andReturn([
            makeContainer('my-app', 'svc-1', 'my-app_web', 'ctr-1'),
            makeContainer('my-app', 'svc-1', 'my-app_web', 'ctr-2'),
            makeContainer('other-stack', 'svc-2', 'other-stack_api', 'ctr-3'),
        ]);

    $service = new StackService($discovery);
    $stacks = $service->listStacks('main-swarm');

    expect($stacks)->toHaveCount(2);

    $names = array_map(fn ($s) => $s->name, $stacks);
    expect($names)->toContain('my-app');
    expect($names)->toContain('other-stack');
});

it('builds correct service container count', function (): void {
    $discovery = Mockery::mock(SwarmDiscoveryService::class);
    $discovery->shouldReceive('discover')
        ->andReturn([
            makeContainer('my-app', 'svc-1', 'my-app_web', 'ctr-1'),
            makeContainer('my-app', 'svc-1', 'my-app_web', 'ctr-2'),
        ]);

    $service = new StackService($discovery);
    $stacks = $service->listStacks('main-swarm');

    $myApp = $stacks[0];
    expect($myApp->name)->toBe('my-app');
    expect($myApp->services)->toHaveCount(1);
    expect($myApp->services[0]->containers)->toHaveCount(2);
    expect($myApp->services[0]->replicas)->toBe(2);
});

it('returns null when stack is not found', function (): void {
    $discovery = Mockery::mock(SwarmDiscoveryService::class);
    $discovery->shouldReceive('discover')
        ->andReturn([
            makeContainer('my-app', 'svc-1', 'my-app_web', 'ctr-1'),
        ]);

    $service = new StackService($discovery);
    $result = $service->findStack('main-swarm', 'does-not-exist');

    expect($result)->toBeNull();
});

it('finds a stack by name', function (): void {
    $discovery = Mockery::mock(SwarmDiscoveryService::class);
    $discovery->shouldReceive('discover')
        ->andReturn([
            makeContainer('my-app', 'svc-1', 'my-app_web', 'ctr-1'),
        ]);

    $service = new StackService($discovery);
    $result = $service->findStack('main-swarm', 'my-app');

    expect($result)->not->toBeNull();
    expect($result->name)->toBe('my-app');
});

it('returns empty list when swarm has no containers', function (): void {
    $discovery = Mockery::mock(SwarmDiscoveryService::class);
    $discovery->shouldReceive('discover')->andReturn([]);

    $service = new StackService($discovery);
    $stacks = $service->listStacks('main-swarm');

    expect($stacks)->toHaveCount(0);
});
