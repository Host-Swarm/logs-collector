<?php

declare(strict_types=1);

namespace App\Domain\Docker\DTOs;

final class ServiceDTO
{
    /**
     * @param  array<int, ContainerDTO>  $containers
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $mode,
        public ?int $replicas,
        public ?string $image,
        public array $containers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mode' => $this->mode,
            'replicas' => $this->replicas,
            'image' => $this->image,
            'containers' => array_map(fn (ContainerDTO $c) => $c->toArray(), $this->containers),
        ];
    }
}
