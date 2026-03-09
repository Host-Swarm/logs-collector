<?php

declare(strict_types=1);

namespace App\Domain\Logs\Contracts;

use App\Domain\Logs\DTOs\NormalizedLogPayloadDTO;

interface LogBroadcaster
{
    public function broadcast(NormalizedLogPayloadDTO $payload): void;
}
