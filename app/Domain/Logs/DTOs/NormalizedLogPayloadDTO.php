<?php

declare(strict_types=1);

namespace App\Domain\Logs\DTOs;

use DateTimeImmutable;

final class NormalizedLogPayloadDTO
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public DateTimeImmutable $timestamp,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
