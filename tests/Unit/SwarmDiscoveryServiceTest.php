<?php

declare(strict_types=1);

use App\Domain\Logs\Services\SwarmDiscoveryService;
use App\Infrastructure\Docker\DockerHttpClient;
use Psr\Log\NullLogger;

it('discovers containers from services and tasks', function () {
    $containerId = 'container-123';
    $filters = json_encode(['service' => ['service-123']], JSON_THROW_ON_ERROR);

    $client = new FakeDockerHttpClient([
        '/services' => [
            [
                'ID' => 'service-123',
                'Spec' => [
                    'Name' => 'server-manager',
                    'Labels' => ['com.docker.stack.namespace' => 'host-swarm'],
                    'Mode' => ['Replicated' => ['Replicas' => 1]],
                ],
            ],
        ],
        '/tasks?filters='.urlencode($filters) => [
            [
                'ID' => 'task-456',
                'Slot' => 1,
                'NodeID' => 'node-789',
                'DesiredState' => 'running',
                'Status' => [
                    'State' => 'running',
                    'ContainerStatus' => [
                        'ContainerID' => $containerId,
                    ],
                ],
            ],
        ],
        "/containers/{$containerId}/json" => [
            'Name' => '/server-manager.1.abcd',
            'Config' => [
                'Labels' => ['com.docker.swarm.service.name' => 'server-manager'],
                'Image' => 'server-manager:latest',
                'Tty' => false,
            ],
            'State' => [
                'Status' => 'running',
            ],
        ],
    ]);

    $service = new SwarmDiscoveryService($client, new NullLogger);
    $containers = $service->discover('main-swarm');

    expect($containers)->toHaveCount(1);
    expect($containers[0]->serviceName)->toBe('server-manager');
    expect($containers[0]->stackName)->toBe('host-swarm');
    expect($containers[0]->containerId)->toBe($containerId);
});

it('skips tasks without container mappings', function () {
    $filters = json_encode(['service' => ['service-123']], JSON_THROW_ON_ERROR);

    $client = new FakeDockerHttpClient([
        '/services' => [
            [
                'ID' => 'service-123',
                'Spec' => [
                    'Name' => 'server-manager',
                    'Labels' => [],
                    'Mode' => ['Replicated' => ['Replicas' => 1]],
                ],
            ],
        ],
        '/tasks?filters='.urlencode($filters) => [
            [
                'ID' => 'task-456',
                'Slot' => 1,
                'DesiredState' => 'running',
                'Status' => [
                    'State' => 'running',
                    'ContainerStatus' => [],
                ],
            ],
        ],
    ]);

    $service = new SwarmDiscoveryService($client, new NullLogger);
    $containers = $service->discover('main-swarm');

    expect($containers)->toHaveCount(0);
});

final class FakeDockerHttpClient extends DockerHttpClient
{
    /**
     * @var array<string, array<int, array<string, mixed>>|
     *     array<string, mixed>>
     */
    private array $responses;

    /**
     * @param  array<string, array<int, array<string, mixed>>|array<string, mixed>>  $responses
     */
    public function __construct(array $responses)
    {
        parent::__construct('/var/run/docker.sock', 1, 1, 1);
        $this->responses = $responses;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $key = $path;

        if ($query !== []) {
            $key .= '?'.http_build_query($query);
        }

        return $this->responses[$key] ?? [];
    }
}
