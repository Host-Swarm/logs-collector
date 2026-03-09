<?php

declare(strict_types=1);

namespace App\Domain\Metrics\DTOs;

final class SystemMetricsSnapshotDTO
{
    /**
     * @param array<int, int>|null $cpu
     * @param array<int, float>|null $loadAverage
     */
    public function __construct(
        public ?array $cpu,
        public ?int $memoryTotalBytes,
        public ?int $memoryAvailableBytes,
        public ?int $diskTotalBytes,
        public ?int $diskFreeBytes,
        public ?array $loadAverage,
        public ?int $processCount,
    ) {
    }
}
