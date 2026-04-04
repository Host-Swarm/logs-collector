<?php

declare(strict_types=1);

namespace App\Domain\Docker\DTOs;

final class StackDTO
{
    /**
     * @param  array<int, ServiceDTO>  $services
     */
    public function __construct(
        public string $name,
        public array $services = [],
    ) {}

    public function serviceCount(): int
    {
        return count($this->services);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'services' => array_map(fn (ServiceDTO $s) => $s->toArray(), $this->services),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'name' => $this->name,
            'services' => $this->serviceCount(),
        ];
    }
}
