<?php

use App\Domain\Logs\DTOs\DiscoveredContainerDTO;
use App\Domain\Logs\Services\LogNormalizerService;
use DateTimeImmutable;

it('normalizes container log payloads', function () {
    $container = new DiscoveredContainerDTO(
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
        discoveredAt: new DateTimeImmutable('2026-03-10T10:20:00+00:00'),
    );

    $normalizer = new LogNormalizerService();
    $timestamp = new DateTimeImmutable('2026-03-10T10:20:30+00:00');

    $payload = $normalizer->normalize($container, 'stdout', 'Application started', $timestamp);

    expect($payload->toArray())->toMatchArray([
        'event' => 'container.log',
        'timestamp' => '2026-03-10T10:20:30+00:00',
        'swarm' => [
            'key' => 'main-swarm',
        ],
        'service' => [
            'id' => 'service-123',
            'name' => 'server-manager',
        ],
        'container' => [
            'id' => 'container-456',
            'name' => 'server-manager.1.abcd',
        ],
        'log' => [
            'channel' => 'stdout',
            'raw' => 'Application started',
            'message' => 'Application started',
        ],
        'meta' => [
            'task_id' => 'task-789',
            'task_slot' => 1,
            'node_id' => 'node-1',
            'node_hostname' => 'manager-01',
            'service_labels' => ['com.docker.stack.namespace' => 'host-swarm'],
            'container_labels' => ['com.docker.swarm.service.name' => 'server-manager'],
            'source' => 'docker',
            'extra' => [
                'service_mode' => 'replicated',
                'task_state' => 'running',
                'desired_state' => 'running',
                'container_state' => 'running',
                'container_status' => 'running',
                'container_image' => 'server-manager:latest',
            ],
        ],
    ]);
});
