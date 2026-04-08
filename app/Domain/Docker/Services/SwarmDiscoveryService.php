<?php

declare(strict_types=1);

namespace App\Domain\Docker\Services;

use App\Domain\Docker\DTOs\DiscoveredContainerDTO;
use App\Infrastructure\Docker\DockerHttpClient;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

class SwarmDiscoveryService
{
    public function __construct(
        private DockerHttpClient $docker,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<int, DiscoveredContainerDTO>
     */
    public function discover(string $swarmKey): array
    {
        $containers = [];
        $discoveredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $services = $this->docker->getJson('/services');
        } catch (Throwable $exception) {
            $this->logger->warning('Docker services discovery failed.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        foreach ($services as $service) {
            $serviceId = $service['ID'] ?? null;
            $serviceName = $service['Spec']['Name'] ?? null;

            if (! is_string($serviceId) || ! is_string($serviceName)) {
                continue;
            }

            $serviceLabels = $service['Spec']['Labels'] ?? [];
            $serviceMode = $this->resolveServiceMode($service);
            $stackName = is_array($serviceLabels) ? ($serviceLabels['com.docker.stack.namespace'] ?? null) : null;

            $tasks = $this->resolveServiceTasks($serviceId);

            foreach ($tasks as $task) {
                $desiredState = $task['DesiredState'] ?? null;
                $currentState = $task['Status']['State'] ?? null;

                if ($desiredState !== 'running' || $currentState !== 'running') {
                    continue;
                }

                $containerId = $task['Status']['ContainerStatus']['ContainerID'] ?? null;

                if (! is_string($containerId) || $containerId === '') {
                    $this->logger->info('Task has no container mapping yet.', [
                        'service_id' => $serviceId,
                        'task_id' => $task['ID'] ?? null,
                    ]);

                    continue;
                }

                $container = $this->resolveContainer($containerId);

                if ($container === null) {
                    continue;
                }

                $containers[] = new DiscoveredContainerDTO(
                    swarmKey: $swarmKey,
                    serviceId: $serviceId,
                    serviceName: $serviceName,
                    serviceLabels: is_array($serviceLabels) ? $serviceLabels : [],
                    serviceMode: $serviceMode,
                    taskId: $task['ID'] ?? null,
                    taskSlot: $this->resolveTaskSlot($task),
                    desiredState: $task['DesiredState'] ?? null,
                    taskState: $task['Status']['State'] ?? null,
                    nodeId: $task['NodeID'] ?? null,
                    nodeHostname: $task['NodeName'] ?? null,
                    containerId: $containerId,
                    containerName: $container['Name'] ?? null,
                    containerLabels: $container['Config']['Labels'] ?? [],
                    containerState: $container['State']['Status'] ?? null,
                    containerStatus: $container['State']['Status'] ?? null,
                    containerImage: $container['Config']['Image'] ?? null,
                    containerTty: (bool) ($container['Config']['Tty'] ?? false),
                    stackName: is_string($stackName) ? $stackName : null,
                    discoveredAt: $discoveredAt,
                );
            }
        }

        $swarmContainerIds = array_column($containers, 'containerId');
        $this->discoverPlainContainers($containers, $swarmContainerIds, $swarmKey, $discoveredAt);

        $this->logger->info('Docker Swarm discovery finished.', [
            'services' => is_countable($services) ? count($services) : 0,
            'containers' => count($containers),
        ]);

        return $containers;
    }

    /**
     * Discovers plain (non-Swarm) running containers and appends them to $containers.
     *
     * @param  array<int, DiscoveredContainerDTO>  $containers
     * @param  array<int, string>  $excludeIds
     */
    private function discoverPlainContainers(array &$containers, array $excludeIds, string $swarmKey, \DateTimeImmutable $discoveredAt): void
    {
        try {
            $list = $this->docker->getJson('/containers/json', ['all' => '0']);
        } catch (Throwable $exception) {
            $this->logger->warning('Plain container discovery failed.', [
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        foreach ($list as $item) {
            $id = $item['Id'] ?? null;

            if (! is_string($id) || in_array($id, $excludeIds, true)) {
                continue;
            }

            $names = $item['Names'] ?? [];
            $name = is_array($names) && isset($names[0]) ? ltrim($names[0], '/') : $id;
            $image = $item['Image'] ?? null;
            $state = $item['State'] ?? null;
            $labels = is_array($item['Labels'] ?? null) ? $item['Labels'] : [];

            // Containers that belong to Swarm services are already discovered above.
            if (isset($labels['com.docker.swarm.service.id'])) {
                continue;
            }

            $stackName = $labels['com.docker.compose.project'] ?? null;

            $containers[] = new DiscoveredContainerDTO(
                swarmKey: $swarmKey,
                serviceId: $id,
                serviceName: $name,
                serviceLabels: [],
                serviceMode: 'container',
                taskId: null,
                taskSlot: null,
                desiredState: null,
                taskState: null,
                nodeId: null,
                nodeHostname: null,
                containerId: $id,
                containerName: $name,
                containerLabels: $labels,
                containerState: is_string($state) ? strtolower($state) : null,
                containerStatus: $item['Status'] ?? null,
                containerImage: is_string($image) ? $image : null,
                containerTty: false,
                stackName: is_string($stackName) ? $stackName : null,
                discoveredAt: $discoveredAt,
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveServiceTasks(string $serviceId): array
    {
        try {
            return $this->docker->getJson('/tasks', [
                'filters' => json_encode(['service' => [$serviceId]], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Docker task discovery failed.', [
                'service_id' => $serviceId,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveContainer(string $containerId): ?array
    {
        try {
            $container = $this->docker->getJson("/containers/{$containerId}/json");
        } catch (Throwable $exception) {
            $this->logger->warning('Docker container inspect failed.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (isset($container['Name']) && is_string($container['Name'])) {
            $container['Name'] = ltrim($container['Name'], '/');
        }

        return $container;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function resolveServiceMode(array $service): string
    {
        $mode = $service['Spec']['Mode'] ?? [];

        if (! is_array($mode) || $mode === []) {
            return 'unknown';
        }

        $keys = array_keys($mode);

        return $keys[0] ?? 'unknown';
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function resolveTaskSlot(array $task): ?int
    {
        $slot = $task['Slot'] ?? $task['Spec']['Slot'] ?? null;

        if (! is_int($slot)) {
            return null;
        }

        return $slot;
    }
}
