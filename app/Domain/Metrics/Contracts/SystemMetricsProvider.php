<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Contracts;

use App\Domain\Metrics\DTOs\SystemMetricsSnapshotDTO;

interface SystemMetricsProvider
{
    public function snapshot(): SystemMetricsSnapshotDTO;
}
