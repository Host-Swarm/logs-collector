<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Domain\Metrics\Contracts\SystemMetricsProvider;
use App\Domain\Metrics\DTOs\SystemMetricsSnapshotDTO;
use RuntimeException;

final class LinuxProcMetricsProvider implements SystemMetricsProvider
{
    public function snapshot(): SystemMetricsSnapshotDTO
    {
        return new SystemMetricsSnapshotDTO(
            cpu: $this->readCpuTotals(),
            memoryTotalBytes: $this->readMemInfoValue('MemTotal'),
            memoryAvailableBytes: $this->readMemInfoValue('MemAvailable'),
            diskTotalBytes: $this->readDiskTotal('/'),
            diskFreeBytes: $this->readDiskFree('/'),
            loadAverage: $this->readLoadAverage(),
            processCount: $this->readProcessCount(),
        );
    }

    /**
     * @return array<int, int>|null
     */
    private function readCpuTotals(): ?array
    {
        $contents = @file_get_contents('/proc/stat');

        if ($contents === false) {
            return null;
        }

        $lines = explode("\n", $contents);
        $cpuLine = $lines[0] ?? null;

        if (! is_string($cpuLine) || $cpuLine === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($cpuLine));

        if (! is_array($parts) || count($parts) < 5) {
            return null;
        }

        array_shift($parts);
        $values = array_values(array_map('intval', $parts));

        $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
        $total = array_sum($values);

        return [$total, $idle];
    }

    private function readMemInfoValue(string $key): ?int
    {
        $contents = @file_get_contents('/proc/meminfo');

        if ($contents === false) {
            return null;
        }

        foreach (explode("\n", $contents) as $line) {
            if (! str_starts_with($line, $key.':')) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line));

            if (! is_array($parts) || count($parts) < 2) {
                return null;
            }

            $valueKb = (int) $parts[1];

            return $valueKb * 1024;
        }

        return null;
    }

    private function readDiskTotal(string $path): ?int
    {
        $total = @disk_total_space($path);

        if ($total === false) {
            return null;
        }

        return (int) $total;
    }

    private function readDiskFree(string $path): ?int
    {
        $free = @disk_free_space($path);

        if ($free === false) {
            return null;
        }

        return (int) $free;
    }

    /**
     * @return array<int, float>|null
     */
    private function readLoadAverage(): ?array
    {
        $load = sys_getloadavg();

        if (! is_array($load)) {
            return null;
        }

        return array_map('floatval', $load);
    }

    private function readProcessCount(): ?int
    {
        try {
            $count = 0;
            foreach (new \DirectoryIterator('/proc') as $entry) {
                if ($entry->isDir() && ctype_digit($entry->getFilename())) {
                    $count += 1;
                }
            }

            return $count;
        } catch (RuntimeException) {
            return null;
        }
    }
}
