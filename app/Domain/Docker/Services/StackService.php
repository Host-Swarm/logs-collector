<?php

declare(strict_types=1);

namespace App\Domain\Docker\Services;

use App\Domain\Docker\DTOs\ContainerDTO;
use App\Domain\Docker\DTOs\DiscoveredContainerDTO;
use App\Domain\Docker\DTOs\ServiceDTO;
use App\Domain\Docker\DTOs\StackDTO;
use Illuminate\Support\Facades\Cache;

class StackService
{
    private const int CACHE_TTL_SECONDS = 30;

    public function __construct(
        private SwarmDiscoveryService $discovery,
    ) {}

    /**
     * Returns all stacks as a summary list (name + service count).
     *
     * @return array<int, StackDTO>
     */
    public function listStacks(string $swarmKey): array
    {
        return Cache::remember(
            "stacks:discovery:{$swarmKey}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildStacks($this->discovery->discover($swarmKey)),
        );
    }

    /**
     * Returns a single stack with full service and container detail.
     * Returns null if the stack is not found.
     */
    public function findStack(string $swarmKey, string $stackName): ?StackDTO
    {
        $stacks = $this->listStacks($swarmKey);

        foreach ($stacks as $stack) {
            if ($stack->name === $stackName) {
                return $stack;
            }
        }

        return null;
    }

    /**
     * @param  array<int, DiscoveredContainerDTO>  $containers
     * @return array<int, StackDTO>
     */
    private function buildStacks(array $containers): array
    {
        /** @var array<string, array<string, DiscoveredContainerDTO[]>> $grouped */
        $grouped = [];

        foreach ($containers as $container) {
            $stackName = $container->stackName ?? '__no_stack__';
            $serviceId = $container->serviceId;

            if (! isset($grouped[$stackName])) {
                $grouped[$stackName] = [];
            }

            if (! isset($grouped[$stackName][$serviceId])) {
                $grouped[$stackName][$serviceId] = [];
            }

            $grouped[$stackName][$serviceId][] = $container;
        }

        $stacks = [];

        foreach ($grouped as $stackName => $serviceGroups) {
            $services = [];

            foreach ($serviceGroups as $serviceId => $serviceContainers) {
                $first = $serviceContainers[0];

                $containerDTOs = array_map(
                    fn (DiscoveredContainerDTO $c) => new ContainerDTO(
                        id: $c->containerId,
                        name: $c->containerName,
                        state: $c->containerState,
                        nodeId: $c->nodeId,
                        nodeHostname: $c->nodeHostname,
                        taskId: $c->taskId,
                        taskSlot: $c->taskSlot,
                        image: $c->containerImage,
                        serviceId: $c->serviceMode !== 'container' ? $c->serviceId : null,
                    ),
                    $serviceContainers,
                );

                $services[] = new ServiceDTO(
                    id: $serviceId,
                    name: $first->serviceName,
                    mode: $first->serviceMode,
                    replicas: count($serviceContainers),
                    image: $first->containerImage,
                    containers: $containerDTOs,
                );
            }

            $stacks[] = new StackDTO(
                name: $stackName === '__no_stack__' ? '(ungrouped)' : $stackName,
                services: $services,
            );
        }

        usort($stacks, fn (StackDTO $a, StackDTO $b) => strcmp($a->name, $b->name));

        return $stacks;
    }
}
