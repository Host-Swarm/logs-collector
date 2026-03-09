<?php

declare(strict_types=1);

namespace App\Domain\Logs\DTOs;

use DateTimeImmutable;

final class DiscoveredContainerDTO
{
    /**
     * @param  array<string, string>  $serviceLabels
     * @param  array<string, string>  $containerLabels
     */
    public function __construct(
        public string $swarmKey,
        public string $serviceId,
        public string $serviceName,
        public array $serviceLabels,
        public string $serviceMode,
        public ?string $taskId,
        public ?int $taskSlot,
        public ?string $desiredState,
        public ?string $taskState,
        public ?string $nodeId,
        public ?string $nodeHostname,
        public string $containerId,
        public ?string $containerName,
        public array $containerLabels,
        public ?string $containerState,
        public ?string $containerStatus,
        public ?string $containerImage,
        public bool $containerTty,
        public ?string $stackName,
        public DateTimeImmutable $discoveredAt,
    ) {}
}
