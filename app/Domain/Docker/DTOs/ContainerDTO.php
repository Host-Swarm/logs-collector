<?php

declare(strict_types=1);

namespace App\Domain\Docker\DTOs;

final class ContainerDTO
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $state,
        public ?string $nodeId,
        public ?string $nodeHostname,
        public ?string $taskId,
        public ?int $taskSlot,
        public ?string $image,
        public ?string $serviceId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'state' => $this->state,
            'node_id' => $this->nodeId,
            'node_hostname' => $this->nodeHostname,
            'task_id' => $this->taskId,
            'task_slot' => $this->taskSlot,
            'image' => $this->image,
            'service_id' => $this->serviceId,
        ];
    }
}
