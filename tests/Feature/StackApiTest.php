<?php

declare(strict_types=1);

use App\Domain\Docker\DTOs\DiscoveredContainerDTO;
use App\Domain\Docker\Services\SwarmDiscoveryService;

function fakeDiscovery(array $containers): SwarmDiscoveryService
{
    $mock = Mockery::mock(SwarmDiscoveryService::class);
    $mock->shouldReceive('discover')->andReturn($containers);

    app()->instance(SwarmDiscoveryService::class, $mock);

    return $mock;
}

function makeDiscoveredContainer(string $stack = 'my-app', string $serviceId = 'svc-1', string $serviceName = 'my-app_web', string $containerId = 'abc123def456'): DiscoveredContainerDTO
{
    return new DiscoveredContainerDTO(
        swarmKey: 'main-swarm',
        serviceId: $serviceId,
        serviceName: $serviceName,
        serviceLabels: ['com.docker.stack.namespace' => $stack],
        serviceMode: 'Replicated',
        taskId: 'task-1',
        taskSlot: 1,
        desiredState: 'running',
        taskState: 'running',
        nodeId: 'node-1',
        nodeHostname: 'worker-1',
        containerId: $containerId,
        containerName: "{$serviceName}.1.xyz",
        containerLabels: [],
        containerState: 'running',
        containerStatus: 'running',
        containerImage: 'nginx:latest',
        containerTty: false,
        stackName: $stack,
        discoveredAt: new \DateTimeImmutable,
    );
}

// ---------- GET /api/stacks ----------

it('returns 401 when server secret is missing', function (): void {
    $response = $this->getJson('/api/stacks');

    $response->assertStatus(401);
    $response->assertJson(['error' => 'Unauthorized.']);
});

it('returns 401 when server secret is wrong', function (): void {
    config(['logs_collector.server_secret' => 'correct-secret']);

    $response = $this->withHeaders(['Authorization' => 'Bearer wrong-secret'])
        ->getJson('/api/stacks');

    $response->assertStatus(401);
});

it('returns stacks list with correct server secret', function (): void {
    config(['logs_collector.server_secret' => 'my-secret']);
    fakeDiscovery([
        makeDiscoveredContainer('my-app', 'svc-1', 'my-app_web', 'abc123def456'),
        makeDiscoveredContainer('other-stack', 'svc-2', 'other_api', 'def456abc123'),
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer my-secret'])
        ->getJson('/api/stacks');

    $response->assertStatus(200);
    $response->assertJsonStructure(['stacks' => [['name', 'services']]]);

    $names = array_column($response->json('stacks'), 'name');
    expect($names)->toContain('my-app');
    expect($names)->toContain('other-stack');
});

it('returns empty stacks list when swarm has no services', function (): void {
    config(['logs_collector.server_secret' => 'my-secret']);
    fakeDiscovery([]);

    $response = $this->withHeaders(['Authorization' => 'Bearer my-secret'])
        ->getJson('/api/stacks');

    $response->assertStatus(200);
    $response->assertJson(['stacks' => []]);
});

// ---------- GET /api/stacks/{stack} ----------

it('returns stack detail with services and containers', function (): void {
    config(['logs_collector.server_secret' => 'my-secret']);
    fakeDiscovery([
        makeDiscoveredContainer('my-app', 'svc-1', 'my-app_web', 'abc123def456'),
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer my-secret'])
        ->getJson('/api/stacks/my-app');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'stack',
        'services' => [['id', 'name', 'mode', 'replicas', 'image', 'containers']],
    ]);
    $response->assertJsonFragment(['stack' => 'my-app']);
});

it('returns 404 when stack does not exist', function (): void {
    config(['logs_collector.server_secret' => 'my-secret']);
    fakeDiscovery([]);

    $response = $this->withHeaders(['Authorization' => 'Bearer my-secret'])
        ->getJson('/api/stacks/does-not-exist');

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Stack not found.']);
});

it('returns 401 on stack detail when secret is wrong', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Bearer bad'])
        ->getJson('/api/stacks/my-app');

    $response->assertStatus(401);
});
